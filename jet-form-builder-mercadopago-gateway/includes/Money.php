<?php
/**
 * ============================================================================
 *  Money  —  formatação de valores POR MOEDA (somente EXIBIÇÃO)
 * ============================================================================
 *
 *  PROBLEMA QUE RESOLVE:
 *  ---------------------------------------------------------------------------
 *  As tabelas/telas herdam a formatação AMERICANA do core/lib PayPal
 *  (number_format(x, 2) = "1,000.00"). Para BRL o certo é "1.000,00". Esta classe
 *  formata por moeda (símbolo/decimais/separadores corretos).
 *
 *  REGRA DE OURO (segurança de dados — leia antes de usar):
 *  ---------------------------------------------------------------------------
 *   1. format() é SÓ PARA EXIBIR. O retorno é uma STRING "humana" (ex.: "1.000,00")
 *      e NUNCA pode voltar a ser parseada como número, gravada no banco, nem
 *      enviada a uma API. Os valores no banco são DECIMAL(10,2) com PONTO; campos
 *      de INPUT/ENVIO (refund amount, criação de plano, amount enviado ao MP) ficam
 *      SEMPRE com ponto. Misturar é o que quebraria: (float)"1.000,00" === 1.0.
 *   2. O input de format() vem SEMPRE como vem do banco/decimal (ponto). to_float()
 *      converte com segurança ("100.00" -> 100.0). O caso normal nunca recebe "100,00".
 *
 *  ISOLAMENTO (NÃO quebra PayPal/Stripe):
 *  ---------------------------------------------------------------------------
 *   - is_mercadopago($record) garante que a formatação por moeda SÓ roda para
 *     registros do gateway 'mercadopago'. Outros gateways seguem o formato original.
 *   - A lib Shared é compartilhada em runtime (Loader pega a versão mais alta). Com
 *     o plugin MP sozinho, a nossa cópia é a carregada e isto funciona. Se um dia
 *     outro gateway trouxer uma versão MAIOR da lib, a formatação MP simplesmente
 *     não aplica (degrada pro formato padrão) — nunca quebra.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Money {

	const DEFAULT_CURRENCY = 'BRL';

	/**
	 * Formato por moeda do Mercado Pago (uma por país onde o MP opera).
	 * decimals: CLP não usa centavos. dec/thou: separadores decimal/milhar.
	 */
	const FORMATS = array(
		'BRL' => array( 'symbol' => 'R$',  'decimals' => 2, 'dec' => ',', 'thou' => '.' ),
		'ARS' => array( 'symbol' => '$',   'decimals' => 2, 'dec' => ',', 'thou' => '.' ),
		'CLP' => array( 'symbol' => '$',   'decimals' => 0, 'dec' => ',', 'thou' => '.' ),
		'COP' => array( 'symbol' => '$',   'decimals' => 2, 'dec' => ',', 'thou' => '.' ),
		'MXN' => array( 'symbol' => '$',   'decimals' => 2, 'dec' => '.', 'thou' => ',' ),
		'PEN' => array( 'symbol' => 'S/',  'decimals' => 2, 'dec' => '.', 'thou' => ',' ),
		'UYU' => array( 'symbol' => '$U',  'decimals' => 2, 'dec' => ',', 'thou' => '.' ),
	);

	/**
	 * @param string $currency
	 *
	 * @return array
	 */
	public static function format_of( string $currency ): array {
		$code = strtoupper( trim( $currency ) );

		return self::FORMATS[ $code ] ?? self::FORMATS[ self::DEFAULT_CURRENCY ];
	}

	/**
	 * Formata um valor (DECIMAL do banco, com ponto) para EXIBIÇÃO na moeda.
	 * SOMENTE para mostrar — não re-parsear, não gravar, não enviar a API.
	 *
	 * @param mixed  $value       Valor numérico/decimal "100.00".
	 * @param string $currency    Código da moeda (BRL, ARS, ...).
	 * @param bool   $with_symbol Se true, prefixa o símbolo (R$).
	 *
	 * @return string
	 */
	public static function format( $value, string $currency, bool $with_symbol = true ): string {
		$fmt    = self::format_of( $currency );
		$number = number_format( self::to_float( $value ), (int) $fmt['decimals'], $fmt['dec'], $fmt['thou'] );

		return $with_symbol ? ( $fmt['symbol'] . ' ' . $number ) : $number;
	}

	/**
	 * Conversão SEGURA para float. O valor vem do banco como DECIMAL (ponto) — o
	 * caso normal cai no regex US e é direto. A defesa abaixo só age se, por engano,
	 * chegar uma string "humana" com separadores (não deveria): detecta o separador
	 * DECIMAL como o ÚLTIMO ('.' ou ',') e descarta os demais (milhar).
	 *
	 * @param mixed $value
	 *
	 * @return float
	 */
	public static function to_float( $value ): float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		$s = trim( (string) $value );

		if ( '' === $s ) {
			return 0.0;
		}

		// Caso normal (banco/US): "1234.56" ou "1234".
		if ( preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
			return (float) $s;
		}

		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );

		if ( false === $last_comma && false === $last_dot ) {
			return (float) preg_replace( '/[^\d-]/', '', $s );
		}

		$dec_pos  = max( false === $last_comma ? -1 : $last_comma, false === $last_dot ? -1 : $last_dot );
		$int_part = preg_replace( '/[^\d-]/', '', substr( $s, 0, $dec_pos ) );
		$frac     = preg_replace( '/\D/', '', substr( $s, $dec_pos + 1 ) );

		return (float) ( ( '' === $int_part ? '0' : $int_part ) . '.' . ( '' === $frac ? '0' : $frac ) );
	}

	/**
	 * A formatação por moeda só se aplica a registros do gateway 'mercadopago'.
	 *
	 * @param array $record Linha (payment/subscription) com gateway_id.
	 *
	 * @return bool
	 */
	public static function is_mercadopago( array $record ): bool {
		return 'mercadopago' === strtolower( (string) ( $record['gateway_id'] ?? '' ) );
	}
}
