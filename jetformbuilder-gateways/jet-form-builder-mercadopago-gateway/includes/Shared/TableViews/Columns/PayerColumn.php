<?php


namespace Jet_FB_Paypal\TableViews\Columns;


use Jet_FB_Mercadopago_Gateway\Money;
use Jet_Form_Builder\Gateways\Table_Views\Columns\Payer_Column;

class PayerColumn extends Payer_Column {

	/**
	 * A coluna "Payer" do core mostra só o NOME do pagador (ship.full_name ->
	 * first+last) e cai em "Not attached" quando ambos estão vazios — o que acontece
	 * com test users ou quando o MP não devolve o nome, mesmo havendo e-mail
	 * vinculado. Para o gateway 'mercadopago', mostramos o E-MAIL como fallback
	 * (melhor que "Not attached"). Outros gateways seguem o parent original (isolado).
	 *
	 * @param array $record
	 *
	 * @return string
	 */
	public function get_value( array $record = array() ) {
		if ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) {
			$name = (string) ( $record['ship']['full_name'] ?? '' );

			if ( '' === trim( $name ) ) {
				$name = trim(
					( $record['payer']['first_name'] ?? '' ) . ' ' . ( $record['payer']['last_name'] ?? '' )
				);
			}

			if ( '' === $name && ! empty( $record['payer']['email'] ) ) {
				return (string) $record['payer']['email'];
			}
		}

		return parent::get_value( $record );
	}
}
