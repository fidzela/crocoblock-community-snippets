<?php
/**
 * ============================================================================
 *  PreapprovalNotification  —  Tópico `subscription_preapproval` do webhook
 * ============================================================================
 *
 *  Dirige o STATUS da assinatura (espelha, juntos, os handlers do Stripe
 *  checkout.session.completed + customer.subscription.updated +
 *  customer.subscription.deleted — o MP consolida tudo num tópico só).
 *
 *  O corpo traz data.id (= id da PREAPPROVAL). SEMPRE consultamos
 *  GET /preapproval/{id} (fonte de verdade) antes de agir. Reconciliamos com a
 *  assinatura local por `billing_id` (gravado na criação).
 *
 *  Mapa de status MP -> evento do form (cada um com GUARD de transição, p/ não
 *  duplicar em reentregas do MP):
 *    authorized (vindo de SUSPENDED) -> reativação  -> SubscriptionReactivateEvent
 *    authorized (vindo de pendente)  -> ATIVA (o 1º Gateway_Success_Event sai no
 *                                       `subscription_authorized_payment`, igual
 *                                       ao invoice.paid do Stripe) -> sem evento
 *    paused                          -> SUSPENDED   -> SubscriptionSuspendedEvent
 *    cancelled                       -> CANCELLED   -> SubscriptionCancelEvent
 *    (qualquer status, se a assinatura LOCAL já está TERMINAL)
 *                                    -> IGNORADO (§5.2/§11.3: SubscriptionStatusGuard
 *                                       não reage em CANCELLED/EXPIRED/REFUNDED)
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Preapproval;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionCancelEvent;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionReactivateEvent;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionSuspendedEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Paypal\DbModels\RecurringCyclesModel;
use Jet_FB_Paypal\Logic\SubscribeNow;
use Jet_FB_Paypal\QueryViews\SubscriptionsView;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Model;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PreapprovalNotification {

	/**
	 * @param string $data_id Id da preapproval (data.id do webhook).
	 *
	 * @return WP_REST_Response
	 */
	public function handle( string $data_id ): WP_REST_Response {
		if ( '' === $data_id ) {
			return self::ok( 'no data.id' );
		}

		$token = WebhookConfig::access_token();

		if ( '' === $token ) {
			WebhookConfig::log( 'No access token; cannot verify preapproval.', array( 'data_id' => $data_id ) );

			return self::ok( 'no access token' );
		}

		$pre = ( new Retrieve_Preapproval() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $data_id ) )
			->send_request();

		if ( isset( $pre['error'] ) ) {
			$code = $pre['error']['code'] ?? 0;

			WebhookConfig::log(
				'Preapproval lookup failed.',
				array( 'data_id' => $data_id, 'code' => $code )
			);

			$is_transient = ( 'http_error' === $code ) || ( is_numeric( $code ) && (int) $code >= 500 );

			return $is_transient
				? new WP_REST_Response( array( 'message' => 'lookup failed (retry)' ), 500 )
				: self::ok( 'preapproval not found' );
		}

		$billing_id = (string) ( $pre['id'] ?? $data_id );
		$row        = $this->find_subscription( $billing_id );

		if ( null === $row ) {
			return self::ok( 'no matching subscription' );
		}

		$mp_status = (string) ( $pre['status'] ?? '' );

		try {
			$this->apply_status( $row, $mp_status, $pre );
		} catch ( \Throwable $e ) {
			WebhookConfig::log(
				'Preapproval status apply failed.',
				array( 'data_id' => $data_id, 'error' => $e->getMessage() )
			);

			// A assinatura existe no MP; não force reentrega por erro local.
			return self::ok( 'apply failed' );
		}

		// Re-sincroniza os TERMOS locais (valor/frequência/moeda) se mudaram no MP —
		// o RecurringCyclesModel local exibia os termos da CRIAÇÃO. Best-effort.
		$this->sync_terms( $row, $pre );

		return self::ok( 'status ' . ( '' !== $mp_status ? $mp_status : 'unknown' ) );
	}

	/**
	 * Re-sincroniza o ciclo recorrente LOCAL (RecurringCyclesModel, linha REGULAR)
	 * com o auto_recurring atual da preapproval. O ciclo local é só EXIBIÇÃO (admin);
	 * a cobrança real é sempre o que o MP aplica. Mantemos o local fiel quando o
	 * plano/termos mudam no MP. Idempotente: só escreve se algo mudou.
	 *
	 * @param array $row Assinatura local preparada (SubscriptionsView, com 'cycle').
	 * @param array $pre Preapproval do MP (GET /preapproval), com auto_recurring.
	 *
	 * @return void
	 */
	private function sync_terms( array $row, array $pre ) {
		$auto = $pre['auto_recurring'] ?? array();

		if ( empty( $auto ) || ! isset( $auto['transaction_amount'] ) ) {
			return;
		}

		$sub_id = (int) ( $row['id'] ?? 0 );

		if ( ! $sub_id ) {
			return;
		}

		$mp = array(
			'interval_unit'  => (string) ( $auto['frequency_type'] ?? 'months' ),
			'interval_count' => (int) ( $auto['frequency'] ?? 1 ),
			'currency'       => (string) ( $auto['currency_id'] ?? 'BRL' ),
			'amount'         => number_format( (float) $auto['transaction_amount'], 2, '.', '' ),
		);

		$local = is_array( $row['cycle'] ?? null ) ? $row['cycle'] : array();

		// Já idêntico? Nada a fazer (evita escrita e UPDATE de 0 linhas).
		if ( ! empty( $local['amount'] )
			&& (string) ( $local['interval_unit'] ?? '' ) === $mp['interval_unit']
			&& (int) ( $local['interval_count'] ?? 0 ) === $mp['interval_count']
			&& (string) ( $local['currency'] ?? '' ) === $mp['currency']
			&& number_format( (float) ( $local['amount'] ?? 0 ), 2, '.', '' ) === $mp['amount']
		) {
			return;
		}

		try {
			if ( empty( $local['amount'] ) ) {
				// Não havia ciclo local (criação best-effort falhou) -> cria a linha REGULAR.
				( new RecurringCyclesModel() )->insert(
					array_merge(
						array( 'subscription_id' => $sub_id, 'quantity' => 1, 'tenure_type' => 'REGULAR' ),
						$mp
					)
				);
			} else {
				( new RecurringCyclesModel() )->update(
					$mp,
					array( 'subscription_id' => $sub_id, 'tenure_type' => 'REGULAR' )
				);
			}

			WebhookConfig::audit(
				'subscription_terms_resynced',
				array( 'subscription_id' => $sub_id, 'amount' => $mp['amount'], 'currency' => $mp['currency'] )
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Re-sync dos termos falhou.', array( 'subscription_id' => $sub_id, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Aplica a transição de status + dispara o evento do form (guardado por
	 * transição, p/ rodar no máximo uma vez).
	 *
	 * @param array  $row
	 * @param string $mp_status
	 * @param array  $pre
	 *
	 * @return void
	 */
	private function apply_status( array $row, string $mp_status, array $pre ) {
		$current  = (string) ( $row['status'] ?? '' );
		$resource = new Subscription( $row );

		// GUARD §5.2/§11.3: assinatura local em estado TERMINAL (CANCELLED/EXPIRED/
		// REFUNDED) NÃO reage a NENHUMA transição de status do MP. Cobre tanto a
		// reentrega tardia de um `authorized` (não ressuscita) quanto o `cancelled`
		// que NÓS MESMOS provocamos ao encerrar a assinatura por estorno
		// (Subscription_Refund_Closer) — evita flipar status e re-disparar evento.
		if ( SubscriptionStatusGuard::is_terminal( $current ) ) {
			WebhookConfig::log(
				'Preapproval status para assinatura TERMINAL — ignorado.',
				array( 'subscription_id' => $row['id'] ?? 0, 'status' => $current, 'mp_status' => $mp_status )
			);

			return;
		}

		switch ( $mp_status ) {
			case 'authorized':
				$was_suspended = ( SubscribeNow::SUSPENDED === $current );

				$resource->set_active();
				$this->save_payer( $pre, $row );

				if ( $was_suspended ) {
					SubscriptionUtils::execute_event_for_subscription( $row['id'], SubscriptionReactivateEvent::class );
				}
				break;

			case 'paused':
				if ( SubscribeNow::SUSPENDED !== $current ) {
					$resource->set_suspended();
					SubscriptionUtils::execute_event_for_subscription( $row['id'], SubscriptionSuspendedEvent::class );
				}
				break;

			case 'cancelled':
				if ( SubscribeNow::CANCELLED !== $current ) {
					$resource->update_status_soft( SubscribeNow::CANCELLED );
					SubscriptionUtils::execute_event_for_subscription( $row['id'], SubscriptionCancelEvent::class );
				}
				break;

			// 'pending' (e desconhecidos): no-op.
		}
	}

	/**
	 * Localiza a assinatura local pelo billing_id (= id da preapproval).
	 *
	 * @param string $billing_id
	 *
	 * @return array|null
	 */
	private function find_subscription( string $billing_id ) {
		if ( '' === $billing_id ) {
			return null;
		}

		try {
			$query = SubscriptionsView::find( array( 'billing_id' => $billing_id ) )->query();
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
	 * Enriquecimento best-effort do pagador a partir da preapproval.
	 *
	 * @param array $pre
	 * @param array $row
	 *
	 * @return void
	 */
	private function save_payer( array $pre, array $row ) {
		$email = $pre['payer_email'] ?? '';

		if ( empty( $email ) ) {
			return;
		}

		try {
			Payer_Model::insert_or_update(
				array(
					'user_id'  => $row['user_id'] ?? 0,
					'payer_id' => (string) ( $pre['payer_id'] ?? '' ),
					'email'    => $email,
				)
			);
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Payer enrichment (subscription) failed.', array( 'error' => $e->getMessage() ) );
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
