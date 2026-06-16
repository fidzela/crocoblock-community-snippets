<?php
/**
 * ============================================================================
 *  WebhookConfig  —  Credenciais e URL do webhook do Mercado Pago
 * ============================================================================
 *
 *  No Checkout Pro o pay-now confirma no RETORNO do navegador; o webhook é a
 *  REDE DE SEGURANÇA (aba fechada e — na fase 2 — Pix/boleto assíncronos).
 *
 *  Como o painel de configuração do gateway é um app Vue COMPILADO, evitamos
 *  recompilar: as credenciais do webhook são resolvidas por CONSTANTE + FILTRO.
 *
 *   - Assinatura secreta (valida o header x-signature):
 *       define( 'JFB_MP_WEBHOOK_SECRET', '...' );        // painel MP > Webhooks
 *       add_filter( 'jet-form-builder/mercadopago/webhook-secret', fn() => '...' );
 *
 *   - Access Token (autentica o GET /v1/payments/{id} no contexto do webhook,
 *     onde NÃO há gateway controller ativo para ler o 'secret' do form):
 *       define( 'JFB_MP_ACCESS_TOKEN', 'APP_USR-...' );
 *       add_filter( 'jet-form-builder/mercadopago/webhook-access-token', fn() => '...' );
 *
 *  IMPORTANTE: a "Assinatura secreta" NÃO é o Access Token. São credenciais
 *  diferentes, em telas diferentes do painel do Mercado Pago.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookConfig {

	/**
	 * Namespace e caminho da rota REST do webhook.
	 */
	const ROUTE_NAMESPACE = 'jfb-mercadopago/v1';
	const ROUTE_PATH      = '/webhook';

	/**
	 * Assinatura secreta do painel (valida x-signature). Vazia => webhook
	 * RECUSADO (401, fail-closed); configure JFB_MP_WEBHOOK_SECRET.
	 *
	 * @return string
	 */
	public static function webhook_secret(): string {
		$secret = '';

		if ( defined( 'JFB_MP_WEBHOOK_SECRET' ) ) {
			$secret = (string) JFB_MP_WEBHOOK_SECRET;
		}

		return (string) apply_filters( 'jet-form-builder/mercadopago/webhook-secret', $secret );
	}

	/**
	 * Access Token usado para verificar o pagamento no contexto do webhook.
	 *
	 * @return string
	 */
	public static function access_token(): string {
		$token = '';

		if ( defined( 'JFB_MP_ACCESS_TOKEN' ) ) {
			$token = (string) JFB_MP_ACCESS_TOKEN;
		}

		return (string) apply_filters( 'jet-form-builder/mercadopago/webhook-access-token', $token );
	}

	/**
	 * URL pública (HTTPS) que o Mercado Pago chamará. Enviada no corpo da
	 * Preference, tem precedência sobre a configuração do painel.
	 *
	 * @return string
	 */
	public static function notification_url(): string {
		$url = add_query_arg(
			'source_news',
			'webhooks',
			rest_url( self::ROUTE_NAMESPACE . self::ROUTE_PATH )
		);

		return (string) apply_filters( 'jet-form-builder/mercadopago/notification-url', $url );
	}

	/**
	 * Log defensivo (só com WP_DEBUG, ou habilitado via filtro). Nunca registra
	 * dados sensíveis (tokens, assinatura, dados de cartão).
	 *
	 * @param string $message
	 * @param array  $context
	 *
	 * @return void
	 */
	public static function log( string $message, array $context = array() ) {
		$enabled = apply_filters(
			'jet-form-builder/mercadopago/webhook-logging',
			defined( 'WP_DEBUG' ) && WP_DEBUG
		);

		if ( ! $enabled ) {
			return;
		}

		$line = '[JFB MercadoPago Webhook] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
