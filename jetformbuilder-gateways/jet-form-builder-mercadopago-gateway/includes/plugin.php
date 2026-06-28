<?php

namespace Jet_FB_Mercadopago_Gateway;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Engine;
use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder;
use JetMercadopagoGatewayCore\LicenceProxy;
use Jet_FB_Mercadopago_Gateway\Proxy\AdminPages;
use Jet_FB_Mercadopago_Gateway\Proxy\AdminSinglePages;
use Jet_FB_Mercadopago_Gateway\Proxy\RestApiController;
use Jet_FB_Mercadopago_Gateway\FormEvents\EventsManager;
use Jet_FB_Mercadopago_Gateway\Admin\Plans_Page;
use Jet_FB_Mercadopago_Gateway\Recovery\Reconciler;
use Jet_FB_Mercadopago_Gateway\Recovery\Pending_Effects;
use Jet_FB_Mercadopago_Gateway\Payment_Methods_Config;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

class Plugin {
	/**
	 * Instance.
	 *
	 * Holds the plugin instance.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @var Plugin
	 */
	public static $instance = null;

	public $slug = 'jet-form-builder-mercadopago-gateway';

	public function __construct() {

		require_once JET_FB_MERCADOPAGO_GATEWAY_PATH . 'includes/Shared/Loader.php';

		$this->register_autoloader();

		Jet_Form_Builder\Manager::instance();

		add_action(
			'after_setup_theme',
			function () {
				Jet_Form_Builder\Tabs\Manager_Tabs::register();
				LicenceProxy::register();
				$this->init();
			},
			-100
		);
	}

	public function init() {
		$this->init_components();
	}

	public function init_components() {
		if ( is_admin() ) {
			new Editor();
			Plans_Page::register();
		}

		if ( Jet_Engine\Manager::check() ) {
			Jet_Engine\Manager::instance();
		}

		AdminPages::register();
		AdminSinglePages::register();
		RestApiController::register();
		EventsManager::register();

		// Rede de segurança: reconcilia com o MP os registros que o webhook perdeu
		// (plugin fora do ar além da janela de retry do MP, rollback, etc.). WP-Cron.
		Reconciler::register();

		// Flag de "efeitos pendentes": expõe a reexecução manual das ações do form
		// (hook jet-form-builder/mercadopago/rerun-effects) quando um evento falhou.
		Pending_Effects::register();

		// Meios de pagamento por-formulário (Pay Now): hooka o filtro de exclusão de
		// tipos que o Create_Preference já dispara. Isolado das credenciais.
		Payment_Methods_Config::register();
	}

	/**
	 * Register autoloader.
	 */
	public function register_autoloader() {
		require JET_FB_MERCADOPAGO_GATEWAY_PATH . 'includes/autoloader.php';
		Autoloader::run();
	}

	public function get_version() {
		return JET_FB_MERCADOPAGO_GATEWAY_VERSION;
	}

	public function plugin_url( $path ) {
		return JET_FB_MERCADOPAGO_GATEWAY_URL . $path;
	}

	public function plugin_dir( $path = '' ) {
		return JET_FB_MERCADOPAGO_GATEWAY_PATH . $path;
	}

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @return Plugin An instance of the class.
	 * @since 1.0.0
	 * @access public
	 * @static
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

Plugin::instance();
