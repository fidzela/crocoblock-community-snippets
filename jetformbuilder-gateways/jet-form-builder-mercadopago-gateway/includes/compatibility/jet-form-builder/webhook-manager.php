<?php
/**
 * ============================================================================
 *  Webhook_Manager  —  Criação de webhook no gateway (SUBSCRIPTION/fase 2)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/webhook-manager.php
 *
 *  CORRIGIDO (havia ficado com namespace do Stripe):
 *   - namespace  Jet_FB_Mercadopago_Gateway\...  ->  Jet_FB_Mercadopago_Gateway\...
 *   - ENDPOINT_PATH  /wp-json/jfb-stripe/... ->  /wp-json/jfb-mercadopago/...
 *
 *  STATUS: INERTE na fase 1. Esta classe só é chamada por `Subscription_Logic`
 *  (`maybe_create_webhook()`), e o cenário de assinatura está desligado
 *  (JFB_MP_SUBSCRIPTIONS_ENABLED === false). Portanto NADA aqui executa na fase 1.
 *
 *  IMPORTANTE p/ o futuro (fase 2 — Pix): o Mercado Pago **não** cria webhooks
 *  por API do mesmo modo que o Stripe (`/v1/webhook_endpoints`). No MP o webhook
 *  é configurado no painel OU informado via `notification_url` no corpo da
 *  preference. Logo, esta classe (toda baseada em api.stripe.com) será
 *  SUBSTITUÍDA na fase 2 por: (a) enviar `notification_url` na preference e
 *  (b) um endpoint REST que recebe a notificação e valida o header `x-signature`
 *  (HMAC-SHA256). Por ora, fica apenas válida sintaticamente e inerte.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;

use Jet_Form_Builder\Exceptions\Gateway_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhook_Manager {

	const ENDPOINT_PATH = '/wp-json/jfb-mercadopago/v1/webhook';

	/**
	 * @throws Gateway_Exception
	 */
	public function maybe_create_webhook() {
		$token      = jet_fb_gateway_current()->current_gateway( 'secret' );
		$target_url = rtrim( get_site_url(), '/' ) . self::ENDPOINT_PATH;

		$webhook_id = $this->get_webhook_id_by_endpoint( $target_url, $token );

		if ( $webhook_id ) {
			return;
		}

		// NOTE (fase 2): substituir por notification_url na preference + endpoint
		// REST com validação x-signature. Mantido inerte (Stripe-shaped) por ora.
		$response = wp_remote_post(
			'https://api.stripe.com/v1/webhook_endpoints',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'url'            => $target_url,
					'enabled_events' => array(
						'checkout.session.completed',
						'invoice.paid',
						'invoice.payment_failed',
						'customer.subscription.updated',
						'customer.subscription.deleted',
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Gateway_Exception( $response->get_error_message() );
		}

		$res_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $res_body['id'] ) ) {
			throw new Gateway_Exception( 'Could not create webhook.', $res_body );
		}
	}

	public function get_webhook_id_by_endpoint( $compared_url, $token ) {
		$response = wp_remote_get(
			'https://api.stripe.com/v1/webhook_endpoints',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Gateway_Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$webhooks = $body['data'] ?? array();

		return $this->search_webhook_by_url( $webhooks, $compared_url );
	}

	public function search_webhook_by_url( $webhooks, $endpoint ) {
		$rest_url = get_rest_url();

		foreach ( $webhooks as $webhook ) {
			$url = $webhook['url'] ?? '';
			if (
				1 === preg_match( "#$endpoint#", $url ) &&
				1 === preg_match( "#$rest_url#", $url )
			) {
				return $webhook['id'];
			}
		}

		return false;
	}
}