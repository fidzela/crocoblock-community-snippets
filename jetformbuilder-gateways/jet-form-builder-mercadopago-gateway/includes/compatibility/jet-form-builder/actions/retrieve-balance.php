<?php
/**
 * ============================================================================
 *  Retrieve_Balance  —  Valida o Access Token (botão "Sync" do editor)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/actions/retrieve-balance.php
 *
 *  POR QUE ESTE ARQUIVO MUDOU (era o "erro crítico" ao clicar em SYNC):
 *  ---------------------------------------------------------------------------
 *   1) Endpoint: era `v1/balance` — isso é do STRIPE
 *      (https://api.stripe.com/v1/balance). No Mercado Pago NÃO existe.
 *      Trocado por `users/me`, que com um Access Token VÁLIDO devolve 200 +
 *      dados da conta (valida a credencial); com token inválido, 401.
 *   2) Assinatura: `action_endpoint()` não declarava `: string`, incompatível
 *      com o abstract de Base_Action (`action_endpoint(): string`). Isso causava
 *      *Fatal error* ao AUTOCARREGAR a classe (no clique do SYNC). Corrigido.
 *
 *  O botão "Sync" (Fetch_Pay_Now_Editor) chama isto para confirmar que o
 *  Access Token colado no campo do gateway é aceito pela API do MP.
 *
 *  (Nome da classe mantido de propósito: fetch-pay-now-editor.php faz
 *  `use ...\Retrieve_Balance` e `new Retrieve_Balance()`.)
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Balance extends Base_Action {

	/**
	 * Verbo HTTP: leitura.
	 *
	 * @var string
	 */
	protected $method = 'GET';

	/**
	 * GET /users/me — valida o Access Token (substitui o `v1/balance` do Stripe,
	 * que não existe no Mercado Pago).
	 *
	 * @return string
	 */
	public function action_endpoint(): string {
		return 'users/me';
	}
}
