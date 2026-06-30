<?php

namespace Jet_FB_Mercadopago_Gateway;


class Editor
{
    use Editor_Assets_Manager;

    public function __construct() {
        $this->assets_init();
    }

	/**
	 * @return void
	 */
	public function before_init_editor_assets() {
		wp_enqueue_script(
			Plugin::instance()->slug,
			Plugin::instance()->plugin_url( 'assets/js/mercadopago.js' ), // CLAUDE VERIFICAR
			array(),
			Plugin::instance()->get_version(),
			true
		);
	}
}

