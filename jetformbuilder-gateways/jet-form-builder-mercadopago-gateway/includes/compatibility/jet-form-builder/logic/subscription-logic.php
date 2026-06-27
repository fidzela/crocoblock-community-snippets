<?php
/**
 * ============================================================================
 *  Subscription_Logic  —  Cenário de ASSINATURA (Mercado Pago Preapproval)
 * ============================================================================
 *
 *  Replica o cenário de assinatura do addon Stripe (Checkout Session
 *  mode=subscription — cartão informado na PÁGINA do gateway), trocando a API
 *  pelo equivalente do Mercado Pago: uma **Preapproval SEM plano, com
 *  `auto_recurring` INLINE** (devolve `init_point` sem `card_token_id`). O plano
 *  (preapproval_plan) escolhido no editor é o TEMPLATE: lemos seus termos
 *  (valor/frequência/moeda) e os enviamos inline. (Por que não mandamos o
 *  `preapproval_plan_id`: o MP exigiria `card_token_id` — ver Create_Preapproval.)
 *
 *  FLUXO (espelha o Stripe):
 *    after_actions()
 *      1. create_subscription()  grava SubscriptionModel (APPROVAL_PENDING) +
 *                                RecurringCyclesModel (lendo os termos do plano)
 *      2. create_resource()      POST /preapproval (auto_recurring inline)
 *                                -> { id, init_point, status }
 *      3. save_resource()        grava billing_id = id da preapproval (o MP
 *                                devolve na hora — vantagem vs. Stripe, onde o id
 *                                só vinha pelo webhook checkout.session.completed)
 *      4. redirect               init_point (pagador autoriza a assinatura)
 *      5. Save_Record::add_hidden() + attach_record_id() (liga sub <-> record,
 *                                base para o webhook re-rodar as ações do form)
 *
 *  process_after()/process_status() ficam VAZIOS de propósito (igual ao Stripe):
 *  todo o estado e os eventos do form são dirigidos pelo WEBHOOK
 *  (`subscription_preapproval` -> status; `subscription_authorized_payment` ->
 *  Payment_Model + Gateway_Success_Event/RenewalPaymentEvent). O retorno do
 *  navegador apenas exibe a mensagem de sucesso.
 *
 *  DIVERGÊNCIA do Stripe: a Preapproval EXIGE `payer_email`. Resolvido em
 *  resolve_payer_email() (campo do form -> usuário logado -> filtro).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Create_Preapproval;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Preapproval_Plan;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Subscription_Connector;
use Jet_Form_Builder\Db_Queries\Exceptions\Sql_Exception;
use Jet_Form_Builder\Exceptions\Gateway_Exception;
use Jet_Form_Builder\Exceptions\Query_Builder_Exception;
use Jet_Form_Builder\Gateways\Paypal\Scenarios_Logic\With_Resource_It;
use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_Logic_Base;
use Jet_Form_Builder\Gateways\Base_Gateway;
use Jet_Form_Builder\Actions\Types\Save_Record;
use Jet_FB_Paypal\DbModels\RecurringCyclesModel;
use Jet_FB_Paypal\DbModels\SubscriptionModel;
use Jet_FB_Paypal\DbModels\SubscriptionToRecordModel;
use Jet_FB_Paypal\QueryViews\SubscriptionWithRecord;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subscription_Logic extends Scenario_Logic_Base implements With_Resource_It {

	use Subscription_Connector;

	const SUBSCRIPTION_QUERY_VAR = 'subscription_id';

	const APPROVAL_PENDING = 'APPROVAL_PENDING';
	const APPROVED         = 'APPROVED';
	const ACTIVE           = 'ACTIVE';
	const SUSPENDED        = 'SUSPENDED';
	const CANCELLED        = 'CANCELLED';
	const EXPIRED          = 'EXPIRED';
	const REFUNDED         = 'REFUNDED';

	protected $subscription_id;

	/**
	 * Plano (preapproval_plan) lido do MP, memoizado por submit.
	 *
	 * @var array|null
	 */
	protected $plan_data;

	/**
	 * Token do retorno: o id INTERNO da assinatura (anexado à back_url).
	 *
	 * @return string
	 */
	protected function query_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET[ self::SUBSCRIPTION_QUERY_VAR ] ?? '' ) );
	}

	/**
	 * Status (do nosso DB) considerados "falha" para a mensagem de retorno.
	 *
	 * @return array
	 */
	public function get_failed_statuses() {
		return array( self::CANCELLED, self::EXPIRED );
	}

	/**
	 * @throws Gateway_Exception
	 */
	public function after_actions() {
		$this->subscription_id = $this->create_subscription();

		// Obs.: se o MP recusar, a linha local fica como APPROVAL_PENDING. NÃO
		// apagamos aqui porque o submit roda no contexto do VISITANTE (sem
		// manage_options) e Base_Db_Model::before_delete() exige essa capability
		// -> daria "Not enough capabilities ... delete rows". Limpeza dessas
		// pendentes é pela tela admin de Subscriptions (lá há permissão).
		$preapproval = $this->create_resource();

		$this->save_resource( $preapproval );

		$this->add_context(
			array(
				'session_id' => $this->subscription_id,
			)
		);

		jet_fb_action_handler()->add_response(
			array( 'redirect' => $this->resolve_redirect_url( $preapproval ) )
		);

		Save_Record::add_hidden();

		add_action(
			'jet-form-builder/form-handler/after-send',
			array( $this, 'attach_record_id' )
		);
	}

	/**
	 * Assinatura é dirigida pelo webhook (igual ao Stripe). Sem ação no retorno.
	 */
	public function process_after() {
	}

	/**
	 * O Gateway_Success_Event NÃO dispara no retorno (e sim no 1º
	 * `subscription_authorized_payment` via webhook). Igual ao Stripe.
	 *
	 * @param string $type
	 */
	public function process_status( $type = 'success' ) {
	}

	/**
	 * Grava a assinatura local (APPROVAL_PENDING) + o ciclo recorrente (lido do
	 * plano no MP). Espelha o create_subscription() do Stripe.
	 *
	 * @return int
	 * @throws Gateway_Exception
	 */
	public function create_subscription() {
		try {
			$primary_id = ( new SubscriptionModel() )->insert(
				array(
					'billing_id' => '',
					'gateway_id' => jet_fb_gateway_current()->get_id(),
					'scenario'   => self::scenario_id(),
					'form_id'    => jet_fb_handler()->form_id,
					'user_id'    => get_current_user_id(),
					'status'     => self::APPROVAL_PENDING,
				)
			);

			$this->maybe_save_recurring_cycle( $primary_id );

		} catch ( Sql_Exception $exception ) {
			throw new Gateway_Exception( $exception->getMessage() );
		}

		return $primary_id;
	}

	/**
	 * Best-effort: lê o plano (GET /preapproval_plan/{id}) e grava o ciclo
	 * recorrente para exibição no CORE (admin). Falha aqui não impede a criação.
	 *
	 * @param int $subscription_id
	 *
	 * @return void
	 */
	protected function maybe_save_recurring_cycle( int $subscription_id ) {
		// 100% best-effort (só alimenta a exibição do ciclo no admin). Qualquer
		// falha aqui NÃO pode abortar a criação — a validação autoritativa do
		// plano acontece em create_resource() (o MP rejeita plano inválido).
		try {
			$auto = $this->fetch_plan_data()['auto_recurring'] ?? array();

			if ( empty( $auto ) ) {
				return;
			}

			( new RecurringCyclesModel() )->insert(
				array(
					'subscription_id' => $subscription_id,
					'quantity'        => 1,
					'interval_unit'   => $auto['frequency_type'] ?? 'months',
					'interval_count'  => (int) ( $auto['frequency'] ?? 1 ),
					'currency'        => $auto['currency_id'] ?? 'BRL',
					'amount'          => number_format( (float) ( $auto['transaction_amount'] ?? 0 ), 2, '.', '' ),
					// MAIÚSCULO de propósito: SubscriptionsView filtra `tenure_type = 'REGULAR'`
					// (QueryViews/SubscriptionsView.php). Em minúsculo a coluna "billing cycle"
					// da tabela Subscriptions sai vazia.
					'tenure_type'     => 'REGULAR',
				)
			);
		} catch ( \Throwable $exception ) {
			return;
		}
	}

	/**
	 * Cria a Preapproval no Mercado Pago (POST /preapproval).
	 *
	 * @return array
	 * @throws Gateway_Exception
	 */
	public function create_resource() {
		$controller = jet_fb_gateway_current();
		$secret     = (string) $controller->current_gateway( 'secret' );

		// Termos vêm do PLANO escolhido (template) e vão INLINE na preapproval —
		// é o que habilita o init_point sem card_token (ver Create_Preapproval).
		$auto_recurring = $this->resolve_auto_recurring();

		if ( empty( $auto_recurring ) ) {
			throw new Gateway_Exception(
				__( 'Plano de assinatura inválido ou sem termos de cobrança. Selecione um plano válido na ação "Subscription".', 'jet-form-builder-mercadopago-gateway' )
			);
		}

		$payer_email = $this->resolve_payer_email();

		$request = ( new Create_Preapproval() )
			->set_bearer_auth( $secret )
			->set_auto_recurring( $auto_recurring )
			->set_reason( $this->resolve_plan_reason() )
			->set_payer_email( $payer_email )
			->set_external_reference( $this->generate_external_reference() )
			->set_back_url( $this->get_referrer_url( Base_Gateway::SUCCESS_TYPE ) );

		do_action( 'jet-form-builder/gateways/before-create', $request );

		$preapproval = $request->send_request();

		if ( isset( $preapproval['error'] ) ) {
			// Diagnóstico do payer/token SÓ com WP_DEBUG (não vaza no Form Record em
			// produção). Ajuda a depurar "payer and collector must be real or test".
			$hint = '';

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$hint = sprintf(
					' [payer_email: %s | token: %s]',
					'' !== $payer_email ? $payer_email : '(vazio)',
					'' !== $secret ? substr( $secret, 0, 8 ) . '…' : '(vazio)'
				);
			}

			throw new Gateway_Exception( $preapproval['error']['message'] . $hint, $preapproval );
		}

		return $preapproval;
	}

	/**
	 * Lê o plano (preapproval_plan) escolhido — memoizado (1 GET por submit) e
	 * reaproveitado pelo ciclo recorrente. Só memoiza sucesso (falha = re-tenta).
	 *
	 * @return array
	 */
	protected function fetch_plan_data(): array {
		if ( is_array( $this->plan_data ) && ! empty( $this->plan_data ) ) {
			return $this->plan_data;
		}

		$plan_id = $this->get_plan_id();

		if ( '' === (string) $plan_id ) {
			return array();
		}

		try {
			$plan = ( new Retrieve_Preapproval_Plan() )
				->set_bearer_auth( jet_fb_gateway_current()->current_gateway( 'secret' ) )
				->set_path( array( 'id' => $plan_id ) )
				->send_request();

			if ( ! is_array( $plan ) || isset( $plan['error'] ) ) {
				return array();
			}

			return $this->plan_data = $plan;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * auto_recurring (inline) a partir dos termos do plano escolhido.
	 *
	 * @return array
	 */
	protected function resolve_auto_recurring(): array {
		$auto = $this->fetch_plan_data()['auto_recurring'] ?? array();

		if ( empty( $auto ) || ! isset( $auto['transaction_amount'] ) ) {
			return array();
		}

		return array(
			'frequency'          => (int) ( $auto['frequency'] ?? 1 ),
			'frequency_type'     => (string) ( $auto['frequency_type'] ?? 'months' ),
			'transaction_amount' => (float) $auto['transaction_amount'],
			'currency_id'        => (string) ( $auto['currency_id'] ?? 'BRL' ),
		);
	}

	/**
	 * Nome/descrição do plano para a `reason` da assinatura.
	 *
	 * @return string
	 */
	protected function resolve_plan_reason(): string {
		return (string) ( $this->fetch_plan_data()['reason'] ?? __( 'Subscription', 'jet-form-builder-mercadopago-gateway' ) );
	}


	/**
	 * Grava o id da Preapproval como billing_id (chave de reconciliação do
	 * webhook -> assinatura).
	 *
	 * @param array $preapproval
	 *
	 * @return void
	 */
	public function save_resource( $preapproval ) {
		if ( empty( $preapproval['id'] ) ) {
			return;
		}

		try {
			( new SubscriptionModel() )->update(
				array( 'billing_id' => (string) $preapproval['id'] ),
				array( 'id' => $this->subscription_id )
			);
		} catch ( Sql_Exception $exception ) {
			// segue: o webhook ainda reconcilia por external_reference se preciso.
			return;
		}
	}

	/**
	 * Redireciona o pagador para autorizar a assinatura.
	 *
	 * @param array $preapproval
	 *
	 * @return string
	 */
	protected function resolve_redirect_url( array $preapproval ): string {
		return $preapproval['init_point'] ?? '';
	}

	/**
	 * Id do plano: campo do form (plan_field) ou seleção manual (plan_manual).
	 *
	 * @return string
	 * @throws Gateway_Exception
	 */
	protected function get_plan_id() {
		return (string) $this->get_from_field_or_manual( 'plan_field', 'plan_manual' );
	}

	/**
	 * Resolve o e-mail do pagador (exigido pela Preapproval). Ordem:
	 *  1) primeiro valor do request que pareça e-mail (ex.: campo "email");
	 *  2) e-mail do usuário logado;
	 *  3) filtro de override.
	 *
	 * @return string
	 */
	protected function resolve_payer_email(): string {
		$email   = '';
		$request = jet_fb_action_handler()->request_data ?? array();

		if ( is_array( $request ) ) {
			// 1) Campos "de e-mail" mais prováveis (o dono costuma nomear assim).
			foreach ( array( 'payer_email', 'email', 'e_mail', 'mail', 'user_email' ) as $key ) {
				if ( ! empty( $request[ $key ] ) && is_email( (string) $request[ $key ] ) ) {
					$email = (string) $request[ $key ];
					break;
				}
			}

			// 2) Qualquer valor que pareça e-mail.
			if ( '' === $email ) {
				foreach ( $request as $value ) {
					if ( is_string( $value ) && is_email( $value ) ) {
						$email = $value;
						break;
					}
				}
			}
		}

		// 3) Usuário WP logado. CUIDADO no sandbox: se o submit for de um admin
		// logado, isto traz o e-mail REAL dele -> com token de TESTE o MP recusa
		// ("Both payer and collector must be real or test users"). Por isso o
		// campo de e-mail no form é o caminho recomendado.
		if ( '' === $email ) {
			$user  = wp_get_current_user();
			$email = ( $user && $user->user_email ) ? $user->user_email : '';
		}

		return (string) apply_filters(
			'jet-form-builder/mercadopago/payer-email',
			$email,
			$this
		);
	}

	/**
	 * Referência externa única por assinatura.
	 *
	 * @return string
	 */
	protected function generate_external_reference(): string {
		return 'jfbmp-sub-' . $this->subscription_id;
	}

	/**
	 * Resolve o valor de uma config do cenário a partir de um campo do form
	 * (option_field) ou de um valor manual (option_manual).
	 *
	 * @param string $option_field
	 * @param string $option_manual
	 *
	 * @return mixed
	 * @throws Gateway_Exception
	 */
	protected function get_from_field_or_manual( $option_field, $option_manual ) {
		$scenario   = jet_fb_gateway_current()->get_current_scenario();
		$field_name = $scenario[ $option_field ] ?? false;

		if ( ! $field_name ) {
			return $scenario[ $option_manual ] ?? false;
		}

		$request = jet_fb_action_handler()->request_data;

		if ( empty( $request[ $field_name ] ) ) {
			throw new Gateway_Exception(
				'Empty value for ' . $option_field . ' in field ' . $field_name,
				$field_name,
				$request
			);
		}

		return $request[ $field_name ];
	}

	/**
	 * Liga a assinatura ao Form Record (base p/ o webhook re-rodar as ações).
	 *
	 * @throws Sql_Exception
	 */
	public function attach_record_id() {
		$record_id       = jet_fb_action_handler()->get_context( Save_Record::ID, 'id' );
		$subscription_id = $this->get_context( 'session_id' );

		if ( ! $record_id || ! $subscription_id ) {
			return;
		}

		( new SubscriptionToRecordModel() )->insert(
			array(
				'subscription_id' => $subscription_id,
				'record_id'       => $record_id,
			)
		);
	}

	/**
	 * Resolve a config do cenário (sem usar set_plan_field, inexistente no core).
	 */
	public function set_gateway_data() {
	}

	/**
	 * back_url + o id interno da assinatura (para o retorno localizar a linha).
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public function get_referrer_url( string $type ) {
		$url = parent::get_referrer_url( $type );

		return add_query_arg(
			array( self::SUBSCRIPTION_QUERY_VAR => $this->subscription_id ),
			$url
		);
	}

	/**
	 * Localiza a linha do cenário (assinatura + record) pelo id interno.
	 *
	 * @return array
	 * @throws Gateway_Exception
	 */
	protected function query_scenario_row(): array {
		try {
			return SubscriptionWithRecord::findOne(
				array( 'id' => $this->query_token() )
			)->query()->query_one();

		} catch ( Query_Builder_Exception $exception ) {
			throw new Gateway_Exception( $exception->getMessage() );
		}
	}
}
