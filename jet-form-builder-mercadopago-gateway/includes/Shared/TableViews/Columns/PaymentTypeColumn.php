<?php


namespace Jet_FB_Paypal\TableViews\Columns;


use Jet_FB_Mercadopago_Gateway\Money;
use Jet_FB_Paypal\Utils\PaymentUtils;
use Jet_Form_Builder\Gateways\Table_Views\Columns\Payment_Type_Column;

class PaymentTypeColumn extends Payment_Type_Column {

	public function get_type_name( array $record ): string {
		if ( PaymentUtils::is_renewal( $record ) ) {
			return __( 'Renewal payment', 'jet-form-builder' );
		}

		// MERCADOPAGO: decidimos initial/renewal SÓ pelo `type` do registro (já
		// tratado acima por is_renewal). O parent (core) usa `initial_transaction_id`
		// como heurística de "renovação" — mas no pay-now NÓS reaproveitamos esse
		// campo para guardar o external_reference (chave de reconciliação do webhook),
		// então ele fica SEMPRE preenchido e o parent rotularia TODO pay-now como
		// "Renewal payment" (bug). Como aqui o `type` não é 'renew', é INICIAL.
		// Isolado ao gateway: PayPal/Stripe seguem o parent original (não quebra nada).
		if ( class_exists( Money::class ) && Money::is_mercadopago( $record ) ) {
			return __( 'Initial payment', 'jet-form-builder' );
		}

		return parent::get_type_name( $record );
	}

}