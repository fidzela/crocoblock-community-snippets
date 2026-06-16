<?php
/**
 * ============================================================================
 *  SignatureValidator  —  Valida o header x-signature do webhook (HMAC-SHA256)
 * ============================================================================
 *
 *  Headers recebidos do Mercado Pago:
 *    x-signature:  ts=<unix_ts>,v1=<hash_hex>
 *    x-request-id: <uuid>
 *
 *  Manifest (ordem fixa, com os valores recebidos):
 *    id:<data.id>;request-id:<x-request-id>;ts:<ts>;
 *
 *  Hash:
 *    hash_hmac( 'sha256', manifest, ASSINATURA_SECRETA )  ->  compara com v1
 *    usando hash_equals (comparação timing-safe).
 *
 *  O segredo é a "Assinatura secreta" do painel de Webhooks — NÃO o Access
 *  Token. Sem segredo configurado, is_valid() retorna true (apenas registra um
 *  aviso): a verificação real do pagamento acontece no GET /v1/payments/{id}.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SignatureValidator {

	/**
	 * @param WP_REST_Request $request Requisição do webhook.
	 * @param string          $data_id Valor de data.id (recurso notificado).
	 *
	 * @return bool true se válido OU se não há segredo configurado (skip).
	 *              false somente quando há segredo e a assinatura NÃO confere.
	 */
	public static function is_valid( WP_REST_Request $request, string $data_id ): bool {
		$secret = WebhookConfig::webhook_secret();

		// Sem segredo: não há como validar. O GET autenticado em
		// PaymentNotification continua sendo a verdade — seguimos, mas avisamos.
		if ( '' === $secret ) {
			WebhookConfig::log( 'Webhook secret not configured; skipping x-signature validation.' );

			return true;
		}

		$x_signature = (string) $request->get_header( 'x-signature' );
		$x_request   = (string) $request->get_header( 'x-request-id' );

		if ( '' === $x_signature ) {
			return false;
		}

		$parts = self::parse_signature( $x_signature );
		$ts    = $parts['ts'] ?? '';
		$v1    = $parts['v1'] ?? '';

		if ( '' === $ts || '' === $v1 ) {
			return false;
		}

		// O MP recomenda data.id em minúsculas quando alfanumérico
		// (no-op para ids numéricos do tópico 'payment').
		$id       = strtolower( $data_id );
		$manifest = "id:{$id};request-id:{$x_request};ts:{$ts};";
		$computed = hash_hmac( 'sha256', $manifest, $secret );

		return hash_equals( $computed, $v1 );
	}

	/**
	 * Quebra "ts=...,v1=..." num mapa associativo.
	 *
	 * @param string $header
	 *
	 * @return array
	 */
	private static function parse_signature( string $header ): array {
		$out = array();

		foreach ( explode( ',', $header ) as $piece ) {
			$pair = explode( '=', trim( $piece ), 2 );

			if ( 2 === count( $pair ) ) {
				$out[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		return $out;
	}
}
