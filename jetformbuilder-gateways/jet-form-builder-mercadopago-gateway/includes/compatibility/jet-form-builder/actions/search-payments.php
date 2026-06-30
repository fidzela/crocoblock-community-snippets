<?php
/**
 * Search_Payments — GET /v1/payments/search?external_reference=...
 *
 * Resolve o `payment_id` do MP a partir do external_reference. Necessário no
 * REFUND de PAY-NOW: lá o `Payment_Model.transaction_id` guarda o id da
 * PREFERENCE (não o do pagamento), mas o external_reference (persistido em
 * initial_transaction_id) é ecoado no objeto do pagamento e é pesquisável.
 * (Em assinatura, o transaction_id já é o payment_id, então a busca não é usada.)
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search_Payments extends Base_Action {

	protected $method = 'GET';

	protected $external_reference = '';

	public function set_external_reference( $reference ) {
		$this->external_reference = (string) $reference;

		return $this;
	}

	public function action_endpoint(): string {
		return 'v1/payments/search?sort=date_created&criteria=desc&external_reference='
			. rawurlencode( $this->external_reference );
	}

	/**
	 * Retorna o id do 1º pagamento APROVADO encontrado (ou '' se nenhum).
	 *
	 * @return string
	 */
	public function find_approved_payment_id(): string {
		$resp = $this->send_request();

		if ( isset( $resp['error'] ) || empty( $resp['results'] ) || ! is_array( $resp['results'] ) ) {
			return '';
		}

		foreach ( $resp['results'] as $payment ) {
			if ( 'approved' === ( $payment['status'] ?? '' ) ) {
				return (string) ( $payment['id'] ?? '' );
			}
		}

		// fallback: o mais recente, mesmo que não 'approved'.
		return (string) ( $resp['results'][0]['id'] ?? '' );
	}
}
