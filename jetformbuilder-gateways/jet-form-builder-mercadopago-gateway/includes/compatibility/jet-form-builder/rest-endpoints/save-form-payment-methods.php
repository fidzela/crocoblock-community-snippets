<?php
/**
 * Save_Form_Payment_Methods — salva, POR FORMULÁRIO, os tipos de pagamento
 * EXCLUÍDOS no Checkout Pro (Pay Now). Grava na option isolada via
 * Payment_Methods_Config (NUNCA no blob de credenciais do gateway).
 *
 * Body: { form_id:int, excluded:[ "ticket","atm", ... ] }  (tipos a EXCLUIR).
 *   - excluded [] -> o form aceita TODOS os meios;
 *   - excluded null/ausente com clear=true -> remove a config (volta ao default).
 *
 * Guarda de segurança: não deixa excluir TODOS os tipos canônicos (checkout vazio).
 *
 * @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Rest_Endpoints;

use Jet_FB_Mercadopago_Gateway\Payment_Methods_Config;
use Jet_Form_Builder\Rest_Api\Rest_Api_Endpoint_Base;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Save_Form_Payment_Methods extends Rest_Api_Endpoint_Base {

	public static function get_rest_base() {
		return 'save-mercadopago-payment-methods';
	}

	public static function get_methods() {
		return \WP_REST_Server::CREATABLE;
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function run_callback( \WP_REST_Request $request ) {
		$in      = $request->get_json_params() ?: array();
		$form_id = (int) ( $in['form_id'] ?? 0 );

		if ( ! $form_id || 'jet-form-builder' !== get_post_type( $form_id ) ) {
			return new WP_Error(
				'mp_invalid_form',
				__( 'Formulário inválido.', 'jet-form-builder-mercadopago-gateway' ),
				array( 'status' => 400 )
			);
		}

		// clear=true remove a config do form (volta ao comportamento padrão).
		if ( filter_var( $in['clear'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			Payment_Methods_Config::set_excluded( $form_id, null );

			return rest_ensure_response( array( 'success' => true, 'cleared' => true ) );
		}

		$excluded = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $in['excluded'] ?? array() ) ),
				Payment_Methods_Config::TYPE_IDS
			)
		);

		// Nunca excluir TODOS os tipos canônicos -> checkout sem meios.
		if ( count( $excluded ) >= count( Payment_Methods_Config::TYPE_IDS ) ) {
			return new WP_Error(
				'mp_exclude_all',
				__( 'Você não pode excluir TODOS os meios de pagamento — mantenha pelo menos um ativo.', 'jet-form-builder-mercadopago-gateway' ),
				array( 'status' => 400 )
			);
		}

		Payment_Methods_Config::set_excluded( $form_id, $excluded );

		return rest_ensure_response( array( 'success' => true, 'excluded' => $excluded ) );
	}
}
