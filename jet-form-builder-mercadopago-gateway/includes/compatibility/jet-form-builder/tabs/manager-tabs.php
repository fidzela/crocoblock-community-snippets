<?php


namespace Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Tabs;

use JetMercadopagoGatewayCore\JetFormBuilder\RegisterFormTabs;

class Manager_Tabs {

	use RegisterFormTabs;

	public function plugin_version_compare() {
		return '1.2.2';
	}

	public function tabs(): array {
		return array(
			new Mercadopago_Tab()
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