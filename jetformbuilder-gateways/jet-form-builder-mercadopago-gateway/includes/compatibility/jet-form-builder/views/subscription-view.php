<?php

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Views;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Subscription_Connector;
use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_View_Base;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints\Fetch_Pay_Now_Editor;

class Subscription_View extends Scenario_View_Base {

	use Subscription_Connector;

	public function get_title(): string {
		return _x( 'Subscription', 'Mercadopago gateway editor data', 'jet-form-builder-mercadopago-gateway' );
	}

	public function get_editor_labels(): array {
		return array(
			'subscribe_plan_field' 		 => __( 'Subscription Plan Field', 'jet-form-builder-mercadopago-gateway' ),
			'subscribe_plan'       		 => __( 'Subscription Plan', 'jet-form-builder-mercadopago-gateway' ),
			'refresh_plans_button'     	 => __( 'Refresh Plans From Mercadopago', 'jet-form-builder-mercadopago-gateway' ),
			'plans_fetched_successfully' => __( 'Successfully Updated Plans', 'jet-form-builder-mercadopago-gateway' ),
		);
	}

	public function get_editor_data(): array {
		return array(
			'fetch' => array(
				'method' => Fetch_Pay_Now_Editor::get_methods(),
				'url'    => Fetch_Pay_Now_Editor::rest_url(),
			),
		);
	}
}
