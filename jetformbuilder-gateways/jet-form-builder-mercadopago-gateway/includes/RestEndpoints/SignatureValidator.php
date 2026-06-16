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
 *  Manifest (ordem fixa; inclui SOMENTE os segmentos presentes — se o
 *  x-request-id NÃO vier no header, esse segmento é OMITIDO, conforme o
 *  template oficial do MP; nunca vai "request-id:;" vazio):
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

		// Sem o header de assinatura não dá para validar. Acontece quando:
		//  (a) o servidor/host REMOVE headers customizados antes do PHP, ou
		//  (b) a origem (ex.: simulador) não enviou x-signature.
		// Logamos para diagnóstico (sem dado sensível).
		if ( '' === $x_signature ) {
			WebhookConfig::log(
				'x-signature header ausente (servidor pode estar removendo, ou a origem nao enviou).',
				array( 'tem_request_id' => '' !== $x_request )
			);

			return false;
		}

		$parts = self::parse_signature( $x_signature );
		$ts    = $parts['ts'] ?? '';
		$v1    = $parts['v1'] ?? '';

		if ( '' === $ts || '' === $v1 ) {
			WebhookConfig::log( 'x-signature presente mas ts/v1 nao foram extraidos.', array( 'raw' => $x_signature ) );

			return false;
		}

		// Manifest do MP: SOMENTE os segmentos presentes, nesta ordem:
		//   id:<data.id>;request-id:<x-request-id>;ts:<ts>;
		// Se x-request-id NAO vier, o segmento e OMITIDO (não "request-id:;").
		// data.id em minúsculas quando alfanumérico (no-op p/ id numérico).
		$manifest = '';

		if ( '' !== $data_id ) {
			$manifest .= 'id:' . strtolower( $data_id ) . ';';
		}

		if ( '' !== $x_request ) {
			$manifest .= 'request-id:' . $x_request . ';';
		}

		$manifest .= 'ts:' . $ts . ';';

		$computed = hash_hmac( 'sha256', $manifest, $secret );
		$ok       = hash_equals( $computed, $v1 );

		// Diagnóstico do 401 (NÃO loga o segredo). manifest e prefixos de hash
		// não são sensíveis e revelam a causa:
		//  - prefixos diferentes com manifest correto => segredo errado;
		//  - confira se o segmento request-id entrou ou não no manifest.
		if ( ! $ok ) {
			WebhookConfig::log(
				'x-signature NAO confere (provavel: JFB_MP_WEBHOOK_SECRET != Assinatura secreta do app).',
				array(
					'manifest'        => $manifest,
					'computed_prefix' => substr( $computed, 0, 12 ),
					'received_prefix' => substr( $v1, 0, 12 ),
				)
			);
		}

		return $ok;
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
