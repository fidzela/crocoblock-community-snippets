<?php

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

/**
 * (Stripe-era) Consulta de um Price recorrente. INERTE na fase 1: só é
 * referenciada pelo subscription-logic (cenário de assinatura, atrás do flag
 * JFB_MP_SUBSCRIPTIONS_ENABLED). O equivalente MP do "Price recorrente" é o
 * preapproval_plan (GET /preapproval_plan/{id}); a troca virá com o port de
 * assinaturas.
 *
 * BLINDAGEM ANTI-FATAL (Etapa 2.0): action_endpoint() agora declara `: string`
 * para casar com Base_Action::action_endpoint(): string. Sem isso, o autoload
 * dessa classe dá Fatal error (assinatura incompatível no PHP 8) — o mesmo bug
 * que já derrubou o SYNC (ver retrieve-balance.php).
 */
class Retrieve_Price extends Base_Action {

	protected $method = \WP_REST_Server::READABLE;

	public function action_endpoint(): string {
		return 'v1/prices/{id}';
	}

}
