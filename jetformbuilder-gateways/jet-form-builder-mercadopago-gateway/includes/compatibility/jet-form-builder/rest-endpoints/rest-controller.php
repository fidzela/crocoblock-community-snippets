<?php
/**
 * Rest_Controller — registro das rotas REST do gateway (editor/admin).
 *
 * Os endpoints de ASSINATURA (Cancel_Subscription, Subscription_Suspend) só são
 * registrados com JFB_MP_SUBSCRIPTIONS_ENABLED. Sempre ativos: Fetch_Pay_Now_Editor
 * (botão "Sync Access Token"), Refund_Payment e o CRUD de planos.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_Form_Builder\Rest_Api\Rest_Api_Controller_Base;
use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller extends Rest_Api_Controller_Base {

	/**
	 * @return Rest_Api_Endpoint_Base[]
	 */
	public function routes(): array {
		$routes = array(
			new Fetch_Pay_Now_Editor(),
			new Refund_Payment(),
			// Gerenciamento de planos (página admin "MP Planos" + dropdown do editor).
			// Sempre disponível: o admin pode criar/listar/excluir planos da API
			// independentemente do cenário de assinatura estar ligado.
			new Fetch_Mercadopago_Plans(),
			new Create_Mercadopago_Plan(),
			new Delete_Mercadopago_Plan(),
		);

		// Gerenciamento de ASSINATURA (cancelar/suspender pelo admin). Ligado por
		// padrão junto com o cenário Subscription (JFB_MP_SUBSCRIPTIONS_ENABLED).
		if ( defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) && JFB_MP_SUBSCRIPTIONS_ENABLED ) {
			$routes[] = new Cancel_Subscription();
			$routes[] = new Subscription_Suspend();
		}

		return $routes;
	}
}