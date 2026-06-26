<?php
/**
 * ============================================================================
 *  Plans_Page  —  Página admin "MP Planos" (criar / listar / excluir planos)
 * ============================================================================
 *
 *  Gerencia planos de assinatura via API (preapproval_plan) SEM terminal. Os
 *  planos do PAINEL do Mercado Pago não aparecem na API; só os criados aqui
 *  (preapproval_plan) populam o dropdown do cenário de assinatura.
 *
 *  Endpoints REST consumidos (jet-form-builder/v1):
 *    - fetch-mercadopago-plans   (listar)
 *    - create-mercadopago-plan   (criar)
 *    - delete-mercadopago-plan   (cancelar/desativar)
 *
 *  @package Jet_FB_Mercadopago_Gateway
 */

namespace Jet_FB_Mercadopago_Gateway\Admin;

use Jet_FB_Mercadopago_Gateway\Compatibility\Jet_Form_Builder\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plans_Page {

	const SLUG     = 'jfb-mp-plans';
	const REST_NS  = 'jet-form-builder/v1';
	const HOOK     = 'toplevel_page_jfb-mp-plans';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Mercado Pago — Planos de Assinatura', 'jet-form-builder-mercadopago-gateway' ),
			__( 'MP Planos', 'jet-form-builder-mercadopago-gateway' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-money-alt',
			58
		);
	}

	/**
	 * Token global do gateway (campo 'secret' = Access Token), pré-preenchido.
	 *
	 * @return string
	 */
	private static function global_token(): string {
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

	public static function enqueue( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		$handle = 'jfb-mp-plans-admin';

		wp_enqueue_script(
			$handle,
			JET_FB_MERCADOPAGO_GATEWAY_URL . 'assets/js/mp-plans-admin.js',
			array(),
			JET_FB_MERCADOPAGO_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'JFB_MP_PLANS',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'token' => self::global_token(),
				'urls'  => array(
					'list'   => rest_url( self::REST_NS . '/fetch-mercadopago-plans' ),
					'create' => rest_url( self::REST_NS . '/create-mercadopago-plan' ),
					'delete' => rest_url( self::REST_NS . '/delete-mercadopago-plan' ),
				),
				'i18n'  => array(
					'confirmDelete' => __( 'Cancelar/desativar este plano no Mercado Pago? Assinaturas já ativas continuam; o plano só deixa de aceitar novas.', 'jet-form-builder-mercadopago-gateway' ),
					'loading'       => __( 'Carregando…', 'jet-form-builder-mercadopago-gateway' ),
					'empty'         => __( 'Nenhum plano de API nesta conta. Crie um abaixo.', 'jet-form-builder-mercadopago-gateway' ),
					'created'       => __( 'Plano criado com sucesso!', 'jet-form-builder-mercadopago-gateway' ),
					'deleted'       => __( 'Plano cancelado.', 'jet-form-builder-mercadopago-gateway' ),
					'noToken'       => __( 'Cole o Access Token primeiro.', 'jet-form-builder-mercadopago-gateway' ),
					'delete'        => __( 'Excluir', 'jet-form-builder-mercadopago-gateway' ),
					'copy'          => __( 'Copiar ID', 'jet-form-builder-mercadopago-gateway' ),
				),
			)
		);
	}

	public static function render() {
		$token = self::global_token();
		?>
		<div class="wrap jfb-mp-plans">
			<h1><?php esc_html_e( 'Mercado Pago — Planos de Assinatura', 'jet-form-builder-mercadopago-gateway' ); ?></h1>

			<p style="max-width:760px">
				<?php esc_html_e( 'Crie, veja e exclua os planos de assinatura (preapproval_plan) da API. IMPORTANTE: os "Planos" criados no PAINEL do Mercado Pago NÃO aparecem na API e NÃO servem para a integração — use os daqui. Os planos abaixo é que populam o dropdown do cenário "Subscription" no editor do formulário.', 'jet-form-builder-mercadopago-gateway' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jfb-mp-token"><?php esc_html_e( 'Access Token', 'jet-form-builder-mercadopago-gateway' ); ?></label></th>
					<td>
						<input type="text" id="jfb-mp-token" class="regular-text code" style="width:520px"
							value="<?php echo esc_attr( $token ); ?>"
							placeholder="APP_USR-... ou TEST-..." autocomplete="off" spellcheck="false" />
						<p class="description">
							<?php esc_html_e( 'Use o MESMO token que está no gateway do formulário (mesma conta/aplicação). Vem pré-preenchido com o token global, se houver.', 'jet-form-builder-mercadopago-gateway' ); ?>
						</p>
						<button type="button" class="button" id="jfb-mp-refresh"><?php esc_html_e( 'Atualizar lista', 'jet-form-builder-mercadopago-gateway' ); ?></button>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Planos existentes', 'jet-form-builder-mercadopago-gateway' ); ?></h2>
			<table class="widefat striped" id="jfb-mp-plans-table">
				<thead>
					<tr>
						<th style="width:34%"><?php esc_html_e( 'Descrição', 'jet-form-builder-mercadopago-gateway' ); ?></th>
						<th><?php esc_html_e( 'Valor', 'jet-form-builder-mercadopago-gateway' ); ?></th>
						<th><?php esc_html_e( 'Frequência', 'jet-form-builder-mercadopago-gateway' ); ?></th>
						<th><?php esc_html_e( 'Status', 'jet-form-builder-mercadopago-gateway' ); ?></th>
						<th><?php esc_html_e( 'ID', 'jet-form-builder-mercadopago-gateway' ); ?></th>
						<th><?php esc_html_e( 'Ações', 'jet-form-builder-mercadopago-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody id="jfb-mp-plans-body">
					<tr><td colspan="6"><?php esc_html_e( 'Carregando…', 'jet-form-builder-mercadopago-gateway' ); ?></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Criar novo plano', 'jet-form-builder-mercadopago-gateway' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jfb-mp-reason"><?php esc_html_e( 'Nome / descrição', 'jet-form-builder-mercadopago-gateway' ); ?></label></th>
					<td><input type="text" id="jfb-mp-reason" class="regular-text" placeholder="<?php esc_attr_e( 'Ex.: Plano Mensal Premium', 'jet-form-builder-mercadopago-gateway' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jfb-mp-amount"><?php esc_html_e( 'Valor', 'jet-form-builder-mercadopago-gateway' ); ?></label></th>
					<td><input type="number" id="jfb-mp-amount" step="0.01" min="0.5" style="width:140px" placeholder="10.00" /> <span class="description"><?php esc_html_e( 'por cobrança', 'jet-form-builder-mercadopago-gateway' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Frequência', 'jet-form-builder-mercadopago-gateway' ); ?></label></th>
					<td>
						<?php esc_html_e( 'A cada', 'jet-form-builder-mercadopago-gateway' ); ?>
						<input type="number" id="jfb-mp-frequency" min="1" value="1" style="width:80px" />
						<select id="jfb-mp-frequency-type">
							<option value="months"><?php esc_html_e( 'mês(es)', 'jet-form-builder-mercadopago-gateway' ); ?></option>
							<option value="days"><?php esc_html_e( 'dia(s)', 'jet-form-builder-mercadopago-gateway' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( '(dica: use "dias" p/ testar renovação rápido)', 'jet-form-builder-mercadopago-gateway' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jfb-mp-currency"><?php esc_html_e( 'Moeda', 'jet-form-builder-mercadopago-gateway' ); ?></label></th>
					<td><input type="text" id="jfb-mp-currency" value="BRL" style="width:90px" maxlength="3" /></td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="jfb-mp-create"><?php esc_html_e( 'Criar plano', 'jet-form-builder-mercadopago-gateway' ); ?></button>
			</p>

			<div id="jfb-mp-notice" style="margin-top:1em;font-size:13px"></div>
		</div>
		<?php
	}
}
