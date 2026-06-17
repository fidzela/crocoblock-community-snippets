<?php
/**
 * ============================================================================
 *  Refund_Payment  —  Estorno de pagamento MP (admin)
 * ============================================================================
 *
 *  Ligado ao botão "Refund" do admin pela mesma mecânica gateway-aware do
 *  cancel/suspend: a URL resolve em `mercadopago/payment/refund/{id}` (id INTERNO
 *  do Payment_Model). API: `POST /v1/payments/{payment_id}/refunds`.
 *
 *  RESOLUÇÃO DO payment_id DO MP (ver REFUND-ARCHITECTURE.md):
 *   - Assinatura: `transaction_id` já é o payment_id (numérico) -> usa direto.
 *   - Pay-now:    `transaction_id` é o id da PREFERENCE (tem hífen) -> busca o
 *                 pagamento por external_reference (initial_transaction_id).
 *
 *  SEGURANÇA/LÓGICA:
 *   - Idempotência: X-Idempotency-Key por payment_id (anti-duplo-estorno).
 *   - Guard de status: só estorna `COMPLETED`; transição -> `REFUNDED` é um
 *     UPDATE condicional (atômico). Já-REFUNDED vira no-op.
 *   - Fonte de verdade: o webhook `payment` (status refunded) reconcilia o DB
 *     (ver PaymentNotification), inclusive estornos feitos no painel do MP.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Refund_Payment_Action;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Search_Payments;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Controller;
use Jet_FB_Paypal\QueryViews\PaymentsWithSales;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_Form_Builder\Db_Queries\Exceptions\Sql_Exception;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Refund_Payment extends Rest_Api_Endpoint_Base {

	public static function get_rest_base() {
		return 'mercadopago/payment/refund/(?P<id>[\d]+)';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $id ) {
			return new WP_REST_Response( array( 'error' => 'empty_payment_id' ), 400 );
		}

		$query = PaymentsWithSales::find( array( 'id' => $id ) )->query();
		$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

		if ( empty( $rows ) ) {
			return new WP_REST_Response( array( 'error' => 'payment_not_found' ), 404 );
		}

		$payment = $query->view()->get_prepared_row( $rows[0] );

		$gateway = strtolower( (string) ( $payment['gateway_id'] ?? '' ) );

		if ( 'mercadopago' !== $gateway ) {
			return new WP_REST_Response( array( 'error' => 'not_a_mercadopago_payment' ), 400 );
		}

		// Idempotência de entrada: só estorna pagamento efetivamente COMPLETED.
		if ( 'COMPLETED' !== (string) ( $payment['status'] ?? '' ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Payment is not refundable (status: ' . ( $payment['status'] ?? 'unknown' ) . ')' ),
				200
			);
		}

		$form_id = (int) ( $payment['form_id'] ?? 0 );
		$creds   = Controller::get_credentials_by_form( $form_id );
		$token   = (string) ( $creds['secret'] ?? '' );

		if ( '' === $token ) {
			return new WP_REST_Response( array( 'error' => 'access_token_not_found' ), 500 );
		}

		$mp_payment_id = $this->resolve_mp_payment_id( $payment, $token );

		if ( '' === $mp_payment_id ) {
			return new WP_REST_Response( array( 'error' => 'mp_payment_id_not_resolved' ), 400 );
		}

		// Estorno parcial opcional (validado <= valor pago). Default: total.
		$in     = $request->get_json_params() ?: array();
		$amount = $this->resolve_amount( $in, $payment );

		$action = ( new Refund_Payment_Action() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $mp_payment_id ) )
			->set_amount( $amount )
			->set_idempotency_key( 'jfbmp-refund-' . $mp_payment_id . ( $amount ? '-' . $amount : '' ) );

		$resp = $action->send_request();

		if ( isset( $resp['error'] ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'mercadopago_api_error',
					'message' => $resp['error']['message'] ?? 'unknown',
				),
				503
			);
		}

		// Transição atômica COMPLETED -> REFUNDED (o webhook é a rede de segurança).
		try {
			( new Payment_Model() )->update(
				array( 'status' => PaymentsWithSales::REFUNDED_STATUS ),
				array(
					'id'     => $payment['id'],
					'status' => 'COMPLETED',
				)
			);
		} catch ( Sql_Exception $exception ) {
			// Já marcado (corrida com o webhook). Segue: o estorno no MP foi OK.
		}

		// Se o pagamento é de assinatura, marca a assinatura como REFUNDED.
		( new Subscription( $payment['subscription'] ?? array() ) )->set_refunded();

		return new WP_REST_Response(
			array(
				'message'    => __( 'Successfully refunded payment!', 'jet-form-builder-mercadopago-gateway' ),
				'payment_id' => $id,
			),
			200
		);
	}

	/**
	 * Resolve o id do pagamento no MP. Numérico (assinatura) -> direto; com hífen
	 * (preference do pay-now) -> busca por external_reference.
	 *
	 * @param array  $payment
	 * @param string $token
	 *
	 * @return string
	 */
	private function resolve_mp_payment_id( array $payment, string $token ): string {
		$transaction_id = (string) ( $payment['transaction_id'] ?? '' );

		if ( ctype_digit( $transaction_id ) ) {
			return $transaction_id;
		}

		$external_reference = (string) ( $payment['initial_transaction_id'] ?? '' );

		if ( '' === $external_reference ) {
			return '';
		}

		return ( new Search_Payments() )
			->set_bearer_auth( $token )
			->set_external_reference( $external_reference )
			->find_approved_payment_id();
	}

	/**
	 * Valor do estorno parcial (se enviado e válido) ou null (total).
	 *
	 * @param array $in
	 * @param array $payment
	 *
	 * @return float|null
	 */
	private function resolve_amount( array $in, array $payment ) {
		if ( ! isset( $in['amount'] ) ) {
			return null;
		}

		$amount = round( (float) $in['amount'], 2 );
		$paid   = round( (float) ( $payment['amount_value'] ?? 0 ), 2 );

		// <= 0 ou >= total -> trata como estorno total.
		if ( $amount <= 0 || ( $paid > 0 && $amount >= $paid ) ) {
			return null;
		}

		return $amount;
	}
}
