<?php
/**
 * ============================================================================
 *  SubscriptionStatusGuard  —  estados TERMINAIS da assinatura
 * ============================================================================
 *
 *  Fonte única de verdade do que é um estado "terminal" (encerrado) de uma
 *  assinatura local. Usado pelos handlers de webhook para NÃO ressuscitar uma
 *  assinatura já encerrada quando chega um evento TARDIO/fora de ordem:
 *
 *    - SubscriptionPaymentRecorder::record()  -> cobrança aprovada tardia
 *    - PreapprovalNotification                -> status `authorized` reentregue
 *
 *  Cenário real (QA-RESPOSTAS §5.2 / §11.3): o MP pode reentregar um evento
 *  `authorized` antigo, ou cobrar uma preapproval que foi cancelada só
 *  localmente (§12.5) — e o `set_active()` reativaria a assinatura. Este guard
 *  impede isso.
 *
 *  POR QUE no NOSSO namespace (e não na lib Shared):
 *  ---------------------------------------------------------------------------
 *  As constantes vêm de Jet_FB_Paypal\Logic\SubscribeNow (lib compartilhada),
 *  mas a REGRA "não reativar terminal" é específica do fluxo MP. Mantida aqui
 *  para NÃO alterar a lib Shared, que via Loader roda também para o PayPal.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents;

use Jet_FB_Paypal\Logic\SubscribeNow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriptionStatusGuard {

	/**
	 * Estados terminais: a assinatura está encerrada e NÃO deve ser reativada por
	 * eventos tardios.
	 *   - CANCELLED: cancelada (admin ou MP).
	 *   - EXPIRED:   fim natural (end_date).
	 *   - REFUNDED:  estornada (dinheiro devolvido).
	 *
	 * @return string[]
	 */
	public static function terminal_statuses(): array {
		return array(
			SubscribeNow::CANCELLED,
			SubscribeNow::EXPIRED,
			SubscribeNow::REFUNDED,
		);
	}

	/**
	 * @param string $status Status atual da assinatura local.
	 *
	 * @return bool true se for um estado terminal (encerrado).
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::terminal_statuses(), true );
	}
}
