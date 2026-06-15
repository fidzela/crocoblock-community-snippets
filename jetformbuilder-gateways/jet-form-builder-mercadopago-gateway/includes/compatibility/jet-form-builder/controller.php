<?php
/**
 * ============================================================================
 *  Controller  —  Gateway do Mercado Pago (registrado no JetFormBuilder)
 * ============================================================================
 *
 *  DESTINO (cole por cima):
 *    includes/compatibility/jet-form-builder/controller.php
 *
 *  MUDANÇAS vs. a versão renomeada do Stripe:
 *   1) $token_query_name = 'preference_id'  (era 'session_id').
 *      O Mercado Pago anexa preference_id na back_url; o core usa esse valor
 *      para achar a linha (transaction_id == preference_id).
 *   2) get_price()  : NÃO multiplica por 100 (BRL é decimal real).
 *   3) get_current_token() : devolve o Access Token (campo 'secret').
 *
 *  MANTIDO INERTE (não remover — subscription fica pronto p/ o futuro):
 *   - $plan_field / $plan_var / set_plan_field() / set_plan_from_field() /
 *     get_plan_var(): só o cenário Subscription usa. Como o cenário está
 *     desligado por padrão (JFB_MP_SUBSCRIPTIONS_ENABLED), esses métodos não
 *     são chamados, mas permanecem para o arquivo carregar sem fatal.
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;

use Jet_FB_Mercadopago_Gateway\Compatibility\Base_Mercadopago;
use Jet_Form_Builder\Gateways\Base_Scenario_Gateway;
use Jet_Form_Builder\Classes\Tools;
use Jet_Form_Builder\Exceptions\Gateway_Exception;
use Jet_Form_Builder\Exceptions\Repository_Exception;
use Jet_Form_Builder\Gateways\Scenarios_Abstract\Scenario_Logic_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Controller extends Base_Scenario_Gateway {

	use Base_Mercadopago;

	/**
	 * Parâmetro lido no retorno (back_url) para localizar a linha.
	 * O Mercado Pago devolve preference_id, que casa com o transaction_id salvo.
	 *
	 * @var string
	 */
	protected $token_query_name = 'preference_id';

	/**
	 * (Inerte — subscription) Campo do plano.
	 *
	 * @var mixed
	 */
	protected $plan_field;

	/**
	 * (Inerte — subscription) Valor do plano.
	 *
	 * @var mixed
	 */
	protected $plan_var;

	/**
	 * @return Scenario_Logic_Base
	 * @throws Repository_Exception
	 */
	public function get_scenario() {
		return Scenarios_Manager::instance()->get_logic( $this );
	}

	/**
	 * @return Scenario_Logic_Base
	 * @throws Gateway_Exception
	 */
	public function query_scenario() {
		return Scenarios_Manager::instance()->query_logic();
	}

	/**
	 * Rótulos dos cenários para o editor.
	 *
	 * @return array
	 */
	public function custom_labels(): array {
		return array(
			'scenario' => Scenarios_Manager::instance()->view()->get_editor_labels(),
		);
	}

	/**
	 * Dados extras para o editor (lista de cenários disponíveis etc.).
	 *
	 * @return array
	 */
	public function additional_editor_data(): array {
		return array_merge(
			array(
				'version'   => 1,
				'scenarios' => Tools::with_placeholder(
					Scenarios_Manager::instance()->view()->get_items_list(),
					__( 'Choose scenario...', 'jet-form-builder' )
				),
			),
			Scenarios_Manager::instance()->view()->get_editor_data()
		);
	}

	/**
	 * Valor em BRL — decimal real, SEM multiplicar por 100.
	 * (Sobrescreve o trait; esta é a versão efetiva usada pelo core.)
	 *
	 * @param mixed $price
	 *
	 * @return float
	 */
	protected function get_price( $price ) {
		return (float) $price;
	}

	/**
	 * (Inerte — subscription) Resolve o campo do plano.
	 */
	public function set_plan_field() {
		$scenario = $this->current_gateway();

		if ( empty( $scenario['plan_field'] ) ) {
			return;
		}

		$this->plan_field = $scenario['plan_field'];
	}

	/**
	 * (Inerte — subscription) Lê o valor do plano do request.
	 */
	public function set_plan_from_field() {
		if ( empty( $this->plan_field ) ) {
			return;
		}

		$value = jet_fb_action_handler()->request_data( $this->plan_field );

		if ( ! empty( $value ) ) {
			$this->plan_var = $value;
		}
	}

	/**
	 * (Inerte — subscription) Valor do plano.
	 *
	 * @return mixed
	 */
	public function get_plan_var() {
		return $this->plan_var;
	}

	/**
	 * Token de credencial "atual" = Access Token (campo 'secret').
	 * (No Stripe era a troca client_id+secret; o MP usa o Access Token direto.)
	 *
	 * @return string
	 */
	public function get_current_token() {
		return (string) $this->current_gateway( 'secret' );
	}
}