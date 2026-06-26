<?php
/**
 * ============================================================================
 *  Logic_Repository  —  Lista de cenários (lógica) do gateway
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/logic-repository.php
 *
 *  INTERRUPTOR DO SUBSCRIPTION (agora LIGADO por padrão):
 *  ---------------------------------------------------------------------------
 *  O cenário só roda se estiver LISTADO em rep_instances(). Subscription_Logic é
 *  registrada quando JFB_MP_SUBSCRIPTIONS_ENABLED === true — o padrão do plugin
 *  (ver arquivo principal). Sem o registro aqui, escolher "Subscription" no
 *  editor e enviar o form NÃO faz nada (o cenário não resolve) — foi essa a causa
 *  do "nada acontece" no submit. Para DESLIGAR, defina a constante como false no
 *  wp-config antes do plugin carregar.
 *
 *  @package Jet_FB_Mercadopago_Gateway
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