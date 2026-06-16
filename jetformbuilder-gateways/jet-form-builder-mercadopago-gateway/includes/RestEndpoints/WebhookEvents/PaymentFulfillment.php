<?php
/**
 * ============================================================================
 *  PaymentFulfillment  —  Roda as ações do form a partir do WEBHOOK (pay-now)
 * ============================================================================
 *
 *  POR QUE ESTE ARQUIVO EXISTE (pendência da Fase 1 — D3 do HANDOFF):
 *  ---------------------------------------------------------------------------
 *  No pay-now, o `Gateway_Success_Event` (que dispara as AÇÕES do formulário:
 *  e-mail, criar post, registrar usuário, webhooks de terceiros, etc.) só era
 *  disparado no RETORNO DO NAVEGADOR (`Scenario_Logic_Base::on_catch()` ->
 *  `process_status('success')`). Se o pagador FECHA A ABA (ou paga por um meio
 *  assíncrono — Pix, na fase futura), o navegador nunca volta e as ações NUNCA
 *  rodavam, mesmo com o pagamento aprovado e a linha já `COMPLETED` via webhook.
 *
 *  Esta classe fecha esse buraco: escuta o hook
 *  `jet-form-builder/mercadopago/payment-approved` (disparado por
 *  PaymentNotification::confirm() APENAS quando o webhook é quem efetiva a
 *  transição CREATED -> COMPLETED) e RE-EXECUTA o `Gateway_Success_Event` FORA
 *  do contexto de submissão — exatamente como o core do JFB faz para assinaturas
 *  em `SubscriptionUtils::execute_event_for_subscription()`, só que indexado
 *  pelo PAGAMENTO (via a tabela `payment_to_record` -> `Record_By_Payment`).
 *
 *  EXACTLY-ONCE:
 *  ---------------------------------------------------------------------------
 *  A garantia de disparo único NÃO mora aqui — mora na transição ATÔMICA
 *  CREATED -> COMPLETED feita em PaymentNotification::confirm() (UPDATE
 *  condicional; só o "vencedor" da corrida emite o hook). Aqui apenas reagimos
 *  ao hook, que já chega no-máximo-uma-vez por pagamento.
 *
 *  COMO REIDRATAMOS O CONTEXTO (igual ao caminho de assinatura do core):
 *  ---------------------------------------------------------------------------
 *  1. acha o Form Record vinculado ao pagamento (Record_By_Payment);
 *  2. restaura o usuário (wp_set_current_user);
 *  3. seta o form_id no Live_Form / action_handler / handler + referrer;
 *  4. Tools::apply_context() recarrega os VALORES dos campos salvos do record;
 *  5. Gateway_Manager carrega as opções de gateway daquele form;
 *  6. jet_fb_events()->execute( Gateway_Success_Event ) roda as ações;
 *  7. Tools::update_record() persiste o resultado das ações no record.
 *
 *  PRÉ-REQUISITO: o cenário pay-now força `Save_Record::add_hidden()`, então
 *  SEMPRE há um record para reidratar. Sem record vinculado, viramos no-op.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event;
use Jet_Form_Builder\Gateways\Gateway_Manager;
use Jet_Form_Builder\Live_Form;
use JFB_Modules\Form_Record\Query_Views\Record_By_Payment;
use JFB_Modules\Form_Record\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentFulfillment {

	/**
	 * Registra o listener. Chamado uma vez no bootstrap (Manager::__construct).
	 *
	 * @return void
	 */
	public static function register() {
		add_action(
			'jet-form-builder/mercadopago/payment-approved',
			array( __CLASS__, 'on_payment_approved' ),
			10,
			2
		);
	}

	/**
	 * Handler do hook. `$row` é a linha do Payment_With_Record_View; `id` é o id
	 * interno do pagamento (chave para achar o record).
	 *
	 * @param array $payment Objeto do pagamento (GET /v1/payments/{id}).
	 * @param array $row     Linha pagamento+record.
	 *
	 * @return void
	 */
	public static function on_payment_approved( array $payment, array $row ) {
		$payment_id = (int) ( $row['id'] ?? 0 );

		if ( $payment_id <= 0 ) {
			return;
		}

		( new self() )->run( $payment_id );
	}

	/**
	 * Reidrata o contexto do form e dispara o Gateway_Success_Event.
	 *
	 * @param int $payment_id
	 *
	 * @return void
	 */
	public function run( int $payment_id ) {
		// Dependência do core (form-record). Se ausente (JFB antigo), no-op seguro.
		if ( ! class_exists( Record_By_Payment::class ) || ! class_exists( Tools::class ) ) {
			WebhookConfig::log(
				'Fulfillment skipped: JFB form-record module unavailable.',
				array( 'payment_id' => $payment_id )
			);

			return;
		}

		$record = $this->find_record( $payment_id );

		if ( empty( $record ) ) {
			// Form sem "Save Record" ou link ainda não gravado — nada a re-disparar.
			return;
		}

		$form_id   = (int) ( $record['form_id'] ?? 0 );
		$record_id = (int) ( $record['id'] ?? 0 );

		if ( $form_id <= 0 || $record_id <= 0 ) {
			return;
		}

		$this->restore_context( $record, $form_id );

		try {
			jet_fb_events()->execute( Gateway_Success_Event::class, $form_id );
			Tools::update_record( $record_id );
		} catch ( \Throwable $e ) {
			// O pagamento JÁ está COMPLETED; uma ação que falhe não pode 500 o
			// webhook (evita retentativas inúteis do MP). Logamos e seguimos.
			WebhookConfig::log(
				'Fulfillment: success event execution failed.',
				array(
					'payment_id' => $payment_id,
					'form_id'    => $form_id,
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Acha o Form Record vinculado ao pagamento (tabela payment_to_record).
	 *
	 * @param int $payment_id
	 *
	 * @return array
	 */
	private function find_record( int $payment_id ): array {
		try {
			$record = Record_By_Payment::findOne(
				array( 'payment_id' => $payment_id )
			)->query()->query_one();
		} catch ( \Throwable $e ) {
			WebhookConfig::log(
				'Fulfillment: record lookup failed.',
				array( 'payment_id' => $payment_id, 'error' => $e->getMessage() )
			);

			return array();
		}

		return ! empty( $record ) ? $record : array();
	}

	/**
	 * Reconstrói o contexto de execução do form fora da submissão original.
	 *
	 * @param array $record
	 * @param int   $form_id
	 *
	 * @return void
	 */
	private function restore_context( array $record, int $form_id ) {
		$user_id = (int) ( $record['user_id'] ?? 0 );

		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}

		Live_Form::instance()->set_form_id( $form_id );
		jet_fb_action_handler()->set_form_id( $form_id );
		jet_fb_handler()->set_referrer( (string) ( $record['referrer'] ?? '' ) );

		// Recarrega os valores dos campos salvos do record para o contexto.
		Tools::apply_context( $record );

		// Carrega as opções de gateway daquele form (necessário p/ macros como
		// %gateway_amount% e para o evento "enxergar" o gateway correto).
		Gateway_Manager::instance()->set_gateways_options_by_form_id( $form_id );
	}
}
