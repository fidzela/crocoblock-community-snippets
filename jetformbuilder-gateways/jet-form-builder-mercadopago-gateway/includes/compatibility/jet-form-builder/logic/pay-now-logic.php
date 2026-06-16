<?php
/**
 * ============================================================================
 *  Pay_Now_Logic  —  Cenário de pagamento único (Checkout Pro)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/logic/pay-now-logic.php
 *
 *  FLUXO (espelha o Stripe, com as diferenças do Mercado Pago):
 *
 *  after_actions()  — roda DEPOIS das actions do form
 *    1. set_gateway_data()         resolve o campo de preço (price_field) -> valor
 *    2. create_resource()          cria a PREFERENCE (POST /checkout/preferences)
 *    3. save_resource()            grava 1 linha (Payment_Model) status 'CREATED'
 *                                  com transaction_id = preference['id']
 *    4. redirect                   init_point (produção) OU sandbox_init_point
 *                                  (se o Access Token começa com 'TEST-')
 *    5. Save_Record::add_hidden()  exige a action "Save Record"
 *    6. attach_record_id()         liga pagamento <-> registro do form
 *
 *  process_after()  — roda no RETORNO (back_url)
 *    1. exige status 'CREATED' (anti dupla-captura)
 *    2. lê payment_id da URL (o MP anexa payment_id/status/preference_id/...)
 *    3. GET /v1/payments/{payment_id}  (fonte de verdade; nunca confiar no
 *       status que vem na URL)
 *    4. exige status == 'approved'
 *    5. grava pagamento (COMPLETED) + dados do pagador
 *
 *  COMO O CORE ACHA A LINHA NO RETORNO:
 *    query_scenario_row() busca por transaction_id == get_queried_token().
 *    get_queried_token() lê o parâmetro definido em Controller::$token_query_name,
 *    que para o MP é 'preference_id'. Como salvamos transaction_id =
 *    preference['id'], e o MP devolve preference_id na back_url, casa certinho.
 *
 *  DIFERENÇAS-CHAVE vs. Stripe:
 *    - get_referrer_url(): NÃO injeta o placeholder {CHECKOUT_SESSION_ID}
 *      (o MP anexa os parâmetros sozinho).
 *    - amount_value salvo SEM dividir por 100 (BRL real).
 *    - a verificação usa payment_id (da URL), não o transaction_id da preference.
 *    - sucesso = 'approved' (Stripe era 'complete').
 *    - sem Expire_Checkout_Session (conceito do Stripe).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Logic;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Create_Checkout_Session;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Actions\Retrieve_Checkout_Session;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Pay_Now_Connector;
use Jet_Form_Builder\Actions\Types\Save_Record;
use Jet_Form_Builder\Db_Queries\Exceptions\Sql_Exception;
use Jet_Form_Builder\Db_Queries\Execution_Builder;
use Jet_Form_Builder\Exceptions\Gateway_Exception;
use Jet_Form_Builder\Exceptions\Query_Builder_Exception;
use Jet_Form_Builder\Exceptions\Repository_Exception;
use Jet_Form_Builder\Gateways\Db_Models\Payer_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_Model;
use Jet_Form_Builder\Gateways\Db_Models\Payment_To_Record;
use Jet_Form_Builder\Gateways\Paypal\Scenarios_Logic\With_Resource_It;
use Jet_Form_Builder\Gateways\Query_Views\Payment_With_Record_View;
use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_Logic_Base;
use Jet_Form_Builder\Gateways\Base_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pay_Now_Logic extends Scenario_Logic_Base implements With_Resource_It {

	use Pay_Now_Connector;

	/**
	 * Parâmetro que o Mercado Pago devolve na back_url e que casa com o
	 * transaction_id salvo (= id da preference).
	 */
	const QUERY_VAR = 'preference_id';

	/**
	 * Token vindo da URL no retorno.
	 *
	 * @return string
	 */
	protected function query_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ?? '' ) );
	}

	/**
	 * Status do Mercado Pago considerados "falha" (para mensagens do form).
	 *
	 * @return array
	 */
	public function get_failed_statuses() {
		return array( 'rejected', 'cancelled', 'refunded', 'charged_back' );
	}

	/**
	 * @throws Gateway_Exception
	 * @throws Repository_Exception
	 */
	public function after_actions() {
		$this->set_gateway_data();

		// Cria a preference no Checkout Pro.
		$preference = $this->create_resource();

		// Grava a linha de pagamento (status CREATED) e guarda o id interno.
		$this->add_context(
			array(
				'payment_id' => $this->save_resource( $preference ),
			)
		);

		// Redireciona para o checkout do Mercado Pago.
		jet_fb_action_handler()->add_response(
			array( 'redirect' => $this->resolve_redirect_url( $preference ) )
		);

		Save_Record::add_hidden();

		add_action(
			'jet-form-builder/form-handler/after-send',
			array( $this, 'attach_record_id' )
		);
	}

	/**
	 * Escolhe a URL de redirecionamento conforme o ambiente.
	 * Access Token de teste começa com 'TEST-' -> sandbox_init_point.
	 *
	 * @param array $preference
	 *
	 * @return string
	 */
	protected function resolve_redirect_url( array $preference ): string {
		$token   = (string) jet_fb_gateway_current()->current_gateway( 'secret' );
		$is_test = ( 0 === strpos( $token, 'TEST-' ) );

		if ( $is_test && ! empty( $preference['sandbox_init_point'] ) ) {
			return $preference['sandbox_init_point'];
		}

		return $preference['init_point'] ?? ( $preference['sandbox_init_point'] ?? '' );
	}

	/**
	 * Cria a preference (Checkout Pro).
	 *
	 * @return array
	 * @throws Gateway_Exception
	 * @throws Repository_Exception
	 */
	public function create_resource() {
		$controller = jet_fb_gateway_current();

		$request = ( new Create_Checkout_Session() )
			->set_bearer_auth( $controller->current_gateway( 'secret' ) ) // 'secret' = Access Token
			->set_currency( $controller->current_gateway( 'currency' ) )
			->set_price( $controller->get_price_var() )                    // BRL real (sem *100)
			->set_external_reference( $this->generate_external_reference() )
			->set_urls(
				$this->get_referrer_url( Base_Gateway::SUCCESS_TYPE ),
				$this->get_referrer_url( Base_Gateway::FAILED_TYPE )
			);

		do_action( 'jet-form-builder/gateways/before-create', $request );

		$preference = $request->send_request();

		if ( isset( $preference['error'] ) ) {
			throw new Gateway_Exception( $preference['error']['message'], $preference );
		}

		return $preference;
	}

	/**
	 * Gera uma referência externa única por submissão (anti-replay / fase 2).
	 *
	 * @return string
	 */
	protected function generate_external_reference(): string {
		return 'jfbmp-' . jet_fb_handler()->form_id . '-' . uniqid( '', true );
	}

	/**
	 * Liga o pagamento ao registro do form (tabela Payment_To_Record).
	 *
	 * @throws Sql_Exception
	 * @throws Repository_Exception
	 */
	public function attach_record_id() {
		$record_id  = jet_fb_action_handler()->get_context( Save_Record::ID, 'id' );
		$payment_id = $this->get_context( 'payment_id' );

		if ( ! $record_id || ! $payment_id ) {
			return;
		}

		( new Payment_To_Record() )->insert(
			array(
				'record_id'  => $record_id,
				'payment_id' => $payment_id,
			)
		);
	}

	/**
	 * Retorno do checkout: confirma o pagamento de verdade.
	 *
	 * @throws Gateway_Exception
	 */
	public function process_after() {
		if ( 'CREATED' !== $this->get_scenario_row( 'status' ) ) {
			throw new Gateway_Exception( 'Payment was already captured' );
		}

		// O MP devolve o id do PAGAMENTO na back_url (não o da preference).
		$payment_id = $this->get_returned_payment_id();

		if ( '' === $payment_id ) {
			$this->on_error(
				array( 'message' => 'Missing payment_id on return' ),
				__( 'Could not identify the payment on return', 'jet-form-builder-mercadopago-gateway' )
			);
		}

		// Fonte de verdade: GET /v1/payments/{id} autenticado.
		$payment = ( new Retrieve_Checkout_Session() )
			->set_bearer_auth( jet_fb_gateway_current()->current_gateway( 'secret' ) )
			->set_path( array( 'id' => $payment_id ) )
			->send_request();

		if ( isset( $payment['error'] ) ) {
			$this->on_error( $payment );
		}

		$status = $payment['status'] ?? '';

		if ( 'approved' !== $status ) {
			// pending / in_process / rejected / cancelled -> não aprovado.
			$this->on_error(
				$payment,
				sprintf(
					/* translators: %s: Mercado Pago payment status */
					__( 'Payment not approved (status: %s)', 'jet-form-builder-mercadopago-gateway' ),
					$status ? $status : 'unknown'
				)
			);
		}

		try {
			Execution_Builder::instance()->transaction_start();

			$this->save_payment( $payment );

			Execution_Builder::instance()->transaction_commit();

		} catch ( Sql_Exception $exception ) {
			Execution_Builder::instance()->transaction_rollback();

			throw new Gateway_Exception( $exception->getMessage() );
		}
	}

	/**
	 * Lê o id do pagamento dos parâmetros que o MP anexa na back_url.
	 * 'payment_id' é o principal; 'collection_id' é alias legado.
	 *
	 * @return string
	 */
	protected function get_returned_payment_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = $_GET['payment_id'] ?? ( $_GET['collection_id'] ?? '' );

		return sanitize_text_field( wp_unslash( (string) $id ) );
	}

	/**
	 * Marca o pagamento como VOID e lança exceção (caminho de falha).
	 *
	 * @param array  $payment
	 * @param string $log_message
	 *
	 * @throws Gateway_Exception
	 */
	private function on_error( array $payment, string $log_message = '' ) {
		if ( '' === $log_message ) {
			$log_message = __( 'Payment was voided', 'jet-form-builder-mercadopago-gateway' );
		}

		$this->scenario_row(
			array(
				'status' => 'VOIDED',
			)
		);

		try {
			( new Payment_Model() )->update(
				array( 'status' => 'VOIDED' ),
				array( 'id' => $this->get_scenario_row( 'id' ) )
			);
		} catch ( Sql_Exception $exception ) {
			return;
		} finally {
			throw new Gateway_Exception( $log_message, $payment );
		}
	}

	/**
	 * Persiste o pagamento aprovado e os dados do pagador.
	 *
	 * @param array $payment
	 *
	 * @throws Sql_Exception
	 */
	private function save_payment( array $payment ) {
		( new Payment_Model() )->update(
			array( 'status' => 'COMPLETED' ),
			array( 'id' => $this->get_scenario_row( 'id' ) )
		);

		// Guarda o status real do MP (dirige as mensagens/ações de retorno).
		$this->scenario_row(
			array(
				'status' => $payment['status'] ?? 'rejected',
			)
		);

		// Enriquecimento opcional: dados do pagador (estrutura do MP é 'payer').
		$payer = $payment['payer'] ?? array();

		if ( ! empty( $payer['email'] ) ) {
			Payer_Model::insert_or_update(
				array(
					'user_id'    => $this->get_scenario_row( 'user_id' ),
					'payer_id'   => (string) ( $payer['id'] ?? '' ),
					'first_name' => $payer['first_name'] ?? null,
					'last_name'  => $payer['last_name'] ?? null,
					'email'      => $payer['email'],
				)
			);
		}
	}

	/**
	 * Localiza a linha do cenário pelo transaction_id (= id da preference),
	 * que casa com o preference_id devolvido na back_url.
	 *
	 * @return array
	 * @throws Gateway_Exception
	 */
	protected function query_scenario_row() {
		try {
			return Payment_With_Record_View::findOne(
				array(
					'transaction_id' => $this->get_queried_token(),
				)
			)->query()->query_one();

		} catch ( Query_Builder_Exception $exception ) {
			throw new Gateway_Exception( $exception->getMessage() );
		}
	}

	/**
	 * Resolve o valor do campo de preço do formulário.
	 *
	 * @throws Gateway_Exception
	 */
	public function set_gateway_data() {
		jet_fb_gateway_current()->set_price_field();
		jet_fb_gateway_current()->set_price_from_filed();
	}

	/**
	 * Grava a linha inicial do pagamento (status CREATED).
	 * transaction_id = id da preference; amount_value em BRL real (sem /100).
	 *
	 * @param array $resource
	 *
	 * @return int
	 * @throws Gateway_Exception
	 */
	public function save_resource( $resource ) {
		$payment_row = array(
			'transaction_id'         => $resource['id'],
			// Reaproveita initial_transaction_id para guardar o external_reference
			// — a CHAVE DE RECONCILIAÇÃO do webhook -> linha. O MP o devolve ecoado
			// na resposta da preference. Em pay-now não há renovação, então o campo
			// está livre. Fallback defensivo para o id da preference.
			'initial_transaction_id' => $resource['external_reference'] ?? $resource['id'],
			'form_id'                => jet_fb_handler()->form_id,
			'user_id'                => get_current_user_id(),
			'gateway_id'             => jet_fb_gateway_current()->get_id(),
			'scenario'               => self::scenario_id(),
			'amount_value'           => jet_fb_gateway_current()->get_price_var(), // BRL real, sem /100
			'amount_code'            => jet_fb_gateway_current()->current_gateway( 'currency' ) ?: 'BRL',
			'type'                   => Base_Gateway::PAYMENT_TYPE_INITIAL,
			'status'                 => 'CREATED',
		);

		try {
			return ( new Payment_Model() )->insert( $payment_row );
		} catch ( Sql_Exception $exception ) {
			throw new Gateway_Exception( $exception->getMessage() );
		}
	}

	/**
	 * URL de retorno. O Mercado Pago anexa payment_id/status/preference_id/
	 * external_reference automaticamente — por isso NÃO injetamos placeholder
	 * (diferente do Stripe, que usava {CHECKOUT_SESSION_ID}).
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public function get_referrer_url( string $type ) {
		return parent::get_referrer_url( $type );
	}
}