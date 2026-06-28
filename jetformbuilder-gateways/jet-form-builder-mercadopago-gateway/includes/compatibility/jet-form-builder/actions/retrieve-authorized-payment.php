<?php
/**
 * Retrieve_Authorized_Payment — GET /authorized_payments/{id} (a FATURA da
 * cobrança recorrente de uma assinatura). Usado pelo webhook do tópico
 * `subscription_authorized_payment`. Análogo do invoice do Stripe. Devolve
 * `preapproval_id`, `status`, `transaction_amount`, `currency_id`.
 *
 * CORRELAÇÃO COM O PAGAMENTO (docs MP): a FATURA e o PAGAMENTO são recursos
 * DIFERENTES — `authorized_payment.id != payment.id`. O vínculo correto é o campo
 * `payment_id` (id do payment real). Algumas respostas trazem também um objeto
 * aninhado `payment { id, status }`; o handler usa `payment.id` quando presente e
 * cai em `payment_id` — NUNCA em `id` (que é o da fatura).
 *
 * OBS (confirmar no sandbox): o MP documenta este recurso ora como
 * `/authorized_payments/{id}` (forma usada aqui), ora como `/authorized/payments/{id}`.
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
