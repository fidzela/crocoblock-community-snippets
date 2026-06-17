<?php
/**
 * ============================================================================
 *  Retrieve_Payment  —  Consulta um PAGAMENTO no Mercado Pago (GET /v1/payments/{id})
 * ============================================================================
 *
 *  Fonte de verdade do status de um pagamento. Usada no retorno do pay-now e no
 *  webhook (tópico `payment`).
 *
 *  ATENÇÃO AO {id}: é o payment_id (NÃO o id da preference). Na criação salvamos
 *  o id da PREFERENCE em transaction_id; aqui precisamos do id do PAGAMENTO, que
 *  o MP devolve na back_url como `payment_id`. Logo, chamar
 *  set_path( array( 'id' => <payment_id> ) ).
 *
 *  SEGURANÇA: consulta autenticada (Bearer access_token) é a ÚNICA fonte de
 *  verdade do status. Nunca confiar no `status` da back_url (UX, pode ser forjado).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Payment extends Base_Action {

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