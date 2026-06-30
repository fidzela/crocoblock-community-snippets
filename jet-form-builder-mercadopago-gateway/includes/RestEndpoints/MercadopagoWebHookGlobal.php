<?php

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints;

use WP_REST_Response;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\Base\MercadopagoWebHookBase;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents\Dispatcher;

class MercadopagoWebHookGlobal extends MercadopagoWebHookBase {

	public function get_route_namespace(): string {
		return 'jfb-mercadopago/v1';
	}

	public function run_event( array $event_data ): WP_REST_Response {
		$type    = (string) ( $event_data['type'] ?? '' );
		$data_id = (string) ( $event_data['data_id'] ?? '' );
		$raw     = (array) ( $event_data['raw'] ?? array() );

		return ( new Dispatcher() )->dispatch( $type, $data_id, $raw );
	}
}
