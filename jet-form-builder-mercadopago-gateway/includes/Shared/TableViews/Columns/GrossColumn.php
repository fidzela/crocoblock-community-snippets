<?php


namespace Jet_FB_Paypal\TableViews\Columns;


use Jet_FB_Mercadopago_Gateway\Money;
use Jet_FB_Paypal\Utils\PaymentUtils;
use Jet_Form_Builder\Gateways\Table_Views\Columns\Gross_Column;

class GrossColumn extends Gross_Column {

	public function get_gross_sign( $record ): string {
		if ( PaymentUtils::is_refunded( $record ) ) {
			return '-';
		}

		return parent::get_gross_sign( $record );
	}

	/**
	 * Só para registros do gateway 'mercadopago', formata o valor PELA MOEDA
	 * (ex.: BRL -> "1.000,00"). Outros gateways caem no parent (formato original) —
	 * isolamento total, não quebra PayPal/Stripe. Ver Money (regra: só exibição).
	 *
	 * @param array $record
	 *
	 * @return string
	 */
	public function get_value( array $record = array() ) {
		if ( ! ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) ) {
			return parent::get_value( $record );
		}

		return sprintf(
			'%s %s %s',
			$this->get_gross_sign( $record ),
			Money::format( $record['amount_value'] ?? 0, (string) ( $record['amount_code'] ?? Money::DEFAULT_CURRENCY ), false ),
			$record['amount_code'] ?? ''
		);
	}

}