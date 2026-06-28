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

define( 'JET_FB_MERCADOPAGO_GATEWAY_VERSION', '2.0.21' );

define( 'JET_FB_MERCADOPAGO_GATEWAY__FILE__', __FILE__ );
define( 'JET_FB_MERCADOPAGO_GATEWAY_PLUGIN_BASE', plugin_basename( __FILE__ ) );
define( 'JET_FB_MERCADOPAGO_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'JET_FB_MERCADOPAGO_GATEWAY_URL', plugins_url( '/', __FILE__ ) );

// Interruptor do cenário Subscription. LIGADO por padrão — ciclo completo de
// assinatura (criação -> redirect -> webhooks -> tabela Subscriptions). Para
// desligar, defina como false no wp-config ANTES deste plugin carregar.
if ( ! defined( 'JFB_MP_SUBSCRIPTIONS_ENABLED' ) ) {
	define( 'JFB_MP_SUBSCRIPTIONS_ENABLED', true );
}

// Ao DESATIVAR o plugin, limpa o agendamento WP-Cron do reconciliador. Feito aqui,
// SEM depender do autoloader, para funcionar mesmo num estado degradado. O nome do
// hook espelha Recovery\Reconciler::HOOK ('jfbmp_reconcile').
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'jfbmp_reconcile' );
} );

// Guarda defensiva no autoloader. Se o vendor/autoload.php estiver ausente/ilegível
// — o que acontece MOMENTANEAMENTE durante uma ATUALIZAÇÃO do plugin (a pasta é
// removida e re-extraída), ou num upload/extração incompleto — NÃO derrubamos o site
// inteiro com fatal. Em vez disso, abortamos o boot do plugin e avisamos no admin.
$jet_fb_mercadopago_autoload = JET_FB_MERCADOPAGO_GATEWAY_PATH . 'vendor/autoload.php';

if ( ! is_readable( $jet_fb_mercadopago_autoload ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'JetFormBuilder Mercadopago Gateway: dependências ausentes (vendor/autoload.php). Reinstale o plugin — faça o upload do .zip novamente.',
					'jet-form-builder-mercadopago-gateway'
				)
			);
		}
	);

	return;
}

require_once $jet_fb_mercadopago_autoload;

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

add_action( 'rest_api_init', function () {
	( new Jet_FB_Mercadopago_Gateway\RestEndpoints\MercadopagoWebHookGlobal() )->register_endpoint();
} );