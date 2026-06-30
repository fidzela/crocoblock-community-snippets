<?php

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\Base;

use WP_REST_Request;
use WP_REST_Response;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\SignatureValidator;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;

abstract class MercadopagoWebHookBase {

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	abstract public function get_route_namespace(): string;
	abstract public function run_event( array $event_data ): WP_REST_Response;

	public function register_endpoint() {
		register_rest_route(
			$this->get_route_namespace(),
			'/webhook',
			[
				'methods'             => self::get_methods(),
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$query = (array) $request->get_query_params();

		$type    = $this->resolve_type( $body, $query );
		$data_id = $this->resolve_data_id( $body, $query );

		// Primeiro sinal de diagnóstico: PROVA que o MP chegou no endpoint. Se isto
		// não aparece no log (WP_DEBUG on), o webhook nem está chegando -> é
		// notification_url/registro no painel, não código.
		WebhookConfig::log( 'Webhook recebido', array( 'type' => $type, 'data_id' => $data_id ) );

		// Defesa em profundidade. Sem segredo configurado, is_valid() retorna
		// true e apenas registra um aviso (o GET autenticado é a fonte de verdade).
		if ( ! SignatureValidator::is_valid( $request, $data_id ) ) {
			return new WP_REST_Response( [ 'message' => 'Invalid signature' ], 401 );
		}

		if ( '' === $type ) {
			// Sem tópico não há o que rotear; 200 evita reentrega do MP.
			return new WP_REST_Response( [ 'message' => 'No topic' ], 200 );
		}

		return $this->run_event(
			array(
				'type'    => $type,
				'data_id' => $data_id,
				'raw'     => $body,
			)
		);
	}

	/**
	 * Resolve o TÓPICO a partir do corpo (webhooks v2) ou da query (IPN legado),
	 * normalizando 'payment.created'/'payment.updated' -> 'payment'.
	 *
	 * @param array $body
	 * @param array $query
	 *
	 * @return string
	 */
	protected function resolve_type( array $body, array $query ): string {
		$type = $body['type'] ?? ( $query['type'] ?? ( $query['topic'] ?? '' ) );

		if ( '' === $type && ! empty( $body['action'] ) ) {
			$type = explode( '.', (string) $body['action'] )[0];
		}

		$type = (string) $type;

		if ( 0 === strpos( $type, 'payment' ) ) {
			return 'payment';
		}

		return $type;
	}

	/**
	 * Resolve o data.id do corpo ou da query. Atenção: o PHP converte '.' em '_'
	 * nas chaves de query, então '?data.id=' costuma chegar como 'data_id'.
	 *
	 * @param array $body
	 * @param array $query
	 *
	 * @return string
	 */
	protected function resolve_data_id( array $body, array $query ): string {
		$id = $body['data']['id'] ?? '';

		if ( '' === $id ) {
			$id = $query['data.id'] ?? ( $query['data_id'] ?? ( $query['id'] ?? '' ) );
		}

		return sanitize_text_field( (string) $id );
	}
}
