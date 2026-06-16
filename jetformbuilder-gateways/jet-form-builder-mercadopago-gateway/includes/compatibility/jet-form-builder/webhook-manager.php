<?php
/**
 * ============================================================================
 *  Webhook_Manager  —  NEUTRALIZADO (erradicação do Stripe)
 * ============================================================================
 *
 *  POR QUE ESTE ARQUIVO FOI ESVAZIADO:
 *  ---------------------------------------------------------------------------
 *  A versão original (herdada do addon Stripe) criava/listava o webhook por API
 *  em `https://api.stripe.com/v1/webhook_endpoints`. Isso NÃO se aplica ao
 *  Mercado Pago: o MP **não cria webhook por API** do mesmo modo. No MP a
 *  notificação é configurada por dois caminhos — AMBOS já implementados no
 *  plugin e independentes desta classe:
 *
 *    (a) `notification_url` enviado no CORPO da preference/preapproval
 *        (ver Create_Checkout_Session::build_preference); e
 *    (b) o endpoint REST que RECEBE a notificação e valida o header
 *        `x-signature` (HMAC) — MercadopagoWebHookGlobal + SignatureValidator.
 *
 *  Por isso TODA a lógica `api.stripe.com` (criar/listar/buscar webhook por API)
 *  foi REMOVIDA daqui — era inerte e só mantinha "Stripe em caminho ativo".
 *  Mantemos o método público `maybe_create_webhook()` como **no-op** para não
 *  quebrar chamadas legadas; a reescrita MP-native das assinaturas não depende
 *  mais dele. Arquivo preservado apenas como ponto de extensão/histórico.
 *
 *  @package Jet_FB_Mercadopago_Gateway
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
