<?php
/**
 * Update_Preapproval — PUT /preapproval/{id}. GERENCIA a assinatura:
 *   status = cancelled  (cancelar)   <- DELETE /v1/subscriptions/{id} do Stripe
 *   status = paused     (suspender)  <- pause_collection do Stripe
 *   status = authorized (reativar)   <- remover pause_collection do Stripe
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Preapproval extends Base_Action {

	protected $method = 'PUT';

	public function action_endpoint(): string {
		return 'preapproval/{id}';
	}

	public function set_status( $status ) {
		$this->add_body_param( 'status', (string) $status );

		return $this;
	}
}
