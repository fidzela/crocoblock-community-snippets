<?php
/**
 * ============================================================================
 *  Retrieve_Checkout_Session  —  Consulta um PAGAMENTO no Mercado Pago
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/actions/retrieve-checkout-session.php
 *
 *  IMPORTANTE — POR QUE O NOME DA CLASSE CONTINUA "Retrieve_Checkout_Session":
 *  ---------------------------------------------------------------------------
 *  O pay-now-logic.php importa `...\Actions\Retrieve_Checkout_Session`. Mantemos
 *  o nome para você apenas COLAR POR CIMA. Só o COMPORTAMENTO muda: em vez de
 *  recuperar a Checkout Session do Stripe (GET /v1/checkout/sessions/{id}),
 *  recupera o pagamento do Mercado Pago (GET /v1/payments/{id}).
 *
 *  MAPA Stripe -> Mercado Pago:
 *    endpoint   v1/checkout/sessions/{id}  ->  v1/payments/{id}
 *    sucesso    status === 'complete'      ->  status === 'approved'
 *
 *  ATENÇÃO AO {id} (será tratado no pay-now-logic):
 *  ---------------------------------------------------------------------------
 *  No Stripe, o {id} é o id da Checkout Session, que é o MESMO transaction_id
 *  salvo na criação. No Mercado Pago é DIFERENTE: na criação salvamos o id da
 *  PREFERENCE, mas aqui precisamos do id do PAGAMENTO, que vem na back_url de
 *  retorno como `payment_id`. Portanto o pay-now-logic deve chamar
 *  set_path( array( 'id' => <payment_id da query da back_url> ) ),
 *  e NÃO o transaction_id da preference.
 *
 *  SEGURANÇA: esta consulta autenticada (Bearer access_token) é a ÚNICA fonte
 *  de verdade do status. Nunca confiar no `status` que vem na back_url —
 *  ele é apenas UX e pode ser forjado.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Checkout_Session extends Base_Action {

	/**
	 * Verbo HTTP: leitura.
	 *
	 * @var string
	 */
	protected $method = 'GET';

	/**
	 * Endpoint de consulta de pagamento. {id} = payment_id.
	 *
	 * @return string
	 */
	public function action_endpoint(): string {
		return 'v1/payments/{id}';
	}
}