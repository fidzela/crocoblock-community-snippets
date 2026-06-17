<?php
/**
 * ============================================================================
 *  Manager (JetEngine Forms) — NEUTRALIZADO (stub inerte)
 * ============================================================================
 *
 *  O projeto usa EXCLUSIVAMENTE o JetFormBuilder. Esta classe era a camada de
 *  compatibilidade com o **JetEngine Forms** (sistema de formulários separado),
 *  herdada do addon Stripe — lia chaves `stripe_*` e renderizava campos
 *  "Stripe settings" no editor do JetEngine. Como está FORA do escopo, **todo o
 *  código foi removido** e a classe virou um stub inerte:
 *
 *    - `condition()` retorna `false` ⇒ NUNCA instancia (ver plugin.php:
 *      `if ( Jet_Engine\Manager::check() ) { ... }`).
 *
 *  O arquivo é mantido apenas para a referência `Jet_Engine\Manager::check()`
 *  resolver sem fatal. Se um dia o JetEngine Forms voltar ao escopo, a
 *  implementação MP-native entra aqui.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Engine;

use Jet_FB_Mercadopago_Gateway\Compatibility\Compatibility_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager {

	use Compatibility_Trait;

	public static $instance = null;

	/**
	 * Desligado de propósito (projeto usa só JetFormBuilder).
	 *
	 * @return boolean
	 */
	protected static function condition() {
		return false;
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
