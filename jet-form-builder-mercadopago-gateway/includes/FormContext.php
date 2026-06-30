<?php
/**
 * FormContext — utilidades de contexto do formulário em submissão.
 *
 * Centraliza a leitura do `form_id` do submit (antes duplicada em
 * Payment_Methods_Config e Pix_Support). Best-effort: 0 fora do contexto de
 * submissão (ex.: no webhook, que roda em outra request).
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormContext {

	/**
	 * Id do formulário em submissão (best-effort). 0 se não houver handler/form.
	 *
	 * @return int
	 */
	public static function current_form_id(): int {
		if ( ! function_exists( 'jet_fb_handler' ) ) {
			return 0;
		}

		$handler = jet_fb_handler();

		return ( $handler && isset( $handler->form_id ) ) ? (int) $handler->form_id : 0;
	}
}
