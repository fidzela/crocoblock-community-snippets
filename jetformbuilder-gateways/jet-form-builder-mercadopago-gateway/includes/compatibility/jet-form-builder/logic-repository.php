<?php
/**
 * ============================================================================
 *  Logic_Repository  —  Lista de cenários (lógica) do gateway
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/logic-repository.php
 *
 *  AQUI É O "INTERRUPTOR" DO SUBSCRIPTION (ativo-mas-inerte):
 *  ---------------------------------------------------------------------------
 *  O cenário só roda se estiver LISTADO em rep_instances(). A classe
 *  Subscription_Logic continua no disco (autoloader feliz, sem fatal), mas só
 *  é registrada se JFB_MP_SUBSCRIPTIONS_ENABLED === true. Por padrão fica de
 *  fora -> o "Subscription" não aparece no editor e nunca é invocado ->
 *  impossível dar erro por causa dele.
 *
 *  Para LIGAR no futuro: defina no arquivo principal do plugin
 *      define( 'JFB_MP_SUBSCRIPTIONS_ENABLED', true );
 *  (e implemente a lógica de Preapproval do Mercado Pago no Subscription_Logic).
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