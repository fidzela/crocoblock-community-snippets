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

		// MP: valor formatado PELA MOEDA (BRL -> vírgula) + a FREQUÊNCIA REAL do plano
		// ("a cada N meses/dias", de interval_count + interval_unit). O formato original
		// "unit (total_cycles)" NÃO servia para o MP: 'months' (plural) não casava com o
		// format_recurring_unit (unit vazio) e o total_cycles vinha fixo em 1 -> "(1)".
		// Isolado por gateway -> demais gateways (PayPal) seguem idênticos.
		if ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) {
			return trim(
				sprintf(
					'%s %s / %s',
					$cycle->get_encoded_symbol(),
					Money::format( $record['cycle']['amount'] ?? 0, (string) $cycle->get_currency(), false ),
					self::mp_period_label(
						(int) $cycle->get_interval_count(),
						(string) ( $record['cycle']['interval_unit'] ?? '' )
					)
				)
			);
		}

		$text     = sprintf(
			'%s %s / %s',
			$cycle->get_encoded_symbol(),
			$cycle->get_amount(),
			$cycle->get_unit()
		);
		$quantity = $cycle->get_quantity();
		$quantity = $quantity ? "($quantity)" : '';

		return trim( $text . ' ' . $quantity );
	}

	/**
	 * Rótulo da frequência do plano MP a partir de interval_count + interval_unit
	 * (o MP usa frequency_type 'months'/'days'). Ex.: "mês", "3 meses", "7 dias".
	 *
	 * @param int    $count
	 * @param string $unit
	 *
	 * @return string
	 */
	private static function mp_period_label( int $count, string $unit ): string {
		$count = max( 1, $count );
		$unit  = rtrim( strtolower( trim( $unit ) ), 's' ); // 'months' -> 'month'

		switch ( $unit ) {
			case 'day':
				/* translators: %d: número de dias */
				$plural   = sprintf( __( '%d dias', 'jet-form-builder-mercadopago-gateway' ), $count );
				$singular = __( 'dia', 'jet-form-builder-mercadopago-gateway' );
				break;
			case 'week':
				/* translators: %d: número de semanas */
				$plural   = sprintf( __( '%d semanas', 'jet-form-builder-mercadopago-gateway' ), $count );
				$singular = __( 'semana', 'jet-form-builder-mercadopago-gateway' );
				break;
			case 'year':
				/* translators: %d: número de anos */
				$plural   = sprintf( __( '%d anos', 'jet-form-builder-mercadopago-gateway' ), $count );
				$singular = __( 'ano', 'jet-form-builder-mercadopago-gateway' );
				break;
			case 'month':
			default:
				/* translators: %d: número de meses */
				$plural   = sprintf( __( '%d meses', 'jet-form-builder-mercadopago-gateway' ), $count );
				$singular = __( 'mês', 'jet-form-builder-mercadopago-gateway' );
				break;
		}

		return ( 1 === $count ) ? $singular : $plural;
	}

}
