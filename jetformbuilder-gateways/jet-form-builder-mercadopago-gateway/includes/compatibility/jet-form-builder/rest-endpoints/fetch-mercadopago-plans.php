<?php
/**
 * ============================================================================
 *  Fetch_Mercadopago_Plans  —  Endpoint REST de "planos" (SUBSCRIPTION/fase 2)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/rest-endpoints/fetch-mercadopago-plans.php
 *
 *  >>> ESTE ERA O BLOQUEADOR Nº 1 (fatal garantido no boot) <<<
 *  ---------------------------------------------------------------------------
 *  O `Rest_Controller::routes()` faz `new Fetch_Mercadopago_Plans()`. O arquivo,
 *  porém, declarava `class Fetch_Stripe_Plans` no namespace `Jet_FB_Mercadopago_Gateway`.
 *  Resultado: ao registrar as rotas (no `rest_api_init`, que dispara em TODA
 *  requisição REST — inclusive wp-admin e o submit do formulário), o autoloader
 *  carregava este arquivo mas NÃO encontrava a classe `Fetch_Mercadopago_Plans`
 *  -> *Fatal error: Class not found*. A API REST inteira quebrava.
 *
 *  CORRIGIDO:
 *   - namespace  ...Stripe...  ->  ...Mercadopago...
 *   - class      Fetch_Stripe_Plans  ->  Fetch_Mercadopago_Plans   (casa com o arquivo)
 *   - restaurada a URL comentada em fetch_prices (havia virado bug de runtime:
 *     com a 1ª linha comentada, o array ia como 1º argumento de wp_remote_get).
 *
 *  ESCOPO/COMPORTAMENTO: este endpoint é a tela "Refresh Plans" do cenário de
 *  ASSINATURA. No Mercado Pago não há "plans" do mesmo jeito que no Stripe; isto
 *  é puramente legado/INERTE na fase 1 (cartão, pagamento único). Como o
 *  Rest_Controller agora registra este endpoint apenas quando
 *  JFB_MP_SUBSCRIPTIONS_ENABLED === true, na prática ele NEM é instanciado na
 *  fase 1. O conteúdo abaixo é o original (renomeado) só para o arquivo ser
 *  válido; será reescrito para o MP (Preapproval Plan) quando entrarmos em
 *  assinaturas.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Exceptions\Gateway_Exception;
use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fetch_Mercadopago_Plans extends Rest_Api_Endpoint_Base {

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
		$secret        = $request->get_param( 'secret' );
		$force_refresh = filter_var( $request->get_param( 'force_refresh' ), FILTER_VALIDATE_BOOLEAN );

		if ( empty( $secret ) ) {
			throw new Gateway_Exception( 'Access token is required', $secret );
		}

		$data = $this->get_cached_plans( $secret, $force_refresh );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	private function get_cached_plans( string $secret, bool $force_refresh = false ): array {
		$cache_key = 'jet_fb_mercadopago_plans_' . md5( $secret );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$prices   = $this->fetch_prices( $secret );
		$products = $this->fetch_products( $secret );

		$data = $this->map_prices_with_products( $prices, $products );

		set_transient( $cache_key, $data, WEEK_IN_SECONDS );

		return $data;
	}

	private function fetch_prices( string $secret ): array {
		// NOTE (fase 2): trocar por endpoint de planos do Mercado Pago
		// (POST/GET /preapproval_plan). Mantido inerte na fase 1.
		$response = wp_remote_get(
			'https://api.mercadopago.com/preapproval_plan/search',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Gateway_Exception( $response->get_error_message(), $response );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body->results ) ) {
			return array();
		}

		return $body->results;
	}

	private function fetch_products( string $secret ): array {
		// Inerte na fase 1 (MP não tem o conceito "products" do Stripe).
		return array();
	}

	private function map_prices_with_products( array $prices, array $products ): array {
		$data = array();

		foreach ( $prices as $price ) {
			$data[] = array(
				'id'       => $price->id ?? '',
				'key'      => $price->id ?? '',
				'label'    => $price->reason ?? ( $price->id ?? '' ),
				'disabled' => false,
			);
		}

		return $data;
	}
}