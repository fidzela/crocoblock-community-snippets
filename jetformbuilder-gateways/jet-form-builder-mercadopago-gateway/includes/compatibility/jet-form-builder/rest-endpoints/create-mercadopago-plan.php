<?php
/**
 * ============================================================================
 *  Create_Mercadopago_Plan  —  Cria um plano de assinatura via API (admin)
 * ============================================================================
 *
 *  POST /preapproval_plan. Alimenta a página admin "MP Planos" (criar plano sem
 *  terminal). Os planos do PAINEL do MP não aparecem na API de Assinaturas; só
 *  os criados por aqui (preapproval_plan) entram no dropdown do cenário de
 *  assinatura (GET /preapproval_plan/search).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create_Mercadopago_Plan extends Rest_Api_Endpoint_Base {

	const ENDPOINT = 'https://api.mercadopago.com/preapproval_plan';

	public static function get_rest_base() {
		return 'create-mercadopago-plan';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		$p = (array) ( $request->get_json_params() ?: array() );

		$secret = trim( (string) ( $p['secret'] ?? '' ) );
		$reason = trim( (string) ( $p['reason'] ?? '' ) );
		$amount = round( (float) ( $p['amount'] ?? 0 ), 2 );

		$frequency      = max( 1, (int) ( $p['frequency'] ?? 1 ) );
		$frequency_type = in_array( $p['frequency_type'] ?? '', array( 'months', 'days' ), true )
			? $p['frequency_type']
			: 'months';
		$currency = strtoupper( trim( (string) ( $p['currency'] ?? 'BRL' ) ) ) ?: 'BRL';
		$back_url = esc_url_raw( (string) ( $p['back_url'] ?? '' ) ) ?: home_url( '/' );

		if ( '' === $secret ) {
			return new WP_Error( 'mp_no_token', __( 'Access Token vazio.', 'jet-form-builder-mercadopago-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $reason ) {
			return new WP_Error( 'mp_no_reason', __( 'Informe o nome/descrição do plano.', 'jet-form-builder-mercadopago-gateway' ), array( 'status' => 400 ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'mp_no_amount', __( 'Informe um valor maior que zero.', 'jet-form-builder-mercadopago-gateway' ), array( 'status' => 400 ) );
		}

		$body = array(
			'reason'         => $reason,
			'auto_recurring' => array(
				'frequency'          => $frequency,
				'frequency_type'     => $frequency_type,
				'transaction_amount' => $amount,
				'currency_id'        => $currency,
			),
			'back_url'       => $back_url,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
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
					__( 'O Mercado Pago recusou a criação do plano (HTTP %1$d). %2$s', 'jet-form-builder-mercadopago-gateway' ),
					$code,
					$msg
				),
				array( 'status' => 400 )
			);
		}

		// Invalida o cache do dropdown para o novo plano aparecer.
		delete_transient( 'jet_fb_mercadopago_plans_' . md5( $secret ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'plan'    => array(
					'id'         => (string) ( $data['id'] ?? '' ),
					'reason'     => (string) ( $data['reason'] ?? $reason ),
					'init_point' => (string) ( $data['init_point'] ?? '' ),
				),
			)
		);
	}
}
