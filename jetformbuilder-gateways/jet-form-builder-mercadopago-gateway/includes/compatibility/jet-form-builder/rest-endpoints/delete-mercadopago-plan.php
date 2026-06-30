<?php
/**
 * ============================================================================
 *  Delete_Mercadopago_Plan  —  Cancela (desativa) um plano via API (admin)
 * ============================================================================
 *
 *  O Mercado Pago NÃO apaga um preapproval_plan; ele é DESATIVADO com
 *  PUT /preapproval_plan/{id} { "status": "cancelled" }. Planos cancelados
 *  deixam de aceitar novas assinaturas e somem do dropdown (filtramos no fetch).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Delete_Mercadopago_Plan extends Rest_Api_Endpoint_Base {

	use Mp_Token_Trait;

	public static function get_rest_base() {
		return 'delete-mercadopago-plan';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		$p = (array) ( $request->get_json_params() ?: array() );

		// Token SEMPRE do gateway (server-side); o cliente nunca o envia.
		$secret = $this->gateway_token();
		$id     = trim( (string) ( $p['id'] ?? '' ) );

		if ( '' === $secret ) {
			return new WP_Error( 'mp_no_token', __( 'Access Token não configurado no gateway.', 'jet-form-builder-mercadopago-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $id ) {
			return new WP_Error( 'mp_no_id', __( 'ID do plano vazio.', 'jet-form-builder-mercadopago-gateway' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_request(
			'https://api.mercadopago.com/preapproval_plan/' . rawurlencode( $id ),
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( array( 'status' => 'cancelled' ) ),
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mp_connection', 'Falha de conexão com o Mercado Pago: ' . $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';

			return new WP_Error(
				'mp_http_error',
				sprintf(
					/* translators: 1: HTTP code, 2: MP message */
					__( 'O Mercado Pago recusou cancelar o plano (HTTP %1$d). %2$s', 'jet-form-builder-mercadopago-gateway' ),
					$code,
					$msg
				),
				array( 'status' => 400 )
			);
		}

		delete_transient( 'jet_fb_mercadopago_plans_' . md5( $secret ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => (string) ( $data['status'] ?? 'cancelled' ),
			)
		);
	}
}
