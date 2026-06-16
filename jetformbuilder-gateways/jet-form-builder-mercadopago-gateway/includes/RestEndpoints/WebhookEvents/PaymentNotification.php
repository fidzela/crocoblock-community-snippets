<?php
/**
 * ============================================================================
 *  PaymentNotification  —  Handler do tópico `payment` do webhook
 * ============================================================================
 *
 *  O corpo do webhook traz apenas data.id. SEMPRE consultamos
 *  GET /v1/payments/{id} (fonte de verdade) antes de confirmar qualquer coisa.
 *
 *  RECONCILIAÇÃO (Checkout Pro):
 *  ---------------------------------------------------------------------------
 *  O webhook entrega o id do PAGAMENTO, mas o pay-now gravou a linha com
 *  transaction_id = id da PREFERENCE. A ponte é o external_reference, que
 *  persistimos em initial_transaction_id (ver Pay_Now_Logic::save_resource).
 *  O MP devolve external_reference ecoado tanto na preference quanto no
 *  objeto do pagamento, então: GET payment -> external_reference -> linha.
 *
 *  IDEMPOTÊNCIA:
 *  ---------------------------------------------------------------------------
 *  Só agimos na transição CREATED -> COMPLETED. Se a linha já está COMPLETED
 *  (ex.: o retorno do navegador confirmou primeiro), o webhook vira no-op.
 *
 *  ANTI-FRAUDE:
 *  ---------------------------------------------------------------------------
 *  Conferimos transaction_amount (MP) vs amount_value (DB) antes de confirmar,
 *  recusando divergências > 0.01 (defesa contra data.id forjado de 3os).
 *
 *  RESPOSTA:
 *  ---------------------------------------------------------------------------
 *  200 para tudo que foi tratado/ignorado (evita reentrega desnecessária do MP);
 *  500 apenas em erro transitório de API (aí queremos que o MP reenvie).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Checkout_Session;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
use Jet_Form_Builder\Gateways\Query_Views\Payment_With_Record_View;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentNotification {

	/**
	 * @param string $data_id Id do pagamento (data.id do webhook).
	 *
	 * @return WP_REST_Response
	 */
	public function handle( string $data_id ): WP_REST_Response {
		if ( '' === $data_id ) {
			return self::ok( 'no data.id' );
		}

		$token = WebhookConfig::access_token();

		if ( '' === $token ) {
			WebhookConfig::log(
				'Access token not configured; cannot verify payment.',
				array( 'data_id' => $data_id )
			);

			// Sem token não há como verificar; 200 evita retentativas sem solução.
			return self::ok( 'no access token' );
		}

		// Fonte de verdade: consulta autenticada do pagamento.
		$payment = ( new Retrieve_Checkout_Session() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $data_id ) )
			->send_request();

		if ( isset( $payment['error'] ) ) {
			WebhookConfig::log(
				'Payment lookup failed.',
				array(
					'data_id' => $data_id,
					'error'   => $payment['error']['message'] ?? '',
				)
			);

			// Erro transitório de API -> 500 para o MP reenviar.
			return new WP_REST_Response( array( 'message' => 'lookup failed' ), 500 );
		}

		$row = $this->find_row( (string) ( $payment['external_reference'] ?? '' ) );

		if ( null === $row ) {
			// Pagamento não é nosso (ou ainda sem external_reference) -> ignora.
			return self::ok( 'no matching record' );
		}

		// Idempotência: já finalizado em outro caminho.
		if ( 'COMPLETED' === ( $row['status'] ?? '' ) ) {
			return self::ok( 'already completed' );
		}

		$status = (string) ( $payment['status'] ?? '' );

		// Só confirmamos pagamento APROVADO, e somente a partir de CREATED.
		if ( 'approved' !== $status || 'CREATED' !== ( $row['status'] ?? '' ) ) {
			return self::ok( 'status ' . ( '' !== $status ? $status : 'unknown' ) );
		}

		// Anti-fraude: o valor do MP precisa bater com o que gravamos.
		if ( ! $this->amount_matches( $payment, $row ) ) {
			WebhookConfig::log(
				'Amount mismatch; refusing to confirm.',
				array(
					'data_id'  => $data_id,
					'valor_mp' => $payment['transaction_amount'] ?? null,
					'valor_db' => $row['amount_value'] ?? null,
				)
			);

			return self::ok( 'amount mismatch' );
		}

		$this->confirm( $payment, $row );

		return self::ok( 'completed' );
	}

	/**
	 * Localiza a linha do pagamento pelo external_reference
	 * (persistido em initial_transaction_id).
	 *
	 * @param string $external_reference
	 *
	 * @return array|null
	 */
	private function find_row( string $external_reference ) {
		if ( '' === $external_reference ) {
			return null;
		}

		try {
			$row = Payment_With_Record_View::findOne(
				array( 'initial_transaction_id' => $external_reference )
			)->query()->query_one();
		} catch ( \Throwable $e ) {
			return null;
		}

		return ! empty( $row ) ? $row : null;
	}

	/**
	 * Cross-check de valor (anti-fraude). Se algum lado não for comparável,
	 * não bloqueia (o GET autenticado já é a fonte de verdade).
	 *
	 * @param array $payment
	 * @param array $row
	 *
	 * @return bool
	 */
	private function amount_matches( array $payment, array $row ): bool {
		$mp = isset( $payment['transaction_amount'] ) ? round( (float) $payment['transaction_amount'], 2 ) : null;
		$db = isset( $row['amount_value'] ) ? round( (float) $row['amount_value'], 2 ) : null;

		if ( null === $mp || null === $db ) {
			return true;
		}

		return abs( $mp - $db ) <= 0.01;
	}

	/**
	 * Marca COMPLETED (idempotente), enriquece o pagador e dispara o hook de
	 * extensão para a fulfillment via webhook.
	 *
	 * @param array $payment
	 * @param array $row
	 *
	 * @return void
	 */
	private function confirm( array $payment, array $row ) {
		( new Payment_Model() )->update(
			array( 'status' => 'COMPLETED' ),
			array( 'id' => $row['id'] )
		);

		$this->save_payer( $payment, $row );

		/**
		 * Pagamento confirmado VIA WEBHOOK (aba fechada agora; Pix/boleto na
		 * fase 2). Ponto de plugue para o PASSO 2 disparar o Gateway_Success_Event
		 * e rodar as ações pós-pagamento do form fora do contexto de submissão.
		 *
		 * @param array $payment Objeto do pagamento (GET /v1/payments/{id}).
		 * @param array $row     Linha do Payment_With_Record_View (pagamento+record).
		 */
		do_action( 'jet-form-builder/mercadopago/payment-approved', $payment, $row );
	}

	/**
	 * Enriquecimento best-effort com os dados do pagador.
	 *
	 * @param array $payment
	 * @param array $row
	 *
	 * @return void
	 */
	private function save_payer( array $payment, array $row ) {
		$payer = $payment['payer'] ?? array();

		if ( empty( $payer['email'] ) ) {
			return;
		}

		try {
			Payer_Model::insert_or_update(
				array(
					'user_id'    => $row['user_id'] ?? 0,
					'payer_id'   => (string) ( $payer['id'] ?? '' ),
					'first_name' => $payer['first_name'] ?? null,
					'last_name'  => $payer['last_name'] ?? null,
					'email'      => $payer['email'],
				)
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Payer enrichment failed.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * @param string $message
	 *
	 * @return WP_REST_Response
	 */
	private static function ok( string $message ): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => $message ), 200 );
	}
}
