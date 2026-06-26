<?php
/**
 * ============================================================================
 *  SubscriptionPaymentRecorder  —  cobrança de assinatura -> CORE + eventos
 * ============================================================================
 *
 *  Ponto único que: (1) ATIVA a assinatura se ainda estiver pendente — uma
 *  cobrança APROVADA prova que a assinatura está ativa; (2) grava o Payment_Model
 *  da cobrança no CORE (aparece em JetFormBuilder → Payments e nas relations);
 *  (3) liga o pagamento à assinatura; (4) dispara o evento do form.
 *
 *  POR QUE existe (descoberta em teste real):
 *  ---------------------------------------------------------------------------
 *  O Mercado Pago entrega a cobrança de uma assinatura de DOIS jeitos, conforme
 *  a configuração: como tópico `payment` (vai para PaymentNotification) E/OU como
 *  `subscription_authorized_payment` (AuthorizedPaymentNotification). No painel do
 *  dono só chegava `payment`. Os dois caminhos chamam ESTE recorder, então a
 *  assinatura ativa e registra a cobrança independentemente do tópico usado.
 *
 *  IDEMPOTÊNCIA: por transaction_id (= id do pagamento no MP). Reentregas do MP
 *  e a chegada pelos dois tópicos para a MESMA cobrança não duplicam.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\FormEvents\RenewalPaymentEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Paypal\DbModels\SubscriptionToPaymentModel;
use Jet_FB_Paypal\DbModels\SubscriptionToPayerShipping;
use Jet_FB_Paypal\Logic\SubscribeNow;
use Jet_FB_Paypal\QueryViews\PaymentsBySubscription;
use Jet_FB_Paypal\QueryViews\PaymentsWithSales;
use Jet_FB_Paypal\QueryViews\SubscriptionPayerShipping;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event;
use Jet_Form_Builder\Db_Queries\Execution_Builder;
use Jet_Form_Builder\Gateways\Base_Gateway;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Shipping_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_To_Payer_Shipping_Model;
use Jet_Form_Builder\Gateways\Query_Views\Payment_With_Record_View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriptionPaymentRecorder {

	/**
	 * @param array  $subscription   Linha preparada da assinatura (SubscriptionsView).
	 * @param string $transaction_id Id do pagamento no MP (idempotência).
	 * @param float  $amount         Valor cobrado.
	 * @param string $currency       Moeda (ex.: BRL).
	 * @param array  $payer          Dados do pagador do payment (id/email/nome). Opcional.
	 *
	 * @return string Mensagem de status (para o log/resposta do webhook).
	 */
	public function record( array $subscription, string $transaction_id, float $amount, string $currency, array $payer = array() ): string {
		if ( '' === $transaction_id ) {
			return 'no transaction id';
		}

		// A cobrança aprovada já prova que a assinatura está ativa — garante ACTIVE
		// mesmo que o tópico `subscription_preapproval` (status) não tenha chegado.
		$this->maybe_activate( $subscription );

		// Idempotência: esta cobrança já virou Payment_Model? (reentrega / 2 tópicos)
		if ( $this->already_processed( $transaction_id ) ) {
			return 'already processed';
		}

		$is_renewal = $this->has_prior_payment( (int) $subscription['id'] );
		$type       = $is_renewal ? PaymentsWithSales::RENEW_TYPE : Base_Gateway::PAYMENT_TYPE_INITIAL;

		$payment_row_id = ( new Payment_Model() )->insert(
			array(
				'transaction_id' => $transaction_id,
				'form_id'        => $subscription['form_id'],
				'user_id'        => $subscription['user_id'],
				'gateway_id'     => 'mercadopago',
				'scenario'       => $subscription['scenario'],
				'amount_value'   => $amount,
				'amount_code'    => '' !== $currency ? $currency : 'BRL',
				'type'           => $type,
				'status'         => 'COMPLETED',
			)
		);

		( new SubscriptionToPaymentModel() )->insert(
			array(
				'subscription_id' => $subscription['id'],
				'payment_id'      => $payment_row_id,
			)
		);

		// Vincula o PAGADOR à assinatura na 1ª cobrança (como o Stripe faz no
		// checkout.session.completed) -> tira o "Subscriber: Not attached". Como o
		// `already_processed` acima garante 1x por transaction_id, e initial só
		// ocorre uma vez, não duplica em renovações/reentregas.
		if ( ! $is_renewal ) {
			$this->attach_payer( $subscription, $payer );
		}

		// Vincula ESTA cobrança ao pagador (coluna "Payer" da tabela Payments + o
		// e-mail no popup de refund de Payment Details). O Stripe faz igual no
		// InvoicePaid: pega o payer_shipping da assinatura e liga ao payment.
		$this->link_payment_to_payer( (int) $subscription['id'], $payment_row_id );

		$event = $is_renewal ? RenewalPaymentEvent::class : Gateway_Success_Event::class;
		SubscriptionUtils::execute_event_for_subscription( $subscription['id'], $event );

		return 'completed (' . $type . ')';
	}

	/**
	 * Liga o Payment_Model ao payer_shipping da assinatura (Payment_To_Payer_Shipping)
	 * — é o que a coluna "Payer" da tabela de Payments resolve. Roda em TODA cobrança
	 * (initial e renovação). Best-effort.
	 *
	 * @param int $subscription_id
	 * @param int $payment_row_id
	 *
	 * @return void
	 */
	private function link_payment_to_payer( int $subscription_id, int $payment_row_id ) {
		if ( ! $subscription_id || ! $payment_row_id ) {
			return;
		}

		try {
			$pair = SubscriptionPayerShipping::findOne(
				array( 'subscription_id' => $subscription_id )
			)->query()->query_one();

			if ( empty( $pair['payer_shipping_id'] ) ) {
				return;
			}

			( new Payment_To_Payer_Shipping_Model() )->insert(
				array(
					'payment_id'        => $payment_row_id,
					'payer_shipping_id' => $pair['payer_shipping_id'],
				)
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Payment->payer link failed.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Cria Payer + Payer_Shipping + SubscriptionToPayerShipping (a cadeia que a
	 * coluna "Subscriber" da tabela de assinaturas resolve). Best-effort e atômico:
	 * se falhar, faz rollback e segue — a cobrança já foi registrada.
	 *
	 * @param array $subscription
	 * @param array $payer  Objeto `payer` do payment do MP (id/email/first_name/last_name).
	 *
	 * @return void
	 */
	private function attach_payer( array $subscription, array $payer ) {
		if ( empty( $payer['email'] ) ) {
			return;
		}

		$first = (string) ( $payer['first_name'] ?? '' );
		$last  = (string) ( $payer['last_name'] ?? '' );

		try {
			Execution_Builder::instance()->transaction_start();

			$payer_id = Payer_Model::insert_or_update(
				array(
					'user_id'    => $subscription['user_id'] ?? 0,
					'payer_id'   => (string) ( $payer['id'] ?? '' ),
					'first_name' => $first,
					'last_name'  => $last,
					'email'      => (string) $payer['email'],
				)
			);

			$payer_ship_id = ( new Payer_Shipping_Model() )->insert(
				array(
					'payer_id'  => $payer_id,
					'full_name' => trim( $first . ' ' . $last ),
				)
			);

			( new SubscriptionToPayerShipping() )->insert(
				array(
					'subscription_id'   => $subscription['id'],
					'payer_shipping_id' => $payer_ship_id,
				)
			);

			Execution_Builder::instance()->transaction_commit();
		} catch ( \Throwable $e ) {
			Execution_Builder::instance()->transaction_rollback();
			WebhookConfig::log( 'Subscriber attach failed.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Ativa a assinatura se ainda não estiver ACTIVE (best-effort).
	 *
	 * @param array $subscription
	 *
	 * @return void
	 */
	private function maybe_activate( array $subscription ) {
		if ( SubscribeNow::ACTIVE === (string) ( $subscription['status'] ?? '' ) ) {
			return;
		}

		try {
			( new Subscription( $subscription ) )->set_active();
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Subscription activate (from payment) failed.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * @param string $transaction_id
	 *
	 * @return bool
	 */
	private function already_processed( string $transaction_id ): bool {
		try {
			$row = Payment_With_Record_View::findOne(
				array( 'transaction_id' => $transaction_id )
			)->query()->query_one();

			return ! empty( $row );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * @param int $subscription_id
	 *
	 * @return bool
	 */
	private function has_prior_payment( int $subscription_id ): bool {
		try {
			$query = PaymentsBySubscription::find( array( 'subscription_id' => $subscription_id ) )->query();
			$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

			return ! empty( $rows );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
