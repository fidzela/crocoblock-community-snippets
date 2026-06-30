<?php

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use WP_REST_Response;

class Dispatcher {

	/**
	 * Roteia por TÓPICO do Mercado Pago (não por nome de evento do Stripe).
	 *
	 * Aceita as DUAS convenções de nome do MP, porque a notificação chega por dois
	 * caminhos com tópicos diferentes:
	 *   - Webhooks v2 (painel):       payment · subscription_preapproval ·
	 *                                 subscription_authorized_payment
	 *   - IPN/notification_url (que setamos na própria preapproval):
	 *                                 payment · preapproval · authorized_payment
	 * Sem os apelidos IPN, a assinatura criada via notification_url caía no default
	 * e NUNCA virava ACTIVE.
	 *
	 * @param string $event_type Tópico normalizado (ex.: 'payment').
	 * @param string $data_id    Id do recurso (data.id) a consultar na API.
	 * @param array  $payload    Corpo bruto do webhook (debug / fases futuras).
	 *
	 * @return WP_REST_Response
	 */
	public function dispatch( string $event_type, string $data_id = '', array $payload = array() ): WP_REST_Response {
		WebhookConfig::log( 'Webhook dispatch', array( 'type' => $event_type, 'data_id' => $data_id ) );

		$response = $this->route( $event_type, $data_id, $payload );

		// Trilha de auditoria MÍNIMA e SEMPRE-LIGADA (§14.1): topic + data_id + status
		// + resultado, sem dado sensível. Dá rastro de suporte em produção (onde o
		// WP_DEBUG fica desligado). Filtrável/silenciável — ver WebhookConfig::audit().
		$data = $response->get_data();

		WebhookConfig::audit(
			'webhook',
			array(
				'topic'   => $event_type,
				'data_id' => $data_id,
				'status'  => $response->get_status(),
				'result'  => is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '',
			)
		);

		return $response;
	}

	/**
	 * Roteamento puro por tópico (sem efeitos colaterais de log/auditoria).
	 *
	 * @param string $event_type
	 * @param string $data_id
	 * @param array  $payload
	 *
	 * @return WP_REST_Response
	 */
	private function route( string $event_type, string $data_id, array $payload ): WP_REST_Response {
		switch ( $event_type ) {

			case 'payment':
				return ( new PaymentNotification() )->handle( $data_id );

			// Assinaturas: status da assinatura (ativa/suspende/cancela).
			case 'subscription_preapproval':
			case 'preapproval': // apelido IPN (notification_url da preapproval)
				return ( new PreapprovalNotification() )->handle( $data_id );

			// Assinaturas: cobranças recorrentes geradas pela assinatura.
			case 'subscription_authorized_payment':
			case 'authorized_payment': // apelido IPN
				return ( new AuthorizedPaymentNotification() )->handle( $data_id );

			default:
				WebhookConfig::log( 'Tópico NÃO tratado', array( 'type' => $event_type, 'data_id' => $data_id ) );

				return new WP_REST_Response( array( 'message' => 'Unhandled topic: ' . $event_type ), 200 );
		}
	}
}
