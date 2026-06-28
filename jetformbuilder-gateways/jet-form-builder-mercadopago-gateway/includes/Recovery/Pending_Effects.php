<?php
/**
 * ============================================================================
 *  Pending_Effects  —  flag "efeitos pendentes" num pagamento (Payment_Meta)
 * ============================================================================
 *
 *  PARA QUE: quando o pagamento é GRAVADO mas as AÇÕES do form
 *  (Gateway_Success_Event / RenewalPaymentEvent) FALHAM ou não rodam, o dinheiro
 *  fica registrado com o efeito perdido EM SILÊNCIO. Aqui marcamos o pagamento
 *  como "efeitos pendentes" — fica identificável (auditoria sempre-ligada +
 *  consultável) e reexecutável.
 *
 *  ONDE NASCE A FLAG:
 *    - SubscriptionPaymentRecorder: se o evento da cobrança de assinatura falha.
 *    - PaymentFulfillment (pay-now): se o Gateway_Success_Event falha.
 *
 *  REEXECUÇÃO É MANUAL/CONSCIENTE de propósito: as ações do form (e-mail, criar
 *  post, etc.) NÃO são necessariamente idempotentes — re-rodar TUDO poderia
 *  DUPLICAR as que já tinham dado certo. Por isso NÃO auto-reexecutamos no
 *  reconciliador; expomos rerun($payment_id) pelo hook
 *  `jet-form-builder/mercadopago/rerun-effects` para o dono/dev disparar
 *  conscientemente (snippet / WP-CLI).
 *
 *  CAPABILITY: usamos só insert/update (NUNCA delete) — Base_Db_Model::before_delete
 *  exige `manage_options`, ausente no contexto de cron/webhook. "Limpar" = mudar o
 *  meta_value para 'done'. Integra com a tabela payments_meta do CORE (zero tabela nova).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Recovery;

use Jet_FB_Mercadopago_Gateway\FormEvents\RenewalPaymentEvent;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;
use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookEvents\PaymentFulfillment;
use Jet_FB_Paypal\DbModels\SubscriptionToPaymentModel;
use Jet_FB_Paypal\QueryViews\PaymentsWithSales;
use Jet_FB_Paypal\Utils\SubscriptionUtils;
use Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Meta_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pending_Effects {

	const META_KEY = 'jfbmp_effects_pending';
	const PENDING  = 'pending';
	const DONE     = 'done';

	/**
	 * Expõe a reexecução manual via hook (chamado no bootstrap).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'jet-form-builder/mercadopago/rerun-effects', array( __CLASS__, 'rerun' ) );
	}

	/**
	 * Marca um pagamento como "efeitos pendentes". Idempotente.
	 *
	 * @param int    $payment_id
	 * @param string $reason  Rótulo curto (ex.: 'fulfillment_failed').
	 *
	 * @return void
	 */
	public static function mark( int $payment_id, string $reason ) {
		if ( $payment_id <= 0 ) {
			return;
		}

		try {
			$current = self::current_value( $payment_id );

			if ( self::PENDING === $current ) {
				return; // já marcado
			}

			if ( null === $current ) {
				// 1º insert também CRIA a tabela payments_meta se faltar (before_insert).
				( new Payment_Meta_Model() )->insert(
					array(
						'payment_id' => $payment_id,
						'meta_key'   => self::META_KEY,
						'meta_value' => self::PENDING,
					)
				);
			} else {
				( new Payment_Meta_Model() )->update(
					array( 'meta_value' => self::PENDING ),
					array( 'payment_id' => $payment_id, 'meta_key' => self::META_KEY )
				);
			}

			WebhookConfig::audit( 'effects_pending', array( 'payment_id' => $payment_id, 'reason' => $reason ) );
		} catch ( \Throwable $e ) {
			WebhookConfig::log( 'Pending_Effects::mark falhou.', array( 'payment_id' => $payment_id, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Resolve a flag (meta_value -> 'done'). Sem delete (capability).
	 *
	 * @param int $payment_id
	 *
	 * @return void
	 */
	public static function clear( int $payment_id ) {
		if ( $payment_id <= 0 || self::PENDING !== self::current_value( $payment_id ) ) {
			return;
		}

		try {
			( new Payment_Meta_Model() )->update(
				array( 'meta_value' => self::DONE ),
				array( 'payment_id' => $payment_id, 'meta_key' => self::META_KEY )
			);
		} catch ( \Throwable $e ) {
			// idempotente
		}
	}

	/**
	 * @param int $payment_id
	 *
	 * @return bool
	 */
	public static function is_pending( int $payment_id ): bool {
		return self::PENDING === self::current_value( $payment_id );
	}

	/**
	 * Ids dos pagamentos com efeitos pendentes (para diagnóstico/contagem).
	 *
	 * @param int $limit
	 *
	 * @return int[]
	 */
	public static function find_pending( int $limit = 50 ): array {
		global $wpdb;

		$table = Payment_Meta_Model::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payment_id FROM `$table` WHERE meta_key = %s AND meta_value = %s LIMIT %d",
				self::META_KEY,
				self::PENDING,
				$limit
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Re-executa as ações do form de um pagamento e, em sucesso, limpa a flag.
	 * CUIDADO: pode re-rodar ações que JÁ tinham rodado (não idempotentes) — é uma
	 * decisão consciente do operador.
	 *
	 * @param int $payment_id
	 *
	 * @return bool
	 */
	public static function rerun( int $payment_id ): bool {
		$payment_id = (int) $payment_id;

		if ( $payment_id <= 0 ) {
			return false;
		}

		try {
			$sub_id = self::subscription_id_for( $payment_id );

			if ( $sub_id > 0 ) {
				$event = self::is_renewal( $payment_id ) ? RenewalPaymentEvent::class : Gateway_Success_Event::class;
				SubscriptionUtils::execute_event_for_subscription( $sub_id, $event );
			} else {
				( new PaymentFulfillment() )->run( $payment_id );
			}

			self::clear( $payment_id );
			WebhookConfig::audit( 'effects_rerun_ok', array( 'payment_id' => $payment_id ) );

			return true;
		} catch ( \Throwable $e ) {
			WebhookConfig::audit( 'effects_rerun_failed', array( 'payment_id' => $payment_id, 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * @param int $payment_id
	 *
	 * @return string|null  'pending'/'done' ou null se não há linha (ou tabela ausente).
	 */
	private static function current_value( int $payment_id ) {
		global $wpdb;

		$table = Payment_Meta_Model::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM `$table` WHERE payment_id = %d AND meta_key = %s LIMIT 1",
				$payment_id,
				self::META_KEY
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * @param int $payment_id
	 *
	 * @return int subscription_id local (0 = pay-now, sem assinatura).
	 */
	private static function subscription_id_for( int $payment_id ): int {
		global $wpdb;

		$table = SubscriptionToPaymentModel::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT subscription_id FROM `$table` WHERE payment_id = %d LIMIT 1",
				$payment_id
			)
		);
	}

	/**
	 * @param int $payment_id
	 *
	 * @return bool
	 */
	private static function is_renewal( int $payment_id ): bool {
		global $wpdb;

		$table = Payment_Model::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$type = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT type FROM `$table` WHERE id = %d LIMIT 1",
				$payment_id
			)
		);

		return PaymentsWithSales::RENEW_TYPE === $type;
	}
}
