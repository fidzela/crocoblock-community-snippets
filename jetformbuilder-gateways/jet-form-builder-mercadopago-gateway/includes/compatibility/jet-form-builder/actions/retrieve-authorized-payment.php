<?php
/**
 * Retrieve_Authorized_Payment — GET /authorized_payments/{id} (a cobrança
 * recorrente gerada por uma assinatura). Usado pelo webhook do tópico
 * `subscription_authorized_payment`. Análogo do invoice do Stripe. Devolve
 * `preapproval_id`, `status`, `transaction_amount`, `currency_id` e o objeto
 * `payment { id, status }` da cobrança real.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieve_Authorized_Payment extends Base_Action {

	protected $method = 'GET';

	public function action_endpoint(): string {
		return 'authorized_payments/{id}';
	}
}
