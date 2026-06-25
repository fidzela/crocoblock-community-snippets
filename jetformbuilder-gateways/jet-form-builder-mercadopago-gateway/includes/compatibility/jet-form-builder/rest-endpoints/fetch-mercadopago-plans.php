<?php
/**
 * ============================================================================
 *  Fetch_Mercadopago_Plans  —  Lista os planos (preapproval_plan) p/ o editor
 * ============================================================================
 *
 *  Alimenta o dropdown "Subscription Plan" do cenário de assinatura. O botão
 *  "Refresh Plans From Mercadopago" (assets/js/mercadopago.js) faz POST aqui com
 *  { public, secret, force_refresh }.
 *
 *  POR QUE FOI REESCRITO (corrige "Request failed" opaco):
 *  ---------------------------------------------------------------------------
 *  A versão anterior LANÇAVA exceção (token vazio / erro de conexão) e ENGOLIA o
 *  status HTTP do MP (se o MP respondia 401/400, devolvia lista vazia sem avisar).
 *  O JS então só mostrava "Request failed", sem causa. Agora:
 *   - nunca dá fatal;
 *   - em falha, devolve um WP_Error com MENSAGEM (o JS mostra em vermelho):
 *       token vazio · HTTP {code} do MP · falha de conexão;
 *   - em sucesso, devolve { success, data:[ {id,key,value,label,disabled} ] }.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fetch_Mercadopago_Plans extends Rest_Api_Endpoint_Base {

	const ENDPOINT = 'https://api.mercadopago.com/preapproval_plan/search?limit=100';

	public static function get_rest_base() {
		return 'fetch-mercadopago-plans';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		$secret = trim( (string) $request->get_param( 'secret' ) );
		$force  = filter_var( $request->get_param( 'force_refresh' ), FILTER_VALIDATE_BOOLEAN );

		if ( '' === $secret ) {
			return new WP_Error(
				'mp_no_token',
				__( 'Access Token vazio. Confira as credenciais do gateway — se o token está só no formulário, desligue "Use Global Settings"; se está no global, ligue.', 'jet-form-builder-mercadopago-gateway' ),
				array( 'status' => 400 )
			);
		}

		$cache_key = 'jet_fb_mercadopago_plans_' . md5( $secret );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return rest_ensure_response( array( 'success' => true, 'data' => $cached ) );
			}
		}

		$response = wp_remote_get(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Accept'        => 'application/json',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mp_connection',
				sprintf(
					/* translators: %s: error message */
					__( 'Falha de conexão com o Mercado Pago: %s', 'jet-form-builder-mercadopago-gateway' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$mp_msg = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';

			return new WP_Error(
				'mp_http_error',
				sprintf(
					/* translators: 1: HTTP code, 2: Mercado Pago message */
					__( 'O Mercado Pago recusou a busca de planos (HTTP %1$d). %2$s', 'jet-form-builder-mercadopago-gateway' ),
					$code,
					$mp_msg
				),
				array( 'status' => 400 )
			);
		}

		$results = ( is_array( $body ) && is_array( $body['results'] ?? null ) ) ? $body['results'] : array();
		$data    = $this->map_plans( $results );

		set_transient( $cache_key, $data, WEEK_IN_SECONDS );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Mapeia os planos do MP para o formato do dropdown.
	 *
	 * @param array $results
	 *
	 * @return array
	 */
	private function map_plans( array $results ): array {
		$data = array();

		foreach ( $results as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}

			$id = (string) ( $plan['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$auto   = is_array( $plan['auto_recurring'] ?? null ) ? $plan['auto_recurring'] : array();
			$amount = $auto['transaction_amount'] ?? null;
			$label  = (string) ( $plan['reason'] ?? $id );

			if ( null !== $amount ) {
				$freq = isset( $auto['frequency'], $auto['frequency_type'] )
					? ' /' . $auto['frequency'] . ' ' . $auto['frequency_type']
					: '';
				$label .= ' — ' . ( $auto['currency_id'] ?? 'BRL' ) . ' ' . $amount . $freq;
			}

			// status do plano (active/inactive) só p/ informação visual.
			if ( ! empty( $plan['status'] ) && 'active' !== $plan['status'] ) {
				$label .= ' [' . $plan['status'] . ']';
			}

			$data[] = array(
				'id'       => $id,
				'key'      => $id,
				'value'    => $id,
				'label'    => $label,
				'disabled' => false,
			);
		}

		return $data;
	}
}
