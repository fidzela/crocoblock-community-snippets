<?php
/**
 * Logic_Repository — cenários (lógica) do gateway. Pay_Now_Logic sempre;
 * Subscription_Logic só com JFB_MP_SUBSCRIPTIONS_ENABLED (default true; defina como
 * false no wp-config para desligar). Sem o registro aqui o cenário não resolve no submit.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic\Pay_Now_Logic;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic\Subscription_Logic;
use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_Logic_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logic_Repository extends Scenario_Logic_Repository {

	/**
	 * Cenários de lógica disponíveis.
	 *
	 * @return array
	 */
	public function rep_instances(): array {
		$items = array(
			new Pay_Now_Logic(),
		);

		if ( defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) && JFB_MP_SUBSCRIPTIONS_ENABLED ) {
			$items[] = new Subscription_Logic();
		}

		return $items;
	}
}