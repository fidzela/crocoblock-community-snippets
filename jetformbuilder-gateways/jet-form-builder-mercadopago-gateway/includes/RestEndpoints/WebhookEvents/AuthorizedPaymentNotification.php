<?php
/**
 * ============================================================================
 *  AuthorizedPaymentNotification  —  Tópico `subscription_authorized_payment`
 * ============================================================================
 *
 *  As COBRANÇAS recorrentes geradas por uma assinatura. Espelha os handlers do
 *  Stripe invoice.paid + invoice.payment_failed.
 *
 *  data.id = id do authorized_payment. SEMPRE consultamos
 *  GET /authorized_payments/{id} (fonte de verdade), que traz `preapproval_id`,
 *  `transaction_amount`, `currency_id` e o objeto `payment { id, status }`.
 *
 *  - 1ª cobrança  -> Payment_Model `initial` (COMPLETED) + Gateway_Success_Event
 *  - renovações   -> Payment_Model `renew`   (COMPLETED) + RenewalPaymentEvent
 *  - recusada     -> Gateway_Failed_Event
 *
 *  Os Payment_Model entram no CORE (aparecem em JetFormBuilder -> Payments e em
 *  todas as queries/relations), exatamente como no pay-now. Os eventos rodam as
 *  ações do form fora da submissão via execute_event_for_subscription
 *  (re-hidrata o Form Record ligado à assinatura).
 *
 *  IDEMPOTÊNCIA: se já existe um Payment_Model com o mesmo transaction_id, vira
 *  no-op (o MP reentrega webhooks).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Authorized_Payment;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Actions\Events\Gateway_Failed\Gateway_Failed_Event;
use Jet_Form_Builder\Gateways\Query_Views\Payment_With_Record_View;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuthorizedPaymentNotification {

	/**
	 * @param string $data_id Id do authorized_payment (data.id do webhook).
	 *
	 * @return WP_REST_Response
	 */
	public function handle( string $data_id ): WP_REST_Response {
		if ( '' === $data_id ) {
			return self::ok( 'no data.id' );
		}

		$token = WebhookConfig::access_token();

		if ( '' === $token ) {
			WebhookConfig::log( 'No access token; cannot verify authorized_payment.', array( 'data_id' => $data_id ) );

			return self::ok( 'no access token' );
		}

		$ap = ( new Retrieve_Authorized_Payment() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $data_id ) )
			->send_request();

		if ( isset( $ap['error'] ) ) {
			$code         = $ap['error']['code'] ?? 0;
			$is_transient = ( 'http_error' === $code ) || ( is_numeric( $code ) && (int) $code >= 500 );

			WebhookConfig::log( 'authorized_payment lookup failed.', array( 'data_id' => $data_id, 'code' => $code ) );

			return $is_transient
				? new WP_REST_Response( array( 'message' => 'lookup failed (retry)' ), 500 )
				: self::ok( 'authorized_payment not found' );
		}

		$preapproval_id = (string) ( $ap['preapproval_id'] ?? '' );
		$row            = $this->find_subscription( $preapproval_id );

		if ( null === $row ) {
			return self::ok( 'no matching subscription' );
		}

		$payment    = (array) ( $ap['payment'] ?? array() );
		$pay_status = (string) ( $payment['status'] ?? '' );
		$ap_status  = (string) ( $ap['status'] ?? '' );

		// CORRELAÇÃO CORRETA (docs MP, confirmado): a fatura (authorized_payment) e o
		// pagamento são recursos DIFERENTES -> authorized_payment.id != payment.id. O
		// vínculo é `authorized_payment.payment_id == payment.id`. O transaction_id
		// DEVE ser o id do PAGAMENTO REAL — é o que converge com o tópico `payment`
		// para a dedup. JAMAIS usar ap.id/data.id (= id da FATURA): a MESMA cobrança
		// entraria 2x (uma por tópico) com ids diferentes.
		$transaction_id = (string) ( $payment['id'] ?? ( $ap['payment_id'] ?? '' ) );

		// Sem id do pagamento real, não há chave de dedup confiável: não registramos
		// (fatura ainda sem payment gerado — scheduled/recycling). O tópico `payment`
		// (ou a próxima reentrega/recorrência) trará o pagamento.
		if ( '' === $transaction_id ) {
			return self::ok( 'no real payment id yet' );
		}

		// Idempotência: já registramos esta cobrança? (converge com o tópico `payment`)
		if ( $this->already_processed( $transaction_id ) ) {
			return self::ok( 'already processed' );
		}

		$approved = ( 'approved' === $pay_status )
			|| ( '' === $pay_status && 'processed' === $ap_status );

		if ( ! $approved ) {
			SubscriptionUtils::execute_event_for_subscription( $row['id'], Gateway_Failed_Event::class );

			return self::ok( 'payment not approved (' . ( '' !== $pay_status ? $pay_status : $ap_status ) . ')' );
		}

		// PONTO ÚNICO: ativa a assinatura + grava o Payment_Model (initial/renew) +
		// vincula o pagador + dispara o evento. Mesmo recorder do tópico `payment`,
		// então os dois caminhos são idênticos e idempotentes (transaction_id).
		try {
			$result = ( new SubscriptionPaymentRecorder() )->record(
				$row,
				$transaction_id,
				(float) ( $ap['transaction_amount'] ?? 0 ),
				(string) ( $ap['currency_id'] ?? 'BRL' ),
				is_array( $payment['payer'] ?? null ) ? $payment['payer'] : array()
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log(
				'Subscription payment persist failed.',
				array( 'data_id' => $data_id, 'error' => $e->getMessage() )
			);

			// Erro local de gravação -> pedir reentrega.
			return new WP_REST_Response( array( 'message' => 'persist failed (retry)' ), 500 );
		}

		return self::ok( 'subscription ' . $result );
	}

	/**
	 * Já existe um Payment_Model com este transaction_id?
	 *
	 * @param string $transaction_id
	 *
	 * @return bool
	 */
	private function already_processed( string $transaction_id ): bool {
		if ( '' === $transaction_id ) {
			return false;
		}

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
	 * Localiza a assinatura local pelo billing_id (= id da preapproval).
	 *
	 * @param string $billing_id
	 *
	 * @return array|null
	 */
	private function find_subscription( string $billing_id ) {
		if ( '' === $billing_id ) {
			return null;
		}

		try {
			$query = SubscriptionsView::find( array( 'billing_id' => $billing_id ) )->query();
			$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

			if ( empty( $rows ) ) {
				return null;
			}

			return $query->view()->get_prepared_row( $rows[0] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * @param string $message
	 *
	 * @return WP_REST_Response
	 */
	private static function ok( string $message ): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => $message ), 200 );
	}
}
