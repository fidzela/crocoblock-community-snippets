<?php
/**
 * Webhook_Manager — no-op. O Mercado Pago não cria webhook por API: a notificação
 * vai pelo `notification_url` enviado no recurso (preference/preapproval) + o endpoint
 * REST que recebe e valida x-signature (MercadopagoWebHookGlobal + SignatureValidator).
 * Mantemos maybe_create_webhook() como no-op para não quebrar chamadas legadas.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhook_Manager {

	/**
	 * Rota REST que recebe as notificações do Mercado Pago (já registrada no
	 * bootstrap via MercadopagoWebHookGlobal). Mantida como referência.
	 */
	const ENDPOINT_PATH = '/wp-json/jfb-mercadopago/v1/webhook';

	/**
	 * No-op intencional. Ver doc do arquivo: o MP não cria webhook por API; a
	 * notificação vai por `notification_url` no recurso + endpoint REST com
	 * validação `x-signature`. Nada a fazer aqui.
	 *
	 * @return void
	 */
	public function maybe_create_webhook() {
		// Intencionalmente vazio (erradicação do Stripe). Ver doc acima.
	}
}
