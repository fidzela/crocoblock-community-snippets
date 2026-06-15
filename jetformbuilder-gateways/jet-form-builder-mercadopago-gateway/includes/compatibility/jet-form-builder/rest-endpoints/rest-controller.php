<?php
/**
 * ============================================================================
 *  Rest_Controller  —  Registro das rotas REST do gateway (editor/admin)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/rest-endpoints/rest-controller.php
 *
 *  POR QUE ESTE ARQUIVO MUDOU (blindagem de boot):
 *  ---------------------------------------------------------------------------
 *  `routes()` roda no `rest_api_init` (toda requisição REST). A versão original
 *  instanciava SEMPRE 5 endpoints, três deles de ASSINATURA cujos arquivos
 *  estavam com namespace/nome de classe do Stripe -> *Fatal: Class not found*
 *  ao registrar as rotas, derrubando a API REST inteira.
 *
 *  Agora os endpoints de assinatura (Fetch_Mercadopago_Plans, Cancel_Subscription,
 *  Subscription_Suspend) só são registrados quando JFB_MP_SUBSCRIPTIONS_ENABLED
 *  === true. Na fase 1 (cartão/pagamento único) eles NEM são instanciados —
 *  então, mesmo que algum tivesse ficado com namespace errado, o boot fica
 *  protegido. (Ainda assim, corrija os namespaces deles: ver o laudo.)
 *
 *  Permanecem SEMPRE registrados:
 *   - Fetch_Pay_Now_Editor : usado pelo botão "Sync Access Token" do editor.
 *   - Refund_Payment       : estorno (admin) de pagamento único.
 *  (Ambos devem estar no namespace Mercadopago — confirme no laudo/grep.)
 *
 *  @package Jet_FB_Mercadopago_Gateway
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
		);

		// Endpoints de ASSINATURA — só na fase 2 (mantém o boot da fase 1 blindado).
		if ( defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) && JFB_MP_SUBSCRIPTIONS_ENABLED ) {
			$routes[] = new Fetch_Mercadopago_Plans();
			$routes[] = new Cancel_Subscription();
			$routes[] = new Subscription_Suspend();
		}

		return $routes;
	}
}