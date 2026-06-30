<?php
/**
 * Refund_Payment_Action — POST /v1/payments/{id}/refunds (estorno).
 *
 * Corpo vazio = estorno TOTAL. Com `amount` = estorno PARCIAL. O caller DEVE
 * setar o X-Idempotency-Key (set_idempotency_key) para impedir estorno duplo em
 * retry/duplo-clique. Análogo do POST /v1/refunds do Stripe.
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Refund_Payment_Action extends Base_Action {

	protected $method = 'POST';

	/**
	 * Valor do estorno parcial (null = estorno total).
	 *
	 * @var float|null
	 */
	protected $amount = null;

	public function action_endpoint(): string {
		return 'v1/payments/{id}/refunds';
	}

	public function set_amount( $amount ) {
		$this->amount = ( null === $amount ) ? null : (float) $amount;

		return $this;
	}

	public function send_request(): array {
		// Estorno parcial: envia o valor. Total: corpo vazio (Base_Action não
		// envia body quando vazio), mas mantém o X-Idempotency-Key do caller.
		if ( null !== $this->amount && $this->amount > 0 ) {
			$this->add_body_param( 'amount', round( $this->amount, 2 ) );
		}

		return parent::send_request();
	}
}
