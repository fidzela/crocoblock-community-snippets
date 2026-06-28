<?php
/**
 * ============================================================================
 *  Subscription_Refund_Closer  —  ESTORNO ENCERRA A ASSINATURA (§12.4/§12.5)
 * ============================================================================
 *
 *  DECISÃO DO DONO: o estorno de uma cobrança ENCERRA a assinatura. Ponto único,
 *  idempotente, chamado pelos DOIS caminhos de refund:
 *    - admin  (refund-payment.php)          -> após estornar o pagamento no MP
 *    - webhook (PaymentNotification)        -> ao reconciliar um refund/chargeback
 *
 *  ORDEM (importa — evita a inconsistência §12.5):
 *    1) CANCELA a preapproval no MP (Update_Preapproval status=cancelled) — para o
 *       MP PARAR de cobrar. Isto é um GATE: se falhar, NÃO marcamos a assinatura
 *       local como encerrada (senão ficaria "encerrada aqui, cobrando lá").
 *    2) Marca a assinatura local CANCELLED + dispara SubscriptionCancelEvent.
 *       (O pagamento já foi/era marcado REFUNDED pelo chamador — é lá que fica a
 *       informação "estorno"; a assinatura fica CANCELLED, como em qualquer cancel.)
 *
 *  IDEMPOTÊNCIA:
 *    - Pagamento de PAY-NOW (sem billing_id) -> no-op ('no subscription').
 *    - Assinatura JÁ terminal -> no-op ('already terminal'). Combinado com o guard
 *      de terminal do PreapprovalNotification, o webhook `cancelled` que o MP devolve
 *      após o passo (1) NÃO re-dispara o evento.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Update_Preapproval;
use Jet_FB_Mercadopago_Gateway\FormEvents\SubscriptionCancelEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents\SubscriptionStatusGuard;
use Jet_FB_Paypal\Logic\SubscribeNow;
use Jet_FB_Paypal\Resources\Subscription;
use Jet_FB_Paypal\Utils\SubscriptionUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subscription_Refund_Closer {

	/**
	 * Encerra a assinatura por estorno (cancela no MP + marca local + evento).
	 *
	 * @param array  $subscription Linha da assinatura (precisa de id, billing_id, status).
	 * @param string $token        Access Token do MP (resolvido pelo chamador).
	 *
	 * @return string Mensagem de status (para log/resposta).
	 */
	public static function close( array $subscription, string $token ): string {
		$billing_id = (string) ( $subscription['billing_id'] ?? '' );
		$sub_id     = (int) ( $subscription['id'] ?? 0 );

		// Pay-now (sem assinatura) -> nada a encerrar.
		if ( '' === $billing_id || ! $sub_id ) {
			return 'no subscription';
		}

		// Idempotência: assinatura já encerrada -> não repete (nem o cancel no MP,
		// nem o evento). Cobre o webhook `cancelled` que nós mesmos provocamos.
		if ( SubscriptionStatusGuard::is_terminal( (string) ( $subscription['status'] ?? '' ) ) ) {
			return 'already terminal';
		}

		if ( '' === $token ) {
			WebhookConfig::audit( 'refund_close_no_token', array( 'subscription_id' => $sub_id ) );

			return 'no token';
		}

		// (1) GATE: cancela a preapproval no MP. Se falhar, NÃO marca local terminal
		// (evita §12.5). Auditado para o dono cancelar manualmente se necessário.
		$resp = ( new Update_Preapproval() )
			->set_bearer_auth( $token )
			->set_path( array( 'id' => $billing_id ) )
			->set_status( 'cancelled' )
			->send_request();

		if ( isset( $resp['error'] ) ) {
			WebhookConfig::audit(
				'refund_close_mp_cancel_failed',
				array(
					'subscription_id' => $sub_id,
					'billing_id'      => $billing_id,
					'message'         => $resp['error']['message'] ?? 'unknown',
				)
			);

			return 'mp cancel failed';
		}

		// (2) Marca local CANCELLED + evento de cancelamento.
		try {
			( new Subscription( $subscription ) )->update_status_soft( SubscribeNow::CANCELLED );
			SubscriptionUtils::execute_event_for_subscription( $sub_id, SubscriptionCancelEvent::class );
		} catch ( \Throwable $e ) {
			WebhookConfig::audit(
				'refund_close_local_failed',
				array( 'subscription_id' => $sub_id, 'error' => $e->getMessage() )
			);

			return 'local mark failed';
		}

		WebhookConfig::audit(
			'refund_closed_subscription',
			array( 'subscription_id' => $sub_id, 'billing_id' => $billing_id )
		);

		return 'closed';
	}
}
