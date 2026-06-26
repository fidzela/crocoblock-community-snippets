<?php


namespace Jet_FB_Mercadopago_Gateway\Proxy;

use Jet_FB_Paypal\RestEndpoints;
use JetMercadopagoGatewayCore\JetFormBuilder\RestApiProxy;

class RestApiController extends RestApiProxy {

	public function plugin_version_compare(): string {
		return '2.0.3';
	}

	public function routes(): array {
		// IMPORTANTE — NÃO registrar aqui PayPalCancelSubscription /
		// PayPalSuspendSubscription / PayPalRefundPayment.
		// ---------------------------------------------------------------------
		// Esses 3 são "gateway-aware" (rota `(?P<gateway>...)/subscription/cancel|
		// suspend` e `.../payment/refund`) e VALIDAM o gateway da URL contra
		// gateway_id()='paypal' (core Gateway_Endpoint::get_common_args). Nós somos
		// MP-only e temos os endpoints MP-específicos `mercadopago/subscription/cancel`,
		// `.../suspend` e `mercadopago/payment/refund` (Rest_Controller). Se as rotas
		// PayPal fossem registradas, elas poderiam casar PRIMEIRO a URL `mercadopago/…`
		// (a mesma que os botões do admin geram) e devolver 400 (gateway != paypal),
		// nunca chegando ao nosso handler.
		// As CLASSES continuam existindo (não deletadas): o admin usa só os MÉTODOS
		// ESTÁTICOS delas — dynamic_rest_url()/get_methods()/get_messages() — para
		// montar a URL `mercadopago/…` e o diálogo de confirmação. Isso NÃO exige a
		// rota registrada. Resultado: botão monta a URL e o NOSSO endpoint a atende.
		$endpoints = array(
			new RestEndpoints\PaypalWebHookFormId(),
			new RestEndpoints\PaypalWebHookGlobal(),
			new RestEndpoints\FetchSubscribeNowEditor(),
			new RestEndpoints\AddSubscriptionNote(),
			new RestEndpoints\ReceiveSubscriptions(),
			new RestEndpoints\ReceivePayments(),
			new RestEndpoints\ReceiveSubscription(),
			new RestEndpoints\FetchNotesBySubscription(),
			new RestEndpoints\FetchPaymentsBySubscription(),
			new RestEndpoints\ReceivePayment(),
			new RestEndpoints\DeleteSubscriptions(),
			new RestEndpoints\DeleteSubscription(),
		);

		if (
		class_exists( '\Jet_Form_Builder\Gateways\Rest_Api\Delete_Payments_Endpoint' )
		) {
			$endpoints[] = new RestEndpoints\DeletePayments();
		}

		return $endpoints;
	}

	public function on_base_need_install() {
	}

	public function on_base_need_update() {
	}

}