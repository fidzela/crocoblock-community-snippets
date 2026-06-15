<?php
/**
 * ============================================================================
 *  Base_Action  —  Cliente HTTP base para a API do Mercado Pago
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/actions/base-action.php
 *
 *  POR QUE ESTE ARQUIVO FOI REESCRITO DO ZERO (e não renomeado do Stripe):
 *  ---------------------------------------------------------------------------
 *  O Base_Action original do Stripe estendia
 *  `Jet_Form_Builder\Gateways\Base_Gateway_Action`, cujo `send_request()`
 *  serializa o corpo como **form-encoded** (application/x-www-form-urlencoded),
 *  porque a API do Stripe espera isso. A API do Mercado Pago espera **JSON**
 *  (Content-Type: application/json) — exatamente como o addon PayPal, que
 *  sobrescreve `to_json()` para devolver uma string JSON.
 *
 *  Em vez de depender de detalhes internos não-públicos do core (nomes de
 *  propriedades, como ele monta headers, como codifica o body), este
 *  Base_Action é AUTOSSUFICIENTE: ele mesmo monta a requisição com
 *  `wp_remote_request`, JSON no corpo, `Authorization: Bearer {access_token}`,
 *  `Content-Type: application/json` e `X-Idempotency-Key`. Zero surpresa de
 *  serialização, tudo sob controle e documentado.
 *
 *  COMPATIBILIDADE DE INTERFACE (drop-in):
 *  ---------------------------------------------------------------------------
 *  Mantém os MESMOS nomes de método fluente que as subclasses já chamam
 *  (`set_bearer_auth`, `set_path`, `add_body_param`, `set_body`,
 *  `send_request`) e a MESMA assinatura de `action_endpoint()` / `base_url()`.
 *  Assim, as subclasses existentes (Create_Checkout_Session,
 *  Retrieve_Checkout_Session, e as inertes Retrieve_Price/Retrieve_Balance/
 *  Expire_Checkout_Session) continuam compilando sem alteração.
 *
 *  MAPA Stripe -> Mercado Pago:
 *    base_url()           'https://api.stripe.com/'  ->  'https://api.mercadopago.com/'
 *    corpo                form-encoded               ->  JSON
 *    auth                 Bearer secret_key          ->  Bearer access_token
 *    idempotência         (automática no Stripe)     ->  header X-Idempotency-Key
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

// Sai se acessado diretamente.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base_Action {

	/**
	 * Verbo HTTP. Padrão POST (criação). Subclasses de leitura definem 'GET'.
	 *
	 * @var string
	 */
	protected $method = 'POST';

	/**
	 * Access Token do Mercado Pago (credencial privada, server-side).
	 *
	 * @var string
	 */
	protected $token = '';

	/**
	 * Corpo da requisição (vira JSON em requisições de escrita).
	 *
	 * @var array
	 */
	protected $body = array();

	/**
	 * Parâmetros que substituem placeholders {chave} no endpoint.
	 * Ex.: set_path( array( 'id' => 123 ) ) em 'v1/payments/{id}'.
	 *
	 * @var array
	 */
	protected $path_params = array();

	/**
	 * Valor do header X-Idempotency-Key (evita cobranças duplicadas em retry).
	 *
	 * @var string
	 */
	protected $idempotency_key = '';

	/**
	 * Base da API do Mercado Pago. Subclasses NÃO precisam sobrescrever.
	 *
	 * @return string
	 */
	public function base_url(): string {
		return 'https://api.mercadopago.com/';
	}

	/**
	 * Caminho do recurso (pode conter placeholders como {id}).
	 * Ex.: 'checkout/preferences', 'v1/payments/{id}'.
	 *
	 * @return string
	 */
	abstract public function action_endpoint(): string;

	/**
	 * Define o Access Token (Authorization: Bearer ...).
	 *
	 * @param string $token
	 *
	 * @return static
	 */
	public function set_bearer_auth( $token ) {
		$this->token = (string) $token;

		return $this;
	}

	/**
	 * Define os parâmetros de caminho para resolver placeholders no endpoint.
	 *
	 * @param array $params
	 *
	 * @return static
	 */
	public function set_path( array $params ) {
		$this->path_params = $params;

		return $this;
	}

	/**
	 * Substitui todo o corpo da requisição.
	 *
	 * @param array $body
	 *
	 * @return static
	 */
	public function set_body( array $body ) {
		$this->body = $body;

		return $this;
	}

	/**
	 * Adiciona/atualiza um único parâmetro no corpo.
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return static
	 */
	public function add_body_param( $key, $value ) {
		$this->body[ $key ] = $value;

		return $this;
	}

	/**
	 * Define a chave de idempotência (header X-Idempotency-Key).
	 *
	 * @param string $key
	 *
	 * @return static
	 */
	public function set_idempotency_key( $key ) {
		$this->idempotency_key = (string) $key;

		return $this;
	}

	/**
	 * Resolve os placeholders {chave} do endpoint com os path_params.
	 *
	 * @return string
	 */
	protected function resolve_endpoint(): string {
		$endpoint = $this->action_endpoint();

		foreach ( $this->path_params as $key => $value ) {
			$endpoint = str_replace(
				'{' . $key . '}',
				rawurlencode( (string) $value ),
				$endpoint
			);
		}

		return $endpoint;
	}

	/**
	 * URL final da requisição.
	 *
	 * @return string
	 */
	protected function request_url(): string {
		return $this->base_url() . ltrim( $this->resolve_endpoint(), '/' );
	}

	/**
	 * Headers da requisição. Bearer + JSON + (opcional) idempotência.
	 *
	 * @return array
	 */
	protected function request_headers(): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		if ( '' !== $this->idempotency_key ) {
			$headers['X-Idempotency-Key'] = $this->idempotency_key;
		}

		return $headers;
	}

	/**
	 * Normaliza o verbo HTTP. Aceita strings do WP_REST_Server
	 * (READABLE='GET', CREATABLE='POST', EDITABLE='POST, PUT, PATCH').
	 * Se vier lista separada por vírgula, usa o primeiro.
	 *
	 * @return string
	 */
	protected function method_string(): string {
		$method = (string) $this->method;

		if ( false !== strpos( $method, ',' ) ) {
			$parts  = explode( ',', $method );
			$method = trim( $parts[0] );
		}

		return '' !== $method ? strtoupper( $method ) : 'POST';
	}

	/**
	 * É uma requisição de escrita (envia corpo)?
	 *
	 * @return bool
	 */
	protected function is_write(): bool {
		return in_array(
			$this->method_string(),
			array( 'POST', 'PUT', 'PATCH' ),
			true
		);
	}

	/**
	 * Executa a requisição contra a API do Mercado Pago.
	 *
	 * Retorno SEMPRE array. Em erro (HTTP != 2xx ou WP_Error), devolve um
	 * array com a chave 'error' => array( 'message' => ..., 'code' => ... ),
	 * mantendo o MESMO contrato que o chamador do Stripe já espera
	 * ( if ( isset( $resp['error'] ) ) { ... } ).
	 *
	 * @return array
	 */
	public function send_request(): array {
		$args = array(
			'method'  => $this->method_string(),
			'headers' => $this->request_headers(),
			'timeout' => 30,
		);

		if ( $this->is_write() && ! empty( $this->body ) ) {
			$args['body'] = wp_json_encode( $this->body );
		}

		$response = wp_remote_request( $this->request_url(), $args );

		// Falha de transporte (DNS, timeout, SSL...).
		if ( is_wp_error( $response ) ) {
			return array(
				'error' => array(
					'message' => $response->get_error_message(),
					'code'    => 'http_error',
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Mercado Pago devolve 4xx/5xx com { message, error, status, cause }.
		// Normalizamos para o contrato 'error' => array( 'message', 'code' ).
		if ( $code < 200 || $code >= 300 ) {
			$message = '';

			if ( ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			} elseif ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = (string) $data['error'];
			} else {
				$message = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Mercado Pago API error (HTTP %d)', 'jet-form-builder-mercadopago-gateway' ),
					$code
				);
			}

			$data['error'] = array(
				'message' => $message,
				'code'    => $code,
				'cause'   => $data['cause'] ?? array(),
			);
		}

		return $data;
	}
}