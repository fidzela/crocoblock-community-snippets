<?php
/**
 * ============================================================================
 *  Plans_Page  —  Integra a aba "Mercado Pago Plans" nas Settings do JFB
 * ============================================================================
 *
 *  Em vez de uma página de menu própria (que parecia "gambiarra"), o
 *  gerenciamento de planos vira uma ABA do SPA de settings do JetFormBuilder
 *  (mesma área do MailChimp/ActiveCampaign), em
 *  `wp-admin/edit.php?post_type=jet-form-builder&page=jfb-settings`.
 *
 *  Aqui só enfileiramos o JS da aba (que se registra no SPA via o filtro
 *  `jet.fb.register.settings-page.tabs`) e passamos a config + nonce. O
 *  componente Vue vive em assets/js/mp-plans-settings.js.
 *
 *  SEGURANÇA: o Access Token NÃO é enviado ao cliente. Passamos apenas
 *  `hasToken` (bool). O CRUD usa os endpoints REST, que leem a chave do gateway
 *  SERVER-SIDE (settings globais de Payments Gateways).
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Admin;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plans_Page {

	const REST_NS = 'jet-form-builder/v1';

	public static function register() {
		// Dispara só na página de settings do JFB (hook do próprio JFB).
		add_action( 'jet-fb/admin-pages/before-assets/jfb-settings', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Token global do gateway (server-side). Só usamos para saber se está
	 * configurado — o valor NÃO vai para o navegador.
	 *
	 * @return string
	 */
	private static function token(): string {
		if ( ! class_exists( Controller::class ) ) {
			return '';
		}
		try {
			$creds = Controller::get_credentials();
		} catch ( \Throwable $e ) {
			return '';
		}
		return (string) ( $creds['secret'] ?? '' );
	}

	public static function enqueue() {
		$handle = 'jfb-mp-plans-settings';

		wp_enqueue_script(
			$handle,
			JET_FB_MERCADOPAGO_GATEWAY_URL . 'assets/js/mp-plans-settings.js',
			array( 'wp-hooks', 'wp-i18n' ),
			JET_FB_MERCADOPAGO_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'JFB_MP_PLANS',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'hasToken' => '' !== self::token(),
				'urls'     => array(
					'list'   => rest_url( self::REST_NS . '/fetch-mercadopago-plans' ),
					'create' => rest_url( self::REST_NS . '/create-mercadopago-plan' ),
					'delete' => rest_url( self::REST_NS . '/delete-mercadopago-plan' ),
				),
				'i18n'     => array(
					'title'         => __( 'Mercado Pago Plans', 'jet-form-builder-mercadopago-gateway' ),
					'intro'         => __( 'Crie, veja e exclua os planos de assinatura (preapproval_plan) da API. Os "Planos" criados no PAINEL do Mercado Pago NÃO aparecem na API e não servem para a integração — use os daqui. São estes que populam o dropdown do cenário "Subscription". A chave usada é SEMPRE a do gateway (Payments Gateways), server-side.', 'jet-form-builder-mercadopago-gateway' ),
					'existing'      => __( 'Planos existentes', 'jet-form-builder-mercadopago-gateway' ),
					'refresh'       => __( 'Atualizar lista', 'jet-form-builder-mercadopago-gateway' ),
					'empty'         => __( 'Nenhum plano de API nesta conta. Crie um abaixo.', 'jet-form-builder-mercadopago-gateway' ),
					'loading'       => __( 'Carregando…', 'jet-form-builder-mercadopago-gateway' ),
					'createTitle'   => __( 'Criar novo plano', 'jet-form-builder-mercadopago-gateway' ),
					'fReason'       => __( 'Nome / descrição', 'jet-form-builder-mercadopago-gateway' ),
					'fAmount'       => __( 'Valor', 'jet-form-builder-mercadopago-gateway' ),
					'fFrequency'    => __( 'Frequência', 'jet-form-builder-mercadopago-gateway' ),
					'fType'         => __( 'Tipo', 'jet-form-builder-mercadopago-gateway' ),
					'fCurrency'     => __( 'Moeda', 'jet-form-builder-mercadopago-gateway' ),
					'months'        => __( 'mês(es)', 'jet-form-builder-mercadopago-gateway' ),
					'days'          => __( 'dia(s)', 'jet-form-builder-mercadopago-gateway' ),
					'createBtn'     => __( 'Criar plano', 'jet-form-builder-mercadopago-gateway' ),
					'created'       => __( 'Plano criado!', 'jet-form-builder-mercadopago-gateway' ),
					'deleted'       => __( 'Plano cancelado.', 'jet-form-builder-mercadopago-gateway' ),
					'delete'        => __( 'Excluir', 'jet-form-builder-mercadopago-gateway' ),
					'confirmDelete' => __( 'Cancelar/desativar este plano no Mercado Pago? Assinaturas já ativas continuam; o plano só deixa de aceitar novas.', 'jet-form-builder-mercadopago-gateway' ),
					'noToken'       => __( 'Configure o Access Token em JetFormBuilder → Settings → Payments Gateways → Mercado Pago. Esta aba usa SEMPRE essa chave (server-side).', 'jet-form-builder-mercadopago-gateway' ),
				),
			)
		);
	}
}
