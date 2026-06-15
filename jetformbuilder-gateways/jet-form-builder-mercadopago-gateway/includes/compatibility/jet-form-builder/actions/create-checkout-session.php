<?php
/**
 * ============================================================================
 *  Create_Checkout_Session  —  Cria uma PREFERENCE do Mercado Pago (Checkout Pro)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/actions/create-checkout-session.php
 *
 *  IMPORTANTE — POR QUE O NOME DA CLASSE CONTINUA "Create_Checkout_Session":
 *  ---------------------------------------------------------------------------
 *  O pay-now-logic.php importa `...\Actions\Create_Checkout_Session`. Para você
 *  apenas COLAR POR CIMA (sem mexer em nenhum `use`/referência), mantemos o
 *  nome da classe e do arquivo. Só o COMPORTAMENTO muda: em vez de criar uma
 *  Checkout Session do Stripe (POST /v1/checkout/sessions), cria uma
 *  Preference do Mercado Pago (POST /checkout/preferences).
 *
 *  MAPA Stripe -> Mercado Pago:
 *    endpoint        v1/checkout/sessions      ->  checkout/preferences
 *    line_items      amount (centavos!) +      ->  items: title/quantity/
 *                    currency + name               unit_price (BRL decimal!) +
 *                                                   currency_id
 *    success/cancel  success_url / cancel_url  ->  back_urls.success/failure/pending
 *    (sem pending)                                 + auto_return:'approved'
 *    -                                         ->  binary_mode: true  (fase 1: só
 *                                                   cartão; recusa 'pending')
 *    metadata token  client_reference_id       ->  external_reference (anti-replay)
 *    métodos         payment_method_types:card ->  payment_methods.excluded_payment_types
 *                                                   (exclui Pix/boleto/ATM)
 *
 *  RESPOSTA: a API devolve { id, init_point, sandbox_init_point, ... }.
 *  O pay-now-logic decide o redirect entre init_point (produção) e
 *  sandbox_init_point (modo teste).
 *
 *  VALOR MONETÁRIO: BRL é decimal real (ex.: 24.90). NÃO multiplicar por 100
 *  (diferença crucial vs. Stripe). O valor chega via set_price() já no formato
 *  correto, pois o trait Base_Mercadopago::get_price() NÃO multiplica.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create_Checkout_Session extends Base_Action {

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
	 * No-ops mantidos por compatibilidade com o caminho de SUBSCRIPTION
	 * (inerte na fase 1). O subscription-logic do fork chama set_price_id()
	 * e set_mode(); preservamos os métodos para o arquivo carregar sem fatal,
	 * mesmo que o cenário esteja desligado.
	 *
	 * @param mixed $id
	 *
	 * @return static
	 */
	public function set_price_id( $id ) {
		return $this;
	}

	/**
	 * @param mixed $mode
	 *
	 * @return static
	 */
	public function set_mode( $mode ) {
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
			'binary_mode'        => true, // fase 1: cartão; recusa 'pending' automaticamente
			'external_reference' => $this->external_reference,
			'payment_methods'    => array(
				'excluded_payment_types' => $this->get_excluded_payment_types(),
				'installments'           => $this->installments,
			),
		);

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