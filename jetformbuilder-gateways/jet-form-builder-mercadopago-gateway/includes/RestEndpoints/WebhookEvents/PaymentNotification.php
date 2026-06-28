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

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Payment;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Subscription_Refund_Closer;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_Form_Builder\Db_Queries\Exceptions\Sql_Exception;
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
		$payment = ( new Retrieve_Payment() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $data_id ) )
			->send_request();

		if ( isset( $payment['error'] ) ) {
			$code = $payment['error']['code'] ?? 0;

			WebhookConfig::log(
				'Payment lookup failed.',
				array(
					'data_id' => $data_id,
					'code'    => $code,
					'error'   => $payment['error']['message'] ?? '',
				)
			);

			// Erro TRANSITÓRIO (falha de conexão/transporte, ou 5xx do MP) -> 500
			// para o MP REENVIAR. Erro DEFINITIVO (404/401/400: id inexistente,
			// token de outra conta) -> 200, sem reenvio. Isso inclui o id FALSO
			// (123456) do Simulador do painel, que SEMPRE dá 404 na consulta.
			$is_transient = ( 'http_error' === $code ) || ( is_numeric( $code ) && (int) $code >= 500 );

			return $is_transient
				? new WP_REST_Response( array( 'message' => 'lookup failed (retry)' ), 500 )
				: self::ok( 'payment not found' );
		}

		$mp_status = (string) ( $payment['status'] ?? '' );

		// REFUND / chargeback: reconcilia o REFUNDED (estorno via admin OU feito
		// direto no painel do MP). Fonte de verdade do status.
		if ( in_array( $mp_status, array( 'refunded', 'partially_refunded', 'charged_back' ), true ) ) {
			return $this->reconcile_refund( $payment, $data_id );
		}

		$external_reference = (string) ( $payment['external_reference'] ?? '' );

		// COBRANÇA DE ASSINATURA: o MP entrega a cobrança da preapproval como evento
		// `payment` (confirmado em teste real), com external_reference = jfbmp-sub-<id>
		// (que setamos na preapproval). Roteamos para a ativação + registro da
		// assinatura, em vez da reconciliação de pay-now (que não acharia linha).
		if ( 0 === strpos( $external_reference, 'jfbmp-sub-' ) ) {
			return $this->handle_subscription_payment( $payment, $external_reference );
		}

		$row = $this->find_row( $external_reference );

		if ( null === $row ) {
			// Pagamento não é nosso (ou ainda sem external_reference) -> ignora.
			// Logado p/ diagnóstico: se um pagamento de assinatura cair aqui, o
			// external_reference revela por que (ex.: o MP não ecoou jfbmp-sub-<id>).
			WebhookConfig::log(
				'Pagamento sem record/assinatura correspondente.',
				array( 'external_reference' => $external_reference, 'mp_status' => $mp_status )
			);

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

		// Anti-fraude: o valor do MP precisa bater com o que gravamos. Divergência ->
		// NÃO confirma (linha fica CREATED) e AUDITA sempre (§11.2): é um alerta que o
		// dono precisa enxergar em produção (não só com WP_DEBUG), pois a cobrança fica
		// pendente em silêncio até reconciliação manual.
		if ( ! $this->amount_matches( $payment, $row ) ) {
			WebhookConfig::audit(
				'amount_mismatch',
				array(
					'data_id'  => $data_id,
					'valor_mp' => $payment['transaction_amount'] ?? null,
					'valor_db' => $row['amount_value'] ?? null,
				)
			);

			return self::ok( 'amount mismatch' );
		}

		$confirmed = $this->confirm( $payment, $row );

		return self::ok( $confirmed ? 'completed' : 'already completed' );
	}

	/**
	 * Cobrança de ASSINATURA chegando pelo tópico `payment`: ativa a assinatura
	 * (se pendente) + grava o Payment_Model + dispara o evento, via recorder
	 * compartilhado (mesmo usado pelo `subscription_authorized_payment`).
	 *
	 * @param array  $payment
	 * @param string $external_reference  jfbmp-sub-<id>
	 *
	 * @return WP_REST_Response
	 */
	private function handle_subscription_payment( array $payment, string $external_reference ): WP_REST_Response {
		$status = (string) ( $payment['status'] ?? '' );

		// Só cobrança APROVADA ativa/registra. (Recusada: no-op; o status da
		// assinatura, se vier, é tratado pelo tópico subscription_preapproval.)
		if ( 'approved' !== $status ) {
			return self::ok( 'subscription payment status ' . ( '' !== $status ? $status : 'unknown' ) );
		}

		$subscription = $this->find_subscription( $external_reference );

		if ( null === $subscription ) {
			WebhookConfig::log(
				'Cobrança de assinatura sem assinatura local correspondente.',
				array( 'external_reference' => $external_reference )
			);

			return self::ok( 'subscription not found' );
		}

		try {
			$result = ( new SubscriptionPaymentRecorder() )->record(
				$subscription,
				(string) ( $payment['id'] ?? '' ),
				(float) ( $payment['transaction_amount'] ?? 0 ),
				(string) ( $payment['currency_id'] ?? 'BRL' ),
				is_array( $payment['payer'] ?? null ) ? $payment['payer'] : array()
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Subscription payment persist failed.', array( 'error' => $e->getMessage() ) );

			// Erro local de gravação -> pedir reentrega.
			return new WP_REST_Response( array( 'message' => 'persist failed (retry)' ), 500 );
		}

		return self::ok( 'subscription ' . $result );
	}

	/**
	 * Localiza a assinatura local a partir do external_reference jfbmp-sub-<id>.
	 *
	 * @param string $external_reference
	 *
	 * @return array|null
	 */
	private function find_subscription( string $external_reference ) {
		if ( ! preg_match( '/^jfbmp-sub-(\d+)$/', $external_reference, $matches ) ) {
			return null;
		}

		try {
			$query = SubscriptionsView::find( array( 'id' => (int) $matches[1] ) )->query();
			$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

			if ( empty( $rows ) ) {
				return null;
			}

			return $query->view()->get_prepared_row( $rows[0] );
		} catch ( \Throwable $e ) {
			return null;
		}
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
	 * Reconcilia um estorno: marca REFUNDED (atômico) o pagamento. Acha a linha
	 * por external_reference (pay-now) OU por transaction_id == data_id
	 * (assinatura, onde o transaction_id já é o payment_id do MP).
	 *
	 * Se a cobrança é de assinatura, ENCERRA a assinatura (§12.4/§12.5, decisão do
	 * dono): cancela a preapproval no MP + marca CANCELLED, via Subscription_Refund_Closer.
	 *
	 * @param array  $payment
	 * @param string $data_id
	 *
	 * @return WP_REST_Response
	 */
	private function reconcile_refund( array $payment, string $data_id ): WP_REST_Response {
		$row = $this->find_row( (string) ( $payment['external_reference'] ?? '' ) );

		if ( null === $row ) {
			$row = $this->find_row_by_transaction( $data_id );
		}

		if ( null === $row ) {
			return self::ok( 'refund: no matching record' );
		}

		// Marca o Payment REFUNDED (transição atômica COMPLETED -> REFUNDED). Se já
		// estava REFUNDED (corrida/reentrega), NÃO retorna cedo: ainda tentamos
		// encerrar a assinatura (idempotente), cobrindo o caso de o cancel no MP ter
		// falhado numa entrega anterior — a reentrega do MP re-tenta até encerrar.
		if ( 'REFUNDED' !== ( $row['status'] ?? '' ) ) {
			try {
				( new Payment_Model() )->update(
					array( 'status' => 'REFUNDED' ),
					array(
						'id'     => $row['id'],
						'status' => 'COMPLETED',
					)
				);
			} catch ( Sql_Exception $exception ) {
				// Não estava COMPLETED (ex.: corrida com o admin) -> idempotente, segue.
			}
		}

		// DECISÃO DO DONO (§12.4/§12.5): estorno ENCERRA a assinatura. Se este
		// pagamento é uma cobrança de assinatura (external_reference jfbmp-sub-<id>),
		// cancela a preapproval no MP + marca a assinatura CANCELLED (idempotente).
		// Pay-now (outro external_reference) -> não entra aqui.
		$external_reference = (string) ( $payment['external_reference'] ?? '' );

		if ( 0 === strpos( $external_reference, 'jfbmp-sub-' ) ) {
			$subscription = $this->find_subscription( $external_reference );

			if ( null !== $subscription ) {
				Subscription_Refund_Closer::close( $subscription, WebhookConfig::access_token() );
			}
		}

		return self::ok( 'refunded' );
	}

	/**
	 * Acha a linha do pagamento pelo transaction_id (= payment_id do MP).
	 *
	 * @param string $transaction_id
	 *
	 * @return array|null
	 */
	private function find_row_by_transaction( string $transaction_id ) {
		if ( '' === $transaction_id ) {
			return null;
		}

		try {
			$row = Payment_With_Record_View::findOne(
				array( 'transaction_id' => $transaction_id )
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
	 * Efetiva o pagamento de forma ATÔMICA e idempotente, e — somente se ESTE
	 * caminho venceu a transição — enriquece o pagador e dispara a fulfillment.
	 *
	 * A transição CREATED -> COMPLETED é um UPDATE CONDICIONAL
	 * (`WHERE id = X AND status = 'CREATED'`). O core lança Sql_Exception quando
	 * 0 linhas casam (i.e., o retorno do navegador — ou outra entrega do webhook
	 * — já confirmou). Assim, o `do_action` (e logo o Gateway_Success_Event via
	 * PaymentFulfillment) dispara NO MÁXIMO UMA VEZ por pagamento, fechando a
	 * janela de corrida entre webhook e retorno do navegador (TOCTOU).
	 *
	 * @param array $payment
	 * @param array $row
	 *
	 * @return bool true se este caminho efetivou o pagamento; false se já estava.
	 */
	private function confirm( array $payment, array $row ): bool {
		try {
			( new Payment_Model() )->update(
				array( 'status' => 'COMPLETED' ),
				array(
					'id'     => $row['id'],
					'status' => 'CREATED',
				)
			);
		} catch ( Sql_Exception $e ) {
			// 0 linhas afetadas => outro caminho já confirmou. No-op idempotente.
			return false;
		}

		$this->save_payer( $payment, $row );

		/**
		 * Pagamento confirmado VIA WEBHOOK (aba fechada; Pix/assíncrono na fase
		 * futura). PaymentFulfillment escuta este hook e roda as ações do form
		 * (Gateway_Success_Event) fora do contexto de submissão. Disparado só
		 * pelo vencedor da transição atômica acima => no-máximo-uma-vez.
		 *
		 * @param array $payment Objeto do pagamento (GET /v1/payments/{id}).
		 * @param array $row     Linha do Payment_With_Record_View (pagamento+record).
		 */
		do_action( 'jet-form-builder/mercadopago/payment-approved', $payment, $row );

		return true;
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
