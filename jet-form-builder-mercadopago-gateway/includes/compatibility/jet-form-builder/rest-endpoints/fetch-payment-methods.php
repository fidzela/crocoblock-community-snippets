<?php
/**
 * Fetch_Payment_Methods — lista os MEIOS DE PAGAMENTO da conta (Checkout Pro) para
 * a seção "Meios de pagamento" da aba MercadoPago Settings (botão SYNC).
 *
 * GET https://api.mercadopago.com/v1/payment_methods -> array de métodos, cada um
 * com { id, name, payment_type_id, status }. Agrupamos por TIPO (credit_card,
 * bank_transfer=Pix, ticket=boleto, ...) — é por TIPO que a preference exclui
 * (`excluded_payment_types`). Dinâmico: se o MP mudar/incluir, o SYNC reflete.
 *
 * Espelha o Fetch_Mercadopago_Plans (token via Mp_Token_Trait — server-side, nunca
 * no cliente; cache transient; WP_Error com mensagem em falha). Só Pay Now.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fetch_Payment_Methods extends Rest_Api_Endpoint_Base {

	use Mp_Token_Trait;

	const ENDPOINT = 'https://api.mercadopago.com/v1/payment_methods';

	public static function get_rest_base() {
		return 'fetch-mercadopago-payment-methods';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		// A aba não manda token -> cai no token GLOBAL do gateway (server-side).
		$client = trim( (string) $request->get_param( 'secret' ) );
		$secret = '' !== $client ? $client : $this->gateway_token();
		$force  = filter_var( $request->get_param( 'force_refresh' ), FILTER_VALIDATE_BOOLEAN );

		if ( '' === $secret ) {
			return new WP_Error(
				'mp_no_token',
				__( 'Access Token não configurado no gateway (JetFormBuilder → Settings → Payments Gateways → Mercado Pago).', 'jet-form-builder-mercadopago-gateway' ),
				array( 'status' => 400 )
			);
		}

		$cache_key = 'jfb_mp_payment_methods_' . md5( $secret );

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
					__( 'O Mercado Pago recusou a busca de meios de pagamento (HTTP %1$d). %2$s', 'jet-form-builder-mercadopago-gateway' ),
					$code,
					$mp_msg
				),
				array( 'status' => 400 )
			);
		}

		$data = $this->map_types( is_array( $body ) ? $body : array() );

		set_transient( $cache_key, $data, WEEK_IN_SECONDS );

		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	/**
	 * Agrupa os métodos por TIPO (ativos), com rótulo amigável + os métodos do tipo.
	 *
	 * @param array $methods Array do MP: [ {id,name,payment_type_id,status}, ... ].
	 *
	 * @return array [ {id:'bank_transfer', label:'Pix', methods:'Pix'}, ... ]
	 */
	private function map_types( array $methods ): array {
		$labels = array(
			'credit_card'   => __( 'Cartão de crédito', 'jet-form-builder-mercadopago-gateway' ),
			'debit_card'    => __( 'Cartão de débito', 'jet-form-builder-mercadopago-gateway' ),
			'ticket'        => __( 'Boleto', 'jet-form-builder-mercadopago-gateway' ),
			'bank_transfer' => __( 'Pix', 'jet-form-builder-mercadopago-gateway' ),
			'atm'           => __( 'Pagamento em lotérica/caixa', 'jet-form-builder-mercadopago-gateway' ),
			'account_money' => __( 'Saldo Mercado Pago', 'jet-form-builder-mercadopago-gateway' ),
			'prepaid_card'  => __( 'Cartão pré-pago', 'jet-form-builder-mercadopago-gateway' ),
		);

		$grouped = array();

		foreach ( $methods as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}

			$status = (string) ( $m['status'] ?? 'active' );

			if ( 'active' !== $status ) {
				continue;
			}

			$type = (string) ( $m['payment_type_id'] ?? '' );

			if ( '' === $type ) {
				continue;
			}

			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array(
					'id'      => $type,
					'label'   => $labels[ $type ] ?? $type,
					'methods' => array(),
				);
			}

			$name = (string) ( $m['name'] ?? ( $m['id'] ?? '' ) );

			if ( '' !== $name ) {
				$grouped[ $type ]['methods'][] = $name;
			}
		}

		// Achata a lista de métodos para uma string informativa.
		return array_values(
			array_map(
				static function ( $row ) {
					$row['methods'] = implode( ', ', array_unique( $row['methods'] ) );

					return $row;
				},
				$grouped
			)
		);
	}
}
