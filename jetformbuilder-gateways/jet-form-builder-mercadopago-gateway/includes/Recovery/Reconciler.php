<?php
/**
 * ============================================================================
 *  Reconciler  —  rede de segurança: reconcilia o DB local com o Mercado Pago
 * ============================================================================
 *
 *  POR QUE existe (QA §3.3/§7.4 + cenários de recuperação):
 *  ---------------------------------------------------------------------------
 *  O webhook é o canal primário, mas pode FALHAR de formas que a reentrega do MP
 *  não cobre: plugin desativado ALÉM da janela de retry do MP (que dura dias),
 *  rollback de backup do WP, ou um webhook que nunca chegou. Sem uma varredura,
 *  esses registros ficam PRESOS — uma assinatura APPROVAL_PENDING que no MP já
 *  está ativa; um pay-now CREATED que no MP já foi pago.
 *
 *  O QUE FAZ (idempotente — REUSA os handlers do webhook, não duplica lógica):
 *  ---------------------------------------------------------------------------
 *    - Assinaturas APPROVAL_PENDING (nossas) -> GET /preapproval e aplica o status
 *      (PreapprovalNotification) + busca a cobrança aprovada por external_reference
 *      e registra (PaymentNotification -> recorder).
 *    - Pay-now CREATED (nossos) -> busca o pagamento aprovado por external_reference
 *      e confirma (PaymentNotification -> CAS atômico).
 *  Como TUDO passa pelos handlers do webhook, herda TODA a idempotência
 *  (already_processed, lock, CAS): reprocessar o que já foi processado é no-op.
 *
 *  AGENDAMENTO: WP-Cron (sempre presente no WordPress — não dependemos do Action
 *  Scheduler estar inicializado). Intervalo `hourly` (filtrável). Desligável com
 *  `define( 'JFB_MP_RECONCILER_ENABLED', false )`.
 *
 *  ESCOPO/SEGURANÇA: só toca registros do gateway 'mercadopago' (as tabelas são
 *  COMPARTILHADAS com o PayPal). Um GRACE de 15min evita corrida com o webhook ao
 *  vivo; um cap por execução evita runs longas.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Recovery;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Search_Payments;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents\PaymentNotification;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents\PreapprovalNotification;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_Form_Builder\Gateways\Query_Views\Payment_With_Record_View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reconciler {

	const HOOK          = 'jfbmp_reconcile';
	const GRACE_MINUTES = 15;
	// Cap por execução: backstop contra timeout de cron num backlog (cada registro
	// faz 1-3 chamadas ao MP). Um backlog grande é drenado ao longo de várias runs.
	const MAX_PER_RUN   = 25;

	/**
	 * Registra o handler do cron e agenda a recorrência (uma vez). Chamado no
	 * bootstrap. Respeita a flag JFB_MP_RECONCILER_ENABLED.
	 *
	 * @return void
	 */
	public static function register() {
		if ( defined( 'JFB_MP_RECONCILER_ENABLED' ) && ! JFB_MP_RECONCILER_ENABLED ) {
			self::unregister();

			return;
		}

		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$interval = (string) apply_filters( 'jet-form-builder/mercadopago/reconciler-interval', 'hourly' );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, self::HOOK );
		}
	}

	/**
	 * Remove o agendamento (desativação do plugin / flag desligada).
	 *
	 * @return void
	 */
	public static function unregister() {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Execução do cron: varre os presos e reconcilia com o MP.
	 *
	 * @return void
	 */
	public static function run() {
		if ( '' === WebhookConfig::access_token() ) {
			WebhookConfig::audit( 'reconciler_skipped_no_token' );

			return;
		}

		$subs = self::reconcile_pending_subscriptions();
		$pays = self::reconcile_created_paynow();

		// Não auto-reexecutamos efeitos pendentes (ações do form podem não ser
		// idempotentes — ver Pending_Effects), mas surfamos a contagem para o dono
		// saber que há pagamentos aguardando reexecução manual.
		$pending = count( Pending_Effects::find_pending( 100 ) );

		WebhookConfig::audit(
			'reconciler_run',
			array(
				'subscriptions_checked' => $subs,
				'paynow_checked'        => $pays,
				'effects_pending'       => $pending,
			)
		);
	}

	/**
	 * Assinaturas APPROVAL_PENDING que podem já estar ativas/canceladas no MP.
	 *
	 * @return int Quantas foram reconciliadas nesta execução.
	 */
	private static function reconcile_pending_subscriptions(): int {
		$rows  = self::fetch( SubscriptionsView::class, 'APPROVAL_PENDING' );
		$count = 0;

		foreach ( $rows as $row ) {
			if ( $count >= self::MAX_PER_RUN ) {
				break;
			}

			// As tabelas são compartilhadas com o PayPal — só tocamos no MP.
			if ( 'mercadopago' !== strtolower( (string) ( $row['gateway_id'] ?? '' ) ) ) {
				continue;
			}

			if ( self::too_new( (string) ( $row['created_at'] ?? '' ) ) ) {
				continue;
			}

			$billing_id = (string) ( $row['billing_id'] ?? '' );
			$sub_id     = (int) ( $row['id'] ?? 0 );

			// Sem billing_id, a criação no MP falhou -> não há o que reconciliar (órfã).
			if ( '' === $billing_id || ! $sub_id ) {
				continue;
			}

			$count++;

			// 1) Status (ativa/cancela conforme o MP). Idempotente (guards de transição).
			try {
				( new PreapprovalNotification() )->handle( $billing_id );
			} catch ( \Throwable $e ) {
				WebhookConfig::audit( 'reconciler_sub_status_error', array( 'subscription_id' => $sub_id ) );
			}

			// 2) Cobrança aprovada que o tópico `payment` possa ter perdido.
			self::recover_charge( 'jfbmp-sub-' . $sub_id );
		}

		return $count;
	}

	/**
	 * Pay-now presos em CREATED que podem já estar pagos no MP.
	 *
	 * @return int Quantos foram reconciliados nesta execução.
	 */
	private static function reconcile_created_paynow(): int {
		$rows  = self::fetch( Payment_With_Record_View::class, 'CREATED' );
		$count = 0;

		foreach ( $rows as $row ) {
			if ( $count >= self::MAX_PER_RUN ) {
				break;
			}

			if ( 'mercadopago' !== strtolower( (string) ( $row['gateway_id'] ?? '' ) ) ) {
				continue;
			}

			if ( self::too_new( (string) ( $row['created_at'] ?? '' ) ) ) {
				continue;
			}

			$external_reference = (string) ( $row['initial_transaction_id'] ?? '' );

			if ( '' === $external_reference ) {
				continue;
			}

			$count++;

			self::recover_charge( $external_reference );
		}

		return $count;
	}

	/**
	 * Resolve o pagamento aprovado por external_reference e o entrega ao handler
	 * `payment` (mesmo caminho do webhook -> idempotente).
	 *
	 * @param string $external_reference
	 *
	 * @return void
	 */
	private static function recover_charge( string $external_reference ) {
		try {
			$payment_id = ( new Search_Payments() )
				->set_bearer_auth( WebhookConfig::access_token() )
				->set_external_reference( $external_reference )
				->find_approved_payment_id();

			if ( '' === $payment_id ) {
				return;
			}

			( new PaymentNotification() )->handle( $payment_id );
		} catch ( \Throwable $e ) {
			WebhookConfig::audit( 'reconciler_charge_error', array( 'external_reference' => $external_reference ) );
		}
	}

	/**
	 * Lê as linhas de um View por status (SQL bruto — só precisamos das colunas da
	 * tabela base: status/gateway_id/id/billing_id/initial_transaction_id/created_at).
	 *
	 * @param string $view_class FQN de um View_Base (SubscriptionsView / Payment_With_Record_View).
	 * @param string $status
	 *
	 * @return array
	 */
	private static function fetch( string $view_class, string $status ): array {
		try {
			$query = $view_class::find( array( 'status' => $status ) )->query();
			$rows  = $query->db()->get_results( $query->sql(), ARRAY_A );

			return is_array( $rows ) ? $rows : array();
		} catch ( \Throwable $e ) {
			WebhookConfig::audit(
				'reconciler_fetch_failed',
				array( 'view' => $view_class, 'status' => $status )
			);

			return array();
		}
	}

	/**
	 * Registro recente demais? Damos uma folga (GRACE) para o webhook ao vivo + os
	 * primeiros retries do MP agirem antes de gastarmos chamadas de API. NÃO é
	 * questão de correção (os handlers são idempotentes) — é só otimização.
	 *
	 * @param string $created_at TIMESTAMP do banco (UTC).
	 *
	 * @return bool
	 */
	private static function too_new( string $created_at ): bool {
		if ( '' === $created_at ) {
			return false;
		}

		$ts = strtotime( $created_at . ' UTC' );

		if ( false === $ts ) {
			return false;
		}

		return ( time() - $ts ) < ( self::GRACE_MINUTES * MINUTE_IN_SECONDS );
	}
}
