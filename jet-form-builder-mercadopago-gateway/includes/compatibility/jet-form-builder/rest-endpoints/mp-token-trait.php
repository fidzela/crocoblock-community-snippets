<?php
/**
 * Mp_Token_Trait — resolve o Access Token SERVER-SIDE.
 *
 * SEGURANÇA: a página/aba de gerenciamento de planos NUNCA envia o token pelo
 * cliente. Os endpoints usam SEMPRE a chave configurada no gateway (settings
 * globais de Payments Gateways), lida aqui no servidor. Assim o token de
 * produção não trafega pelo navegador/JS.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mp_Token_Trait {

	/**
	 * Token do gateway (campo 'secret' = Access Token) das settings GLOBAIS.
	 * Server-side only.
	 *
	 * @return string
	 */
	protected function gateway_token(): string {
		if ( ! class_exists( Controller::class ) ) {
			return '';
		}

		try {
			$creds = Controller::get_credentials();
		} catch ( \Throwable $e ) {
			return '';
		}

		return trim( (string) ( $creds['secret'] ?? '' ) );
	}
}
