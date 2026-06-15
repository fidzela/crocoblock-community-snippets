<?php
/**
 * Plugin Name: JetFormBuilder Mercadopago Gateway
 * Plugin URI:  https://jetformbuilder.com/addons/mercadopago-payments/
 * Description: A supplementary software to integrate your forms and Mercadopago payment system.
 * Version:     1.0.0
 * Author:      fidzela
 * Author URI:  https://github.com/fidzela
 * Text Domain: jet-form-builder-mercadopago-gateway
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

define( 'JET_FB_MERCADOPAGO_GATEWAY_VERSION', '2.0.3' );

define( 'JET_FB_MERCADOPAGO_GATEWAY__FILE__', __FILE__ );
define( 'JET_FB_MERCADOPAGO_GATEWAY_PLUGIN_BASE', plugin_basename( __FILE__ ) );
define( 'JET_FB_MERCADOPAGO_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'JET_FB_MERCADOPAGO_GATEWAY_URL', plugins_url( '/', __FILE__ ) );

// >>> Interruptor do Subscription (ativo-mas-inerte). Troque para true no futuro.
if ( ! defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) ) {
	define( 'JFB_MP_SUBSCRIPTIONS_ENABLED', false );
}

require JET_FB_MERCADOPAGO_GATEWAY_PATH . 'vendor/autoload.php';

add_action( 'plugins_loaded', function () {

	if ( ! version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
		add_action( 'admin_notices', function () {
			$class   = 'notice notice-error';
			$message = __(
				'<b>Error:</b> <b>JetFormBuilder Mercadopago Gateway</b> plugin requires a PHP version ">= 7.0"',
				'jet-form-builder-mercadopago-gateway'
			);
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
		} );

		return;
	}
	require JET_FB_MERCADOPAGO_GATEWAY_PATH . 'includes/plugin.php';
}, 100 );

/*add_action( 'rest_api_init', function () {
*	( new Jet_FB_Mercadopago_Gateway\RestEndpoints\MercadopagoWebHookGlobal() )->register_endpoint();
*} );
*/