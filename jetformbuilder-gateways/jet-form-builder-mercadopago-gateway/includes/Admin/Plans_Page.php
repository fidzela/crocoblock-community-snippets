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
use Jet_FB_Mercadopago_Gateway\Payment_Methods_Config;

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
	 * Lista os formulários do JetFormBuilder para o seletor da seção de meios de
	 * pagamento (Pay Now). [{ value:id, label:title }].
	 *
	 * @return array
	 */
	private static function forms_list(): array {
		// Mais RECENTES primeiro (FASE 2F): o dono normalmente configura o form que
		// acabou de criar. Ordena por data de criação DESC.
		$posts = get_posts(
			array(
				'post_type'   => 'jet-form-builder',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => 200,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		// Placeholder no topo para o select não auto-selecionar o 1º formulário.
		$forms = array(
			array( 'value' => '', 'label' => __( '— Selecione um formulário —', 'jet-form-builder-mercadopago-gateway' ) ),
		);

		foreach ( $posts as $post ) {
			$title   = '' !== trim( (string) $post->post_title ) ? $post->post_title : ( '#' . $post->ID );
			$forms[] = array(
				'value' => (string) $post->ID,
				'label' => $title . ' (#' . $post->ID . ')',
			);
		}

		return $forms;
	}

	/**
	 * Mapa form_id -> [tipos excluídos] já salvos. Lido da option ISOLADA das
	 * credenciais (Payment_Methods_Config) — nunca do blob do gateway.
	 *
	 * @return array
	 */
	private static function form_exclusions(): array {
		$map = get_option( Payment_Methods_Config::OPTION, array() );

		return is_array( $map ) ? $map : array();
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
					'pmList' => rest_url( self::REST_NS . '/fetch-mercadopago-payment-methods' ),
					'pmSave' => rest_url( self::REST_NS . '/save-mercadopago-payment-methods' ),
				),
				// Meios de pagamento por-form (Pay Now): lista de formulários + exclusões
				// já salvas (option ISOLADA das credenciais).
				'forms'          => self::forms_list(),
				'formExclusions' => self::form_exclusions(),
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
					// Seção "Meios de pagamento" (Pay Now).
					'pmTitle'       => __( 'Meios de pagamento (Pay Now)', 'jet-form-builder-mercadopago-gateway' ),
					'pmIntro'       => __( 'Escolha, por FORMULÁRIO, quais meios o Checkout Pro aceita no Pay Now (ex.: só Pix, ou cartões + Pix, ou excluir boleto). Sincronize os meios da sua conta, selecione um formulário e deixe ATIVOS os que devem aparecer — o restante é excluído. Vale só para Pay Now; assinaturas são sempre cartão de crédito (recorrência).', 'jet-form-builder-mercadopago-gateway' ),
					'pmForm'        => __( 'Formulário', 'jet-form-builder-mercadopago-gateway' ),
					'pmSync'        => __( 'Sincronizar meios de pagamento', 'jet-form-builder-mercadopago-gateway' ),
					'pmSynced'      => __( 'Meios sincronizados', 'jet-form-builder-mercadopago-gateway' ),
					'pmSave2'       => __( 'Salvar meios deste formulário', 'jet-form-builder-mercadopago-gateway' ),
					'pmSaved'       => __( 'Meios de pagamento salvos!', 'jet-form-builder-mercadopago-gateway' ),
					'pmPickForm'    => __( 'Escolha um formulário primeiro.', 'jet-form-builder-mercadopago-gateway' ),
					'pmKeepOne'     => __( 'Mantenha pelo menos um meio de pagamento ativo.', 'jet-form-builder-mercadopago-gateway' ),
					'pmEmpty'       => __( 'Nenhum meio de pagamento retornado pela conta. Sincronize novamente.', 'jet-form-builder-mercadopago-gateway' ),
					/* translators: %d: numero de meios de pagamento */
					'pmSyncedMsg'   => __( 'Há %d meios de pagamento disponíveis e sincronizados com o Mercado Pago.', 'jet-form-builder-mercadopago-gateway' ),
					'pmChooseFirst' => __( 'Escolha um formulário acima para configurar e sincronizar os meios de pagamento.', 'jet-form-builder-mercadopago-gateway' ),
					'pmDefaultNote' => __( 'Este formulário ainda não tem meios definidos: por padrão, aceita apenas cartões de crédito (e o saldo Mercado Pago, que não pode ser desativado). Sincronize e salve para personalizar.', 'jet-form-builder-mercadopago-gateway' ),
					'pmAlwaysOn'    => __( 'Sempre disponível — o Mercado Pago não permite desativar o saldo em conta no Checkout Pro.', 'jet-form-builder-mercadopago-gateway' ),
					'pmAsyncNote'   => __( 'Pix e boleto são assíncronos: a venda é confirmada quando o cliente paga (via webhook do Mercado Pago). Confirme que o webhook/notificações estão ativos.', 'jet-form-builder-mercadopago-gateway' ),
				),
			)
		);
	}
}
