<?php

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use WP_REST_Response;

class Dispatcher {

	/**
	 * Roteia por TÓPICO do Mercado Pago (não por nome de evento do Stripe).
	 *
	 * @param string $event_type Tópico normalizado (ex.: 'payment').
	 * @param string $data_id    Id do recurso (data.id) a consultar na API.
	 * @param array  $payload    Corpo bruto do webhook (debug / fases futuras).
	 *
	 * @return WP_REST_Response
	 */
	public function dispatch( string $event_type, string $data_id = '', array $payload = array() ): WP_REST_Response {
		switch ( $event_type ) {

			case 'payment':
				return ( new PaymentNotification() )->handle( $data_id );

			// Assinaturas: status da assinatura (ativa/suspende/cancela).
			case 'subscription_preapproval':
				return ( new PreapprovalNotification() )->handle( $data_id );

			// Assinaturas: cobranças recorrentes geradas pela assinatura.
			case 'subscription_authorized_payment':
				return ( new AuthorizedPaymentNotification() )->handle( $data_id );

			default:
				return new WP_REST_Response( array( 'message' => 'Unhandled topic: ' . $event_type ), 200 );
		}
	}
}
