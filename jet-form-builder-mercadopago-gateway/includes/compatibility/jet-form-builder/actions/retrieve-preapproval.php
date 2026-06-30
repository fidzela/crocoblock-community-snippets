<?php
/**
 * Retrieve_Preapproval — GET /preapproval/{id} (fonte de verdade do STATUS da
 * assinatura). Usado pelo webhook do tópico `subscription_preapproval` e pelo
 * retorno do navegador. Análogo do GET /v1/subscriptions/{id} do Stripe.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Preapproval extends Base_Action {

	protected $method = 'GET';

	public function action_endpoint(): string {
		return 'preapproval/{id}';
	}
}
