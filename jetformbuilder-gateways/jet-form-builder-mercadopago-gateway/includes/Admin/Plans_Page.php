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

	/**
	 * Versão do asset = mtime do arquivo. Garante cache-bust automático a cada
	 * alteração/reinstalação: o `?ver=` muda sozinho. Sem isto, o navegador serve o
	 * JS/CSS VELHO do cache (mesmo `?ver` do plugin) mesmo após reinstalar — foi
	 * exatamente o que aconteceu (PHP/endpoint novos, mas a aba renderizava o JS antigo).
	 *
	 * @param string $rel Caminho relativo do asset dentro do plugin.
	 *
	 * @return string
	 */
	private static function asset_ver( string $rel ): string {
		$path  = JET_FB_MERCADOPAGO_GATEWAY_PATH . $rel;
		$mtime = file_exists( $path ) ? filemtime( $path ) : 0;

		return $mtime ? (string) $mtime : JET_FB_MERCADOPAGO_GATEWAY_VERSION;
	}

	public static function enqueue() {
		$handle  = 'jfb-mp-plans-settings';
		$js_rel  = 'assets/js/mp-plans-settings.js';
		$css_rel = 'assets/css/mp-plans-settings.css';

		wp_enqueue_script(
			$handle,
			JET_FB_MERCADOPAGO_GATEWAY_URL . $js_rel,
			array( 'wp-hooks', 'wp-i18n' ),
			self::asset_ver( $js_rel ),
			true
		);

		// Só os títulos de seção / linhas de plano; o resto é cx-vui nativo.
		wp_enqueue_style(
			$handle,
			JET_FB_MERCADOPAGO_GATEWAY_URL . $css_rel,
			array(),
			self::asset_ver( $css_rel )
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
					// `title` = label da ABA (lateral, compacta como "ActiveCampaign API").
					// `pageTitle` = título grande no topo do conteúdo.
					'title'         => __( 'MercadoPago Settings', 'jet-form-builder-mercadopago-gateway' ),
					'pageTitle'     => __( 'Mercado Pago Gateway', 'jet-form-builder-mercadopago-gateway' ),
					'intro'         => __( 'Gerencie aqui os planos de assinatura (preapproval_plan) da API do Mercado Pago — são eles que populam o dropdown do cenário "Subscription". A chave usada é SEMPRE a do gateway (Payments Gateways), server-side.', 'jet-form-builder-mercadopago-gateway' ),
					'helpLink'      => __( 'Como funciona? →', 'jet-form-builder-mercadopago-gateway' ),
					'helpTitle'     => __( 'Como funcionam os planos do Mercado Pago', 'jet-form-builder-mercadopago-gateway' ),
					'helpBody'      => array(
						__( 'Um "plano" (preapproval_plan) é um MODELO de assinatura: define valor, frequência e moeda. Ele não cobra ninguém sozinho — serve de base para criar assinaturas.', 'jet-form-builder-mercadopago-gateway' ),
						__( 'Atenção: os "Planos" criados no PAINEL do Mercado Pago NÃO aparecem na API e não servem para a integração. Use os criados aqui — são estes que o cenário "Subscription" do formulário enxerga.', 'jet-form-builder-mercadopago-gateway' ),
						__( 'Ao enviar um formulário com o cenário Subscription, lemos os termos do plano escolhido e criamos uma assinatura (preapproval) com esses termos embutidos, redirecionando o pagador ao checkout do Mercado Pago (o cartão é informado lá, como no Stripe Checkout).', 'jet-form-builder-mercadopago-gateway' ),
						__( 'Por segurança, o Access Token nunca trafega para o navegador: todas as operações aqui usam a chave configurada no gateway, no servidor.', 'jet-form-builder-mercadopago-gateway' ),
					),
					'helpRefs'      => __( 'Referências oficiais (Mercado Pago)', 'jet-form-builder-mercadopago-gateway' ),
					'docSubs'       => __( 'Assinaturas — visão geral (docs MP)', 'jet-form-builder-mercadopago-gateway' ),
					'docPlan'       => __( 'API: criar plano (preapproval_plan)', 'jet-form-builder-mercadopago-gateway' ),
					'docPre'        => __( 'API: criar assinatura (preapproval)', 'jet-form-builder-mercadopago-gateway' ),
					'existing'      => __( 'Planos existentes', 'jet-form-builder-mercadopago-gateway' ),
					'refresh'       => __( 'Atualizar lista', 'jet-form-builder-mercadopago-gateway' ),
					'empty'         => __( 'Nenhum plano de API nesta conta. Crie um abaixo.', 'jet-form-builder-mercadopago-gateway' ),
					'loading'       => __( 'Carregando…', 'jet-form-builder-mercadopago-gateway' ),
					'createTitle'   => __( 'Criar novo plano', 'jet-form-builder-mercadopago-gateway' ),
					'fReason'       => __( 'Nome / descrição', 'jet-form-builder-mercadopago-gateway' ),
					'fAmount'       => __( 'Valor do plano', 'jet-form-builder-mercadopago-gateway' ),
					'willCharge'    => __( 'Valor formatado:', 'jet-form-builder-mercadopago-gateway' ),
					'createdOn'     => __( 'Criado em', 'jet-form-builder-mercadopago-gateway' ),
					'cancelledOn'   => __( 'Excluído em', 'jet-form-builder-mercadopago-gateway' ),
					'statusActive'  => __( 'Ativo', 'jet-form-builder-mercadopago-gateway' ),
					'statusCancelled' => __( 'Excluído pelo dono', 'jet-form-builder-mercadopago-gateway' ),
					'fFrequency'    => __( 'Frequência', 'jet-form-builder-mercadopago-gateway' ),
					'fType'         => __( 'Tipo de frequência', 'jet-form-builder-mercadopago-gateway' ),
					'fCurrency'     => __( 'Moeda', 'jet-form-builder-mercadopago-gateway' ),
					'months'        => __( 'mês(es)', 'jet-form-builder-mercadopago-gateway' ),
					'days'          => __( 'dia(s)', 'jet-form-builder-mercadopago-gateway' ),
					'every'         => __( 'a cada', 'jet-form-builder-mercadopago-gateway' ),
					'createBtn'     => __( 'Criar plano', 'jet-form-builder-mercadopago-gateway' ),
					'required'      => __( 'Preencha os campos obrigatórios:', 'jet-form-builder-mercadopago-gateway' ),
					'created'       => __( 'Plano criado!', 'jet-form-builder-mercadopago-gateway' ),
					'deleted'       => __( 'Plano cancelado.', 'jet-form-builder-mercadopago-gateway' ),
					'delete'        => __( 'Excluir', 'jet-form-builder-mercadopago-gateway' ),
					'confirmDelete' => __( 'Cancelar/desativar este plano no Mercado Pago? Assinaturas já ativas continuam; o plano só deixa de aceitar novas.', 'jet-form-builder-mercadopago-gateway' ),
					'showCancelled'     => __( 'Mostrar excluídos', 'jet-form-builder-mercadopago-gateway' ),
					/* translators: %d: número de planos cancelados */
					'showCancelledDesc' => __( 'Exibir também os planos cancelados (%d).', 'jet-form-builder-mercadopago-gateway' ),
					'noToken'       => __( 'Configure o Access Token em JetFormBuilder → Settings → Payments Gateways → Mercado Pago. Esta aba usa SEMPRE essa chave (server-side).', 'jet-form-builder-mercadopago-gateway' ),
				),
			)
		);
	}
}
