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
use Jet_FB_Paypal\Logic\SubscribeNow;
use Jet_FB_Paypal\QueryViews\PaymentsBySubscription;
use Jet_FB_Paypal\QueryViews\PaymentsWithSales;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event;
use Jet_Form_Builder\Gateways\Base_Gateway;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
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
	 *
	 * @return string Mensagem de status (para o log/resposta do webhook).
	 */
	public function record( array $subscription, string $transaction_id, float $amount, string $currency ): string {
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

		$event = $is_renewal ? RenewalPaymentEvent::class : Gateway_Success_Event::class;
		SubscriptionUtils::execute_event_for_subscription( $subscription['id'], $event );

		return 'completed (' . $type . ')';
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
