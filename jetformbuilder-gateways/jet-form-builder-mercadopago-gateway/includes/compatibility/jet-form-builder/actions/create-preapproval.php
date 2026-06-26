<?php
/**
 * ============================================================================
 *  Create_Preapproval  —  Cria uma ASSINATURA do Mercado Pago (Preapproval)
 * ============================================================================
 *
 *  Espelha o Create_Checkout_Session do pay-now, mas para o cenário de
 *  ASSINATURA. Replica o modelo do Stripe (Checkout Session mode=subscription —
 *  o pagador informa o cartão na PÁGINA do gateway) usando o equivalente do
 *  Mercado Pago: uma Preapproval SEM plano, com `auto_recurring` INLINE, que
 *  devolve `init_point` (checkout hospedado do MP).
 *
 *  POR QUE SEM `preapproval_plan_id` (corrige "card_token_id is required"):
 *  ---------------------------------------------------------------------------
 *  No MP, criar `/preapproval` COM `preapproval_plan_id` e SEM `card_token_id` é
 *  o fluxo DIRETO -> o MP exige o card token (cartão tokenizado no SEU site via
 *  Bricks/MP.js). Isso NÃO é o fluxo de redirect. O fluxo de redirect (cartão na
 *  página do MP, igual ao Stripe Checkout) é a preapproval SEM plano, com
 *  `auto_recurring` inline -> devolve `init_point`, sem card token.
 *  O "plano" do nosso UI vira o TEMPLATE: o subscription-logic lê os termos do
 *  preapproval_plan (valor/frequência/moeda) e os envia inline aqui.
 *
 *  MAPA Stripe -> Mercado Pago:
 *    POST v1/checkout/sessions (mode=subscription)  ->  POST /preapproval
 *    line_items[].price (Price recorrente)          ->  auto_recurring (inline)
 *    session.url (redirect)                         ->  init_point (redirect)
 *    metadata.subscription_id                       ->  external_reference
 *
 *  IMPORTANTE (divergência do Stripe): a Preapproval do MP EXIGE `payer_email`
 *  no corpo (o Stripe coletava o e-mail no próprio checkout). Resolvemos isso no
 *  subscription-logic (campo do form / usuário logado / filtro).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions;

use Jet_FB_Mercadopago_Gateway\RestEndpoints\WebhookConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create_Preapproval extends Base_Action {

	protected $method = 'POST';

	protected $auto_recurring    = array();
	protected $payer_email       = '';
	protected $external_reference = '';
	protected $back_url           = '';
	protected $reason             = '';

	public function action_endpoint(): string {
		return 'preapproval';
	}

	/**
	 * Termos de cobrança inline (substitui o preapproval_plan_id). Espera:
	 * frequency, frequency_type, transaction_amount, currency_id.
	 *
	 * @param array $auto_recurring
	 *
	 * @return $this
	 */
	public function set_auto_recurring( array $auto_recurring ) {
		$this->auto_recurring = $auto_recurring;

		return $this;
	}

	public function set_payer_email( $email ) {
		$this->payer_email = (string) $email;

		return $this;
	}

	public function set_external_reference( $reference ) {
		$this->external_reference = (string) $reference;

		return $this;
	}

	public function set_back_url( $url ) {
		$this->back_url = (string) $url;

		return $this;
	}

	public function set_reason( $reason ) {
		$this->reason = (string) $reason;

		return $this;
	}

	protected function build_body(): array {
		// Preapproval SEM plano (auto_recurring inline) -> init_point sem card token.
		$body = array(
			'payer_email'        => $this->payer_email,
			'external_reference' => $this->external_reference,
			'back_url'           => $this->back_url,
			'status'             => 'pending',
		);

		if ( ! empty( $this->auto_recurring ) ) {
			$body['auto_recurring'] = $this->auto_recurring;
		}

		if ( '' !== $this->reason ) {
			$body['reason'] = $this->reason;
		}

		// Rede de segurança: notificação de status/cobrança. MP exige HTTPS público.
		$notification_url = WebhookConfig::notification_url();

		if ( '' !== $notification_url && 0 === strpos( $notification_url, 'https://' ) ) {
			$body['notification_url'] = $notification_url;
		}

		return apply_filters(
			'jet-form-builder/mercadopago/preapproval',
			$body,
			$this
		);
	}

	public function send_request(): array {
		if ( '' === $this->idempotency_key && '' !== $this->external_reference ) {
			$this->set_idempotency_key( 'jfbmp-sub-' . md5( $this->external_reference ) );
		}

		$this->set_body( $this->build_body() );

		return parent::send_request();
	}
}
