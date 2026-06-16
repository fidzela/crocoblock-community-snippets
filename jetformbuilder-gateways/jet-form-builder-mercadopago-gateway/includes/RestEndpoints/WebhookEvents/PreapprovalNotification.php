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
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Preapproval;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionCancelEvent;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionReactivateEvent;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionSuspendedEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
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

		return self::ok( 'status ' . ( '' !== $mp_status ? $mp_status : 'unknown' ) );
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
