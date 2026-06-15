<?php
/**
 * ============================================================================
 *  Base_Api_Method  —  CLIENTE LEGADO (JetEngine Forms). Inerte na fase 1.
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/api-methods/base-api-method.php
 *
 *  POR QUE EXISTE / POR QUE NÃO É USADO NO FLUXO JFB:
 *  ---------------------------------------------------------------------------
 *  Esta classe pertence ao caminho de compatibilidade com o **JetEngine Forms**
 *  (o sistema de formulários ANTIGO do JetEngine, anterior ao JetFormBuilder).
 *  O fluxo do JetFormBuilder NÃO usa esta classe — ele usa
 *  `Compatibility\Jet_Form_Builder\Actions\Base_Action` (o cliente JSON do MP).
 *
 *  O QUE FOI CORRIGIDO (este arquivo havia ficado para trás no rename):
 *  ---------------------------------------------------------------------------
 *   - namespace  Jet_FB_Mercadopago_Gateway\Api_Methods  ->  Jet_FB_Mercadopago_Gateway\Api_Methods
 *   - use        JetStripeGatewayCore\...           ->  JetMercadopagoGatewayCore\...
 *   - $api_url   api.stripe.com                     ->  api.mercadopago.com
 *
 *  O namespace ERRADO aqui é FATAL: o autoloader do plugin só resolve
 *  `Jet_FB_Mercadopago_Gateway\...`; uma classe declarada como
 *  `Jet_FB_Mercadopago_Gateway\Api_Methods\Base_Api_Method` jamais é encontrada
 *  quando referenciada (ex.: por `Compatibility\Jet_Engine\Manager`, que faz
 *  `use Jet_FB_Mercadopago_Gateway\Api_Methods\Checkout_Session`).
 *
 *  Mantido o COMPORTAMENTO original (form-encoded, CurlHelper) porque é o que
 *  o caminho JetEngine espera. Em produção MP esse caminho permanece inerte;
 *  só seria exercido por um formulário do JetEngine Forms usando este gateway.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Api_Methods;

use JetMercadopagoGatewayCore\Common\CurlHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base_Api_Method {

	private $api_url       = 'https://api.mercadopago.com/';
	private $curl_instance = false;
	protected $response    = array();
	protected $token;

	public function __construct( $token ) {
		$this->token = $token;
	}

	abstract public function method_name();

	public function get_request( $endpoint = '', $with_clear = false ) {
		if ( $with_clear ) {
			$this->clear_request();
		}

		if ( false === $this->curl_instance ) {
			$this->curl_instance = new CurlHelper( $this->api_url( $endpoint ) );
		}

		return $this->curl_instance;
	}

	public function clear_request() {
		$this->curl_instance = false;
	}

	public function api_url( $endpoint = '' ) {
		return $this->api_url . $this->method_name() . $endpoint;
	}

	public function _save_response( $type, $response ) {
		$this->response[ $type ] = $response;
	}

	public function get_response_create( $key = '' ) {
		return $key ? $this->get_response( 'create' )[ $key ] : $this->get_response( 'create' );
	}

	public function get_response( $type = '' ) {
		return $type ? $this->response[ $type ] : $this->response;
	}

	public function save_response( $type ) {
		$this->_save_response(
			$type,
			json_decode( $this->get_request()->execute(), true )
		);
	}

	public function create( $fields, $endpoint = '', $post = true ) {
		$this->get_request( $endpoint )
			->set_post( $post )
			->set_auth( $this->token )
			->set_post_fields( http_build_query( $fields ) );

		$this->save_response( 'create' );
	}
}