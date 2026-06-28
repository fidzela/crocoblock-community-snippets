<?php
/**
 * ============================================================================
 *  Payment_Methods_Config — meios de pagamento aceitos POR FORMULÁRIO (Pay Now)
 * ============================================================================
 *
 *  PARA QUE: no Checkout Pro (pay-now), a preference pode EXCLUIR tipos de
 *  pagamento (`payment_methods.excluded_payment_types`). Esta classe guarda, POR
 *  FORMULÁRIO, quais tipos ficam EXCLUÍDOS e injeta isso no ponto que JÁ existe —
 *  o filtro `jet-form-builder/mercadopago/excluded-payment-types` disparado pelo
 *  Create_Preference. Assim **NÃO tocamos** no create-preference.php.
 *
 *  🔒 ISOLAMENTO DAS CREDENCIAIS (crítico): a config fica numa OPTION PRÓPRIA
 *  (`jfb_mp_excluded_types`, mapa form_id -> [tipos]), NUNCA no blob de credenciais
 *  do gateway. Por isso é lida por `form_id` INDEPENDENTE do "Use Global Settings"
 *  (que faz o JFB ignorar o blob por-form). As credenciais seguem globais e intactas
 *  — a API de Planos, que depende delas, não é afetada.
 *
 *  ESCOPO: só PAY NOW (Preference). Subscription = cartão (Preapproval) — não afeta.
 *  SEM config para um form = comportamento ATUAL (default = só cartão).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payment_Methods_Config {

	/**
	 * Option que guarda o mapa form_id -> [tipos excluídos]. Isolada das credenciais.
	 */
	const OPTION = 'jfb_mp_excluded_types';

	/**
	 * Tipos de pagamento canônicos do MP (Brasil/Checkout Pro). Usado só para
	 * VALIDAÇÃO do que pode ser salvo. Os rótulos amigáveis vivem na UI (vêm do SYNC).
	 */
	const TYPE_IDS = array(
		'credit_card',
		'debit_card',
		'ticket',
		'bank_transfer',
		'atm',
		'account_money',
		'prepaid_card',
	);

	/**
	 * Liga o hook no filtro que o Create_Preference já dispara. Chamado no bootstrap.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter(
			'jet-form-builder/mercadopago/excluded-payment-types',
			array( __CLASS__, 'filter_excluded' ),
			10,
			2
		);
	}

	/**
	 * Hook do filtro: troca a lista de excluídos pela config do FORM atual (se houver).
	 * SEM config -> devolve o default que o create-preference montou (não mexe em nada).
	 *
	 * @param array $default Lista [ ['id'=>...], ... ] que o create-preference passou.
	 * @param mixed $action  A action Create_Preference (não usada; o form vem do handler).
	 *
	 * @return array
	 */
	public static function filter_excluded( $default, $action = null ): array {
		$default = is_array( $default ) ? $default : array();
		$form_id = self::current_form_id();

		if ( ! $form_id ) {
			return $default;
		}

		$excluded = self::get_excluded( $form_id );

		// null = sem config para este form -> mantém o default (comportamento atual).
		if ( null === $excluded ) {
			return $default;
		}

		// Shape do MP: [ ['id'=>'ticket'], ... ].
		return array_map(
			static function ( $type ) {
				return array( 'id' => $type );
			},
			$excluded
		);
	}

	/**
	 * Id do formulário em submissão (best-effort).
	 *
	 * @return int
	 */
	private static function current_form_id(): int {
		if ( ! function_exists( 'jet_fb_handler' ) ) {
			return 0;
		}

		$handler = jet_fb_handler();

		return ( $handler && isset( $handler->form_id ) ) ? (int) $handler->form_id : 0;
	}

	/**
	 * Tipos EXCLUÍDOS salvos para um form. null = SEM config (usar default).
	 *
	 * @param int $form_id
	 *
	 * @return array|null
	 */
	public static function get_excluded( int $form_id ) {
		if ( ! $form_id ) {
			return null;
		}

		$map = get_option( self::OPTION, array() );
		$key = (string) $form_id;

		if ( ! is_array( $map ) || ! array_key_exists( $key, $map ) ) {
			return null;
		}

		$list = is_array( $map[ $key ] ) ? $map[ $key ] : array();

		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/**
	 * Salva os tipos EXCLUÍDOS de um form (sanitizado contra a lista canônica).
	 *  - array []   -> form aceita TODOS os meios (nenhuma exclusão);
	 *  - array [..] -> exclui esses tipos;
	 *  - null       -> remove a config (volta ao DEFAULT atual = só cartão).
	 *
	 * @param int        $form_id
	 * @param array|null $excluded
	 *
	 * @return void
	 */
	public static function set_excluded( int $form_id, $excluded ) {
		if ( ! $form_id ) {
			return;
		}

		$map = get_option( self::OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		$key = (string) $form_id;

		if ( null === $excluded ) {
			unset( $map[ $key ] );
		} else {
			$map[ $key ] = array_values(
				array_intersect( array_map( 'strval', (array) $excluded ), self::TYPE_IDS )
			);
		}

		// autoload=false: config de admin, lida só no submit/edição.
		update_option( self::OPTION, $map, false );
	}
}
