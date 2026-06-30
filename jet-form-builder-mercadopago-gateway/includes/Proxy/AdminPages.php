<?php
namespace Jet_FB_Mercadopago_Gateway\Proxy;

use Jet_FB_Paypal\Pages;
use JetMercadopagoGatewayCore\JetFormBuilder\AdminPagesProxy;

class AdminPages extends AdminPagesProxy {

	public function plugin_version_compare(): string {
		return '2.0.3';
	}

	public function pages(): array {
		return array(
			new Pages\PaymentsPage,
			new Pages\SubscriptionsPage
		);
	}

	public function on_base_need_update() {
		$this->add_admin_notice( 'warning', __(
			'<b>Warning</b>: <b>JetFormBuilder Mercadopago Gateway</b> needs <b>JetFormBuilder</b> update.',
			'jet-form-builder-mercadopago-gateway'
		) );
	}

	public function on_base_need_install() {
	}
}