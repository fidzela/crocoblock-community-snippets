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
use Jet_FB_Mercadopago_Gateway\FormEvents\RenewalPaymentEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Paypal\DbModels\SubscriptionToPaymentModel;
use Jet_FB_Paypal\QueryViews\PaymentsBySubscription;
use Jet_FB_Paypal\QueryViews\PaymentsWithSales;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Actions\Events\Gateway_Failed\Gateway_Failed_Event;
use Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event;
use Jet_Form_Builder\Gateways\Base_Gateway;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
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

		// Id da cobrança real (preferido) com fallback no id do authorized_payment.
		$transaction_id = (string) ( $payment['id'] ?? ( $ap['id'] ?? $data_id ) );

		// Idempotência: já registramos esta cobrança?
		if ( $this->already_processed( $transaction_id ) ) {
			return self::ok( 'already processed' );
		}

		$approved = ( 'approved' === $pay_status )
			|| ( '' === $pay_status && 'processed' === $ap_status );

		if ( ! $approved ) {
			SubscriptionUtils::execute_event_for_subscription( $row['id'], Gateway_Failed_Event::class );

			return self::ok( 'payment not approved (' . ( '' !== $pay_status ? $pay_status : $ap_status ) . ')' );
		}

		$is_renewal = $this->has_prior_payment( (int) $row['id'] );
		$type       = $is_renewal ? PaymentsWithSales::RENEW_TYPE : Base_Gateway::PAYMENT_TYPE_INITIAL;

		try {
			$payment_row_id = ( new Payment_Model() )->insert(
				array(
					'transaction_id' => $transaction_id,
					'form_id'        => $row['form_id'],
					'user_id'        => $row['user_id'],
					'gateway_id'     => 'mercadopago',
					'scenario'       => $row['scenario'],
					'amount_value'   => (float) ( $ap['transaction_amount'] ?? 0 ),
					'amount_code'    => (string) ( $ap['currency_id'] ?? 'BRL' ),
					'type'           => $type,
					'status'         => 'COMPLETED',
				)
			);

			( new SubscriptionToPaymentModel() )->insert(
				array(
					'subscription_id' => $row['id'],
					'payment_id'      => $payment_row_id,
				)
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log(
				'Subscription payment persist failed.',
				array( 'data_id' => $data_id, 'error' => $e->getMessage() )
			);

			// Erro local de gravação -> pedir reentrega.
			return new WP_REST_Response( array( 'message' => 'persist failed (retry)' ), 500 );
		}

		$event = $is_renewal ? RenewalPaymentEvent::class : Gateway_Success_Event::class;
		SubscriptionUtils::execute_event_for_subscription( $row['id'], $event );

		return self::ok( 'completed (' . $type . ')' );
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
	 * A assinatura já tem alguma cobrança? (decide initial vs renew).
	 *
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
