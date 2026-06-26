<?php
/**
 * ============================================================================
 *  trait Base_Mercadopago  —  Núcleo do gateway (id, nome, credencial, dinheiro)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/base-mercadopago.php
 *
 *  PRINCIPAIS MUDANÇAS vs. a versão renomeada do Stripe:
 *  ---------------------------------------------------------------------------
 *   1) get_price()           : NÃO multiplica por 100 (BRL é decimal real).
 *   2) get_formated_amount() : NÃO divide por 100.
 *   3) options_list()        : campo "secret" agora é rotulado "Access Token"
 *                              (o JS compilado já usa as chaves public/secret;
 *                              para não recompilar o Vue, mantemos as chaves e
 *                              só trocamos o rótulo). O usuário cola o
 *                              Access Token do Mercado Pago no campo "secret".
 *                              "public" fica opcional (não usado na fase 1).
 *   4) gateway_type          : só "Pay Now". "Subscription" só aparece se a
 *                              constante JFB_MP_SUBSCRIPTIONS_ENABLED === true
 *                              (mantém o cenário INERTE sem quebrar nada).
 *   5) required_credentials_fields(): declara ['secret'] (o Access Token),
 *                              para o badge de "credencial global válida".
 *
 *  POR QUE MANTER get_checkout_session()/get_currency()/get_name_payment():
 *  ---------------------------------------------------------------------------
 *  A camada de compatibilidade com o JetEngine Forms (legado) usa este trait e
 *  chama esses métodos. Mantemos sua assinatura para o trait continuar válido
 *  mesmo que aquela camada carregue. No fluxo do JetFormBuilder eles NÃO são
 *  usados (o JFB usa a classe Create_Checkout_Session).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic\Pay_Now_Logic;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic\Subscription_Logic;
use JFB_Modules\Gateways\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Base_Mercadopago {

	/**
	 * ID interno do gateway (slug). Usado em hooks, tab handler, ?jet_form_gateway=.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'mercadopago';
	}

	/**
	 * Nome exibido no radio "Gateways" do editor.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Mercado Pago Checkout', 'jet-form-builder' );
	}

	/**
	 * Campos do modal por-form (e validação). As chaves public/secret são
	 * mantidas por compatibilidade com o JS compilado; "secret" carrega o
	 * Access Token do Mercado Pago.
	 *
	 * @return array
	 */
	protected function options_list() {
		$scenarios = array(
			Pay_Now_Logic::scenario_id() => __( 'Pay Now', 'jet-form-builder-mercadopago-gateway' ),
		);

		// Subscription LIGADO por padrão (JFB_MP_SUBSCRIPTIONS_ENABLED). Defina a
		// constante como false no wp-config para tirar a opção do dropdown.
		if ( defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) && JFB_MP_SUBSCRIPTIONS_ENABLED ) {
			$scenarios[ Subscription_Logic::scenario_id() ] = __( 'Subscription', 'jet-form-builder-mercadopago-gateway' );
		}

		return array(
			'public'       => array(
				// Public Key do Mercado Pago. NÃO é usada no Checkout Pro (fase 1);
				// reservada para Pix/Bricks (fase 2). O segredo do webhook NÃO vai
				// aqui — ele é o JFB_MP_WEBHOOK_SECRET (wp-config), mais seguro.
				'label'    => __( 'Public Key (optional)', 'jet-form-builder' ),
				'required' => false,
			),
			'secret'       => array(
				// >>> Cole aqui o ACCESS TOKEN do Mercado Pago (TEST-... ou APP_USR-...).
				'label' => __( 'Access Token', 'jet-form-builder' ),
			),
			'currency'     => array(
				'label'    => __( 'Currency Code', 'jet-form-builder' ),
				'required' => false,
			),
			'use_global'   => array(
				'label'    => __( 'Use Global Settings', 'jet-form-builder' ),
				'required' => false,
			),
			'gateway_type' => array(
				'label'   => _x( 'Gateway Action', 'Mercadopago gateways editor data', 'jet-form-builder' ),
				'options' => $scenarios,
			),
		);
	}

	/**
	 * Declara qual(is) campo(s) de credencial é(são) obrigatório(s) para o
	 * gateway ser considerado "válido" globalmente. Para o Mercado Pago é o
	 * Access Token, armazenado na chave 'secret'.
	 *
	 * @return array
	 */
	public function required_credentials_fields(): array {
		return array( 'secret' );
	}

	/**
	 * Status que o core considera "falha".
	 *
	 * @return array
	 */
	protected function failed_statuses() {
		return array( 'rejected', 'cancelled' );
	}

	/**
	 * Valor monetário em BRL — decimal real, SEM multiplicar por 100.
	 * (Diferença crucial vs. Stripe.)
	 *
	 * @param mixed $price
	 *
	 * @return float
	 */
	protected function get_price( $price ) {
		return (float) $price;
	}

	/**
	 * Formata o valor para exibição (BRL, 2 casas), SEM dividir por 100.
	 *
	 * @param mixed $amount
	 *
	 * @return string
	 */
	public function get_formated_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Token único por pedido (base do external_reference / anti-replay).
	 *
	 * @param mixed $order_id
	 * @param mixed $form_id
	 *
	 * @return string
	 */
	protected function query_order_token( $order_id, $form_id ) {
		return $order_id . '-' . md5( $order_id . $form_id );
	}

	/* =========================================================================
	 *  Abaixo: métodos do caminho LEGADO (JetEngine Forms). Mantidos para o
	 *  trait permanecer válido naquela camada (inerte no fluxo do JFB).
	 * ========================================================================= */

	/**
	 * (Legado JetEngine) Mantido por compatibilidade. NÃO é usado no JFB.
	 *
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function get_checkout_session( $params ) {
		return $this->request(
			array_merge(
				array(
					'items' => array(
						array(
							'title'       => $this->get_name_payment(),
							'quantity'    => 1,
							'unit_price'  => isset( $this->price ) ? $this->price : 0,
							'currency_id' => $this->get_currency(),
						),
					),
				),
				$params
			)
		);
	}

	/**
	 * Métodos de pagamento (fase 1: só cartão). Filtrável.
	 *
	 * @return array
	 */
	public function get_payment_methods() {
		return apply_filters( 'jet-form-builder/mercadopago/payment-methods', array( 'card' ), $this );
	}

	/**
	 * Moeda atual (fallback BRL).
	 *
	 * @return string
	 */
	public function get_currency() {
		if ( isset( $this->gateways_meta['currency'] ) && '' !== $this->gateways_meta['currency'] ) {
			return $this->gateways_meta['currency'];
		}
		if ( isset( $this->gateways_meta[ $this->get_id() ]['currency'] )
			&& '' !== $this->gateways_meta[ $this->get_id() ]['currency'] ) {
			return $this->gateways_meta[ $this->get_id() ]['currency'];
		}

		return 'BRL';
	}

	/**
	 * Título padrão do pagamento.
	 *
	 * @return string
	 */
	public function get_name_payment() {
		return get_option( 'blogname' ) . ' ' . __( 'payment', 'jet-form-builder' );
	}

	/* ===================================================================== */

	/**
	 * ID do gateway (estático, para leitura de credenciais).
	 *
	 * @return string
	 */
	protected static function gateway_id(): string {
		return 'mercadopago';
	}

	/**
	 * Credenciais GLOBAIS (wp-admin → Settings → Payments Gateways).
	 *
	 * @return array
	 */
	public static function get_credentials(): array {
		return Module::instance()->get_global_settings( self::gateway_id() ) ?: array();
	}

	/**
	 * Credenciais resolvidas por formulário (por-form ou, se use_global, globais).
	 *
	 * @param int $form_id
	 *
	 * @return array
	 */
	public static function get_credentials_by_form( int $form_id ): array {
		if ( ! $form_id ) {
			return self::get_credentials();
		}

		$gateways = Module::instance()->get_form_gateways_by_id( $form_id );
		$creds    = $gateways[ self::gateway_id() ] ?? array();

		if ( empty( $creds ) || ! empty( $creds['use_global'] ) ) {
			return self::get_credentials();
		}

		return $creds;
	}
}