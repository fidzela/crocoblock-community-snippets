<?php
/**
 * ============================================================================
 *  Locks  —  exclusão mútua entre REQUESTS via MySQL GET_LOCK
 * ============================================================================
 *
 *  POR QUE existe:
 *  ---------------------------------------------------------------------------
 *  Dois pontos do fluxo fazem "check-then-act" que precisa ser atômico ENTRE
 *  processos PHP distintos. O object cache/transient NÃO garante atomicidade, e
 *  o `transaction_id` do core NÃO é UNIQUE (ver QA-RESPOSTAS §8.1/§10.3), então
 *  só os checks de aplicação não bastam sob concorrência:
 *
 *    - SubscriptionPaymentRecorder::record(): a MESMA cobrança chega por 2 vias
 *      (tópicos `payment` e `subscription_authorized_payment`) e/ou é reentregue
 *      pelo MP. Na janela entre already_processed() e o insert, duas entregas
 *      simultâneas duplicariam o Payment_Model.
 *    - Subscription_Logic::after_actions() (double-submit): dois envios do form
 *      em paralelo criariam DUAS preapprovals REAIS no MP (§3.2).
 *
 *  GET_LOCK é por CONEXÃO: dois requests = duas conexões MySQL, logo o lock
 *  serializa de verdade entre eles. É liberado no RELEASE_LOCK ou quando a
 *  conexão fecha (fim do request) — nunca fica preso travando o site.
 *
 *  DEGRADAÇÃO SEGURA (importante): se o host não suportar GET_LOCK (a função
 *  devolve NULL), acquire() retorna null e o chamador SEGUE sem lock, caindo na
 *  proteção de aplicação que já existe. Falta de lock NUNCA bloqueia o fluxo.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Locks {

	/**
	 * Tenta adquirir um lock nomeado (espera até $timeout segundos).
	 *
	 * @param string $name    Nome lógico do lock (prefixado + hasheado internamente).
	 * @param int    $timeout Segundos a esperar pelo lock antes de desistir.
	 *
	 * @return bool|null  true  = adquirido (o chamador DEVE chamar release());
	 *                    false = não adquirido dentro do timeout (outro request o detém);
	 *                    null  = GET_LOCK indisponível no host -> siga SEM lock.
	 */
	public static function acquire( string $name, int $timeout = 5 ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::key( $name ), max( 0, $timeout ) )
		);

		// NULL = erro/sem suporte (ou o lock foi morto). Sinaliza "sem lock disponível"
		// para o chamador degradar com segurança (segue confiando nos checks de app).
		if ( null === $result ) {
			return null;
		}

		return '1' === (string) $result;
	}

	/**
	 * Libera um lock previamente adquirido. Seguro mesmo se não for o dono
	 * (RELEASE_LOCK de lock alheio/inexistente é no-op).
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public static function release( string $name ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::key( $name ) )
		);
	}

	/**
	 * Normaliza o nome do lock: prefixo do plugin + hash. O MySQL limita o nome do
	 * lock a 64 chars (5.7+); o hash garante o limite e evita colisão com outros
	 * apps que usem GET_LOCK no mesmo servidor.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private static function key( string $name ): string {
		return 'jfbmp_' . md5( $name );
	}
}
