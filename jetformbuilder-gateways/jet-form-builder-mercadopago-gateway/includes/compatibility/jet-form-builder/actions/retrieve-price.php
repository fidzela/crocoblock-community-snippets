<?php

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

class Retrieve_Price extends Base_Action {

	protected $method = \WP_REST_Server::READABLE;

	public function action_endpoint() {
		return 'v1/prices/{id}';
	}

}
