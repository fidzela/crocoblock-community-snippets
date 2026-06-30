<?php


namespace Jet_FB_Paypal\TableViews\Columns;

use Jet_FB_Mercadopago_Gateway\Money;
use Jet_FB_Paypal\Resources\BillingCycle;
use Jet_Form_Builder\Admin\Table_Views\Column_Advanced_Base;

class BillingCycleColumn extends Column_Advanced_Base {

	public function get_label(): string {
		return __( 'Billing Cycle', 'jet-form-builder-paypal-subscriptions' );
	}

	public function get_value( array $record = array() ) {
		$cycle = new BillingCycle( $record['cycle'] ?? array() );

		// MP: formata o valor PELA MOEDA (BRL -> vírgula). Demais gateways: idêntico
		// ao original (Amount::escape). Isolamento por gateway -> não quebra ninguém.
		if ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) {
			$amount = Money::format( $record['cycle']['amount'] ?? 0, (string) $cycle->get_currency(), false );
		} else {
			$amount = $cycle->get_amount();
		}

		$text     = sprintf(
			'%s %s / %s',
			$cycle->get_encoded_symbol(),
			$amount,
			$cycle->get_unit()
		);
		$quantity = $cycle->get_quantity();
		$quantity = $quantity ? "($quantity)" : '';

		return trim( $text . ' ' . $quantity );
	}

}
