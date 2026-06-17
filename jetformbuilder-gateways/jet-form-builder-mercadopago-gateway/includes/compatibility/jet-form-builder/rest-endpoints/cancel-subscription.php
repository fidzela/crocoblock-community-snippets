<?php
/**
 * ============================================================================
 *  Cancel_Subscription  —  Cancela uma assinatura MP (admin)
 * ============================================================================
 *
 *  COMO ISSO LIGA NO ADMIN (mecanismo Shared, gateway-aware):
 *  ---------------------------------------------------------------------------
 *  O botão "Cancel" da tabela de assinaturas monta a URL via
 *  `PayPalCancelSubscription::dynamic_rest_url( [ 'gateway' => <gateway_id>,
 *  'id' => <sub id> ] )`. O `Gateway_Endpoint` compõe a rota como
 *  `(?P<gateway>...)/subscription/cancel/(?P<id>)`, então para uma assinatura do
 *  Mercado Pago a URL resolve em `mercadopago/subscription/cancel/{id}` —
 *  exatamente a rota deste endpoint. (No addon Stripe é a mesma mecânica, só que
 *  com `stripe/...`.) Por isso o gateway-específico É chamado pelo admin.
 *
 *  API: PUT /preapproval/{billing_id} { status: 'cancelled' } (espelha o
 *  DELETE /v1/subscriptions/{id} do Stripe).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Update_Preapproval;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Controller;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionCancelEvent;
use Jet_FB_Paypal\Logic\SubscribeNow;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cancel_Subscription extends Rest_Api_Endpoint_Base {

	public static function get_rest_base() {
		return 'mercadopago/subscription/cancel/(?P<id>[\d]+)';
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
			return new WP_REST_Response( array( 'error' => 'empty_subscription_id' ), 400 );
		}

		$query = SubscriptionsView::find( array( 'id' => $id ) )->query();
		$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

		if ( empty( $rows ) ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_found' ), 404 );
		}

		$subscription = $query->view()->get_prepared_row( $rows[0] );

		$gateway = strtolower( (string) ( $subscription['gateway_id'] ?? '' ) );

		if ( 'mercadopago' !== $gateway ) {
			return new WP_REST_Response( array( 'error' => 'not_a_mercadopago_subscription' ), 400 );
		}

		$form_id    = (int) ( $subscription['form_id'] ?? 0 );
		$billing_id = (string) ( $subscription['billing_id'] ?? '' );

		if ( ! $form_id || '' === $billing_id ) {
			return new WP_REST_Response( array( 'error' => 'missing_form_or_billing_id' ), 400 );
		}

		$creds = Controller::get_credentials_by_form( $form_id );
		$token = (string) ( $creds['secret'] ?? '' );

		if ( '' === $token ) {
			return new WP_REST_Response( array( 'error' => 'access_token_not_found' ), 500 );
		}

		$resp = ( new Update_Preapproval() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $billing_id ) )
			->set_status( 'cancelled' )
			->send_request();

		if ( isset( $resp['error'] ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'mercadopago_api_error',
					'message' => $resp['error']['message'] ?? 'unknown',
				),
				503
			);
		}

		// Status local imediato + evento do form (admin-initiated). O webhook
		// `subscription_preapproval` (status=cancelled) tem guard de transição,
		// então NÃO redispara (evita duplicidade nas reentregas do MP).
		$resource = new Subscription( $subscription );
		$resource->update_status_soft( SubscribeNow::CANCELLED );

		SubscriptionUtils::execute_event_for_subscription( $subscription['id'], SubscriptionCancelEvent::class );

		do_action( 'jet-form-builder/subscription/change-status-manual', $resource );

		return new WP_REST_Response(
			array(
				'message'         => __( 'Successfully cancelled subscription!', 'jet-form-builder-mercadopago-gateway' ),
				'subscription_id' => $id,
			),
			200
		);
	}
}
