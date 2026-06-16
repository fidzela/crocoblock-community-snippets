<?php
/**
 * ============================================================================
 *  Create_Preapproval  —  Cria uma ASSINATURA do Mercado Pago (Preapproval)
 * ============================================================================
 *
 *  Espelha o Create_Checkout_Session do pay-now, mas para o cenário de
 *  ASSINATURA. Replica o modelo do Stripe (Checkout Session mode=subscription
 *  com um Price recorrente pré-existente) usando o equivalente do Mercado Pago:
 *  uma Preapproval ASSOCIADA A UM PLANO (`preapproval_plan_id`).
 *
 *  MAPA Stripe -> Mercado Pago:
 *    POST v1/checkout/sessions (mode=subscription)  ->  POST /preapproval
 *    line_items[].price = <price_id>                ->  preapproval_plan_id = <plan id>
 *    session.url (redirect)                         ->  init_point (redirect)
 *    metadata.subscription_id                       ->  external_reference
 *
 *  IMPORTANTE (divergência do Stripe): a Preapproval do MP EXIGE `payer_email`
 *  no corpo (o Stripe coletava o e-mail no próprio checkout). Resolvemos isso no
 *  subscription-logic (campo do form / usuário logado / filtro).
 *
 *  Sem `card_token_id`, o MP devolve `init_point` para o pagador AUTORIZAR a
 *  assinatura — exatamente como o init_point do pay-now.
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

	protected $plan_id            = '';
	protected $payer_email        = '';
	protected $external_reference = '';
	protected $back_url           = '';
	protected $reason             = '';

	public function action_endpoint(): string {
		return 'preapproval';
	}

	public function set_plan_id( $id ) {
		$this->plan_id = (string) $id;

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
		$body = array(
			'preapproval_plan_id' => $this->plan_id,
			'payer_email'         => $this->payer_email,
			'external_reference'  => $this->external_reference,
			'back_url'            => $this->back_url,
			'status'              => 'pending',
		);

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
