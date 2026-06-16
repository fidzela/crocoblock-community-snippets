<?php
/**
 * Retrieve_Preapproval_Plan — GET /preapproval_plan/{id}. Lê o PLANO recorrente
 * (frequência, valor, moeda) para gravar o RecurringCyclesModel na criação da
 * assinatura. Análogo do GET /v1/prices/{id} do Stripe (Retrieve_Price).
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Preapproval_Plan extends Base_Action {

	protected $method = 'GET';

	public function action_endpoint(): string {
		return 'preapproval_plan/{id}';
	}
}
