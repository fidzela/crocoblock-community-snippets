<?php
/**
 * ============================================================================
 *  Create_Preference  —  Cria uma PREFERENCE do Mercado Pago (Checkout Pro)
 * ============================================================================
 *
 *  Usada pelo cenário pay-now. Monta o corpo da Preference e faz
 *  POST /checkout/preferences. A resposta traz { id, init_point,
 *  sandbox_init_point, ... }; o pay-now-logic decide o redirect entre
 *  init_point (produção) e sandbox_init_point (modo teste, token TEST-).
 *
 *  Detalhes do corpo:
 *    - items: title / quantity / unit_price (BRL decimal, SEM *100) / currency_id;
 *    - back_urls.success/failure/pending + auto_return:'approved';
 *    - binary_mode:true (fase 1: só cartão; recusa 'pending');
 *    - external_reference (anti-replay / reconciliação do webhook);
 *    - payment_methods.excluded_payment_types (exclui Pix/boleto/ATM na fase 1).
 *
 *  VALOR MONETÁRIO: BRL é decimal real (ex.: 24.90). O valor chega via
 *  set_price() já correto (Base_Mercadopago::get_price() NÃO multiplica).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create_Preference extends Base_Action {

	/**
	 * Verbo HTTP: criação.
	 *
	 * @var string
	 */
	protected $method = 'POST';

	/**
	 * Valor do item em BRL (decimal real, sem *100).
	 *
	 * @var float
	 */
	protected $amount = 0.0;

	/**
	 * Código da moeda (fixo BRL no escopo Brasil).
	 *
	 * @var string
	 */
	protected $currency = 'BRL';

	/**
	 * URL de retorno em caso de pagamento aprovado.
	 *
	 * @var string
	 */
	protected $success_url = '';

	/**
	 * URL de retorno em caso de falha/cancelamento.
	 *
	 * @var string
	 */
	protected $failure_url = '';

	/**
	 * URL de retorno em caso de pagamento pendente.
	 * (Na fase 1, com binary_mode=true, raramente ocorre; mantemos por contrato.)
	 *
	 * @var string
	 */
	protected $pending_url = '';

	/**
	 * Referência externa única por submissão (vincula pagamento <-> registro,
	 * evita replay). Equivale ao query_order_token do Stripe.
	 *
	 * @var string
	 */
	protected $external_reference = '';

	/**
	 * Título do item exibido no checkout.
	 *
	 * @var string
	 */
	protected $item_title = '';

	/**
	 * Número máximo de parcelas oferecidas.
	 *
	 * @var int
	 */
	protected $installments = 12;

	/**
	 * Endpoint público do Checkout Pro (NÃO o interno /v1/asgard/preferences,
	 * que é first-party e exige x-platform-id/x-product-id registrados).
	 *
	 * @return string
	 */
	public function action_endpoint(): string {
		return 'checkout/preferences';
	}

	/**
	 * Define o valor do item em BRL (decimal real, SEM multiplicar por 100).
	 *
	 * @param mixed $amount
	 *
	 * @return static
	 */
	public function set_price( $amount ) {
		$this->amount = (float) $amount;

		return $this;
	}

	/**
	 * Define a moeda. Vazio -> BRL.
	 *
	 * @param mixed $currency
	 *
	 * @return static
	 */
	public function set_currency( $currency ) {
		$this->currency = $currency ? (string) $currency : 'BRL';

		return $this;
	}

	/**
	 * Define as URLs de retorno. O 3º parâmetro (pending) é específico do MP;
	 * se não informado, usa a URL de falha.
	 *
	 * A assinatura aceita 2 argumentos (como o Stripe chamava) e um 3º opcional,
	 * então o pay-now-logic continua compatível.
	 *
	 * @param string $success
	 * @param string $failure
	 * @param string $pending
	 *
	 * @return static
	 */
	public function set_urls( $success, $failure, $pending = '' ) {
		$this->success_url = (string) $success;
		$this->failure_url = (string) $failure;
		$this->pending_url = $pending ? (string) $pending : (string) $failure;

		return $this;
	}

	/**
	 * Define a referência externa (token único por submissão).
	 *
	 * @param string $reference
	 *
	 * @return static
	 */
	public function set_external_reference( $reference ) {
		$this->external_reference = (string) $reference;

		return $this;
	}

	/**
	 * Define o título do item.
	 *
	 * @param string $title
	 *
	 * @return static
	 */
	public function set_item_title( $title ) {
		$this->item_title = (string) $title;

		return $this;
	}

	/**
	 * Define o número máximo de parcelas.
	 *
	 * @param int $installments
	 *
	 * @return static
	 */
	public function set_installments( $installments ) {
		$this->installments = (int) $installments;

		return $this;
	}

	/**
	 * Tipos de pagamento EXCLUÍDOS na fase 1 (só cartão).
	 *  - ticket        = boleto
	 *  - bank_transfer = Pix
	 *  - atm           = caixa eletrônico
	 * Filtrável para a fase 2 (Pix) liberar esses tipos.
	 *
	 * @return array
	 */
	protected function get_excluded_payment_types(): array {
		$excluded = array(
			array( 'id' => 'ticket' ),
			array( 'id' => 'bank_transfer' ),
			array( 'id' => 'atm' ),
		);

		return apply_filters(
			'jet-form-builder/mercadopago/excluded-payment-types',
			$excluded,
			$this
		);
	}

	/**
	 * Monta o corpo da Preference (Checkout Pro).
	 *
	 * @return array
	 */
	protected function build_preference(): array {
		$title = '' !== $this->item_title
			? $this->item_title
			: get_option( 'blogname' ) . ' ' . __( 'payment', 'jet-form-builder-mercadopago-gateway' );

		$preference = array(
			'items'              => array(
				array(
					'title'       => $title,
					'quantity'    => 1,
					'unit_price'  => $this->amount, // BRL decimal — sem *100
					'currency_id' => $this->currency,
				),
			),
			'back_urls'          => array(
				'success' => $this->success_url,
				'failure' => $this->failure_url,
				'pending' => $this->pending_url,
			),
			'auto_return'        => 'approved',
			// binary_mode OFF por padrão (igual ao Stripe, que não tem esse
			// conceito). Com `true`, o MP RECUSA qualquer pagamento que passe por
			// "review"/in_process — e o checkout NOVO do MP joga até cartão de
			// teste APRO pra "review", derrubando o pagamento. Filtrável para
			// quem quiser o modo estrito (só aprovado-na-hora).
			'binary_mode'        => (bool) apply_filters( 'jet-form-builder/mercadopago/binary-mode', false, $this ),
			'external_reference' => $this->external_reference,
			'payment_methods'    => array(
				'excluded_payment_types' => $this->get_excluded_payment_types(),
				'installments'           => $this->installments,
			),
		);

		// Webhook (rede de segurança / fase 2 Pix). O MP exige HTTPS público;
		// em http/localhost OMITIMOS para NÃO quebrar a criação da preference.
		$notification_url = WebhookConfig::notification_url();

		if ( '' !== $notification_url && 0 === strpos( $notification_url, 'https://' ) ) {
			$preference['notification_url'] = $notification_url;
		}

		/**
		 * Permite ajustar a Preference inteira antes do envio
		 * (ex.: statement_descriptor, notification_url na fase 2, etc.).
		 */
		return apply_filters(
			'jet-form-builder/mercadopago/preference',
			$preference,
			$this
		);
	}

	/**
	 * Monta o corpo, garante a idempotência e envia.
	 *
	 * @return array
	 */
	public function send_request(): array {
		// Chave de idempotência estável por submissão (dedup em retry).
		if ( '' === $this->idempotency_key && '' !== $this->external_reference ) {
			$this->set_idempotency_key( 'jfbmp-' . md5( $this->external_reference ) );
		}

		$this->set_body( $this->build_preference() );

		return parent::send_request();
	}
}