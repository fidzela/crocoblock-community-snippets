<?php


namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;


use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_Logic_Base;

/**
 * (Stripe-era) Expiração de Checkout Session — conceito que NÃO existe no
 * Mercado Pago (a preference não "expira" por API do mesmo modo). Classe
 * INERTE, mantida só para não quebrar referências; será removida na erradicação
 * do Stripe.
 *
 * BLINDAGEM ANTI-FATAL (Etapa 2.0): action_endpoint() agora declara `: string`
 * para casar com Base_Action::action_endpoint(): string (evita Fatal error no
 * autoload sob PHP 8).
 */
class Expire_Checkout_Session extends Base_Action {

	public function action_endpoint(): string {
		return 'v1/checkout/sessions/{id}/expire';
	}

	public static function expire( Scenario_Logic_Base $logic, array $session ) {
		if ( 'open' !== ( $session['status'] ?? '' ) ) {
			return;
		}
		( new static() )
			->set_bearer_auth( jet_fb_gateway_current()->current_gateway( 'secret' ) )
			->set_path( array( 'id' => $logic->get_scenario_row( 'transaction_id' ) ) )
			->send_request();
	}

}