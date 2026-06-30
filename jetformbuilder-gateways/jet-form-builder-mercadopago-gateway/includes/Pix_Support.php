<?php
/**
 * ============================================================================
 *  Pix_Support — habilita Pix/boleto (pagamentos ASSÍNCRONOS) no Pay Now
 * ============================================================================
 *
 *  O QUE FAZ: quando um formulário ACEITA Pix (ou boleto) — i.e. não exclui o
 *  tipo `bank_transfer`/`ticket` em Payment_Methods_Config — a preference do
 *  Checkout Pro precisa de `binary_mode = false`. Com `binary_mode = true`
 *  (default, ideal para cartão) o MP REJEITA pagamentos `pending`, e Pix/boleto
 *  são assíncronos (nascem `pending` até o pagador pagar) → ficariam de fora do
 *  checkout. Esta classe baixa o `binary_mode` SÓ nesses formulários.
 *
 *  COMO: hooka o filtro `jet-form-builder/mercadopago/preference` que o
 *  Create_Preference JÁ dispara → **não tocamos no create-preference.php**. Os
 *  meios em si continuam controlados por Payment_Methods_Config (o filtro de
 *  `excluded_payment_types`): se o form não exclui `bank_transfer`, o Pix já é
 *  oferecido — aqui só garantimos o `binary_mode` coerente.
 *
 *  ISOLAMENTO: forms que NÃO aceitam Pix/boleto ficam intactos (`binary_mode`
 *  permanece como está = true). Cartão/saldo seguem idênticos a hoje.
 *
 *  CONFIRMAÇÃO: Pix paga "depois" → a venda é efetivada pelo WEBHOOK
 *  (PaymentNotification → PaymentFulfillment), que já existe. O retorno do
 *  navegador em `pending` é tratado pelo Pay_Now_Logic (mensagem "aguardando").
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pix_Support {

	/**
	 * Liga o hook na preference. Prioridade 20 (depois do Payer_Info, prio 10) —
	 * mexe em chave diferente (`binary_mode`), então não há conflito.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter(
			'jet-form-builder/mercadopago/preference',
			array( __CLASS__, 'filter_preference' ),
			20,
			2
		);
	}

	/**
	 * Baixa o binary_mode quando o form aceita Pix/boleto.
	 *
	 * @param array $preference
	 * @param mixed $action
	 *
	 * @return array
	 */
	public static function filter_preference( array $preference, $action = null ): array {
		$form_id = FormContext::current_form_id();

		// Form só-cartão/saldo -> mantém o binary_mode atual (true). Nada muda.
		if ( ! $form_id || ! Payment_Methods_Config::accepts_async( $form_id ) ) {
			return $preference;
		}

		// Pix/boleto exigem aceitar 'pending' no checkout -> binary_mode OFF.
		$preference['binary_mode'] = false;

		return apply_filters( 'jet-form-builder/mercadopago/pix-preference', $preference, $form_id );
	}
}
