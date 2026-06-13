<?php
/**
 * Plugin Name: CSV to JetEngine CPT Custom Meta Storage Importer
 * Plugin URI: https://softemblems.com
 * Description: Import CSV files into JetEngine Custom Meta Storage tables for Custom Post Types.
 * Version: 0.1.0
 * Author: Douglas / Adapted Project
 * License: GPLv2 or later
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
	exit;
}

class CSV_Metastorage_CPT_Importer {
	private const NONCE_ACTION = 'csv_metastorage_cpt_import';
	private const NONCE_NAME   = 'csv_metastorage_cpt_nonce';

	private const SHORTCODE = 'import_metastorage_cpt_csv';

	public function __construct() {
		add_shortcode(self::SHORTCODE, [$this, 'render_import_ui']);
		add_action('admin_menu', [$this, 'add_admin_page']);
	}

	public function add_admin_page(): void {
		add_menu_page(
			'CSV to CPT Meta Storage Importer',
			'CPT Meta Importer',
			'manage_options',
			'csv-metastorage-cpt-import',
			[$this, 'admin_page_html'],
			'dashicons-database-import',
			80
		);
	}

	public function admin_page_html(): void {
		echo '<div class="wrap" style="max-width:900px">';
		echo '<h1>CSV to JetEngine CPT Custom Meta Storage Importer</h1>';
		echo '<p><strong>Frontend Shortcode:</strong> <code>[' . esc_html(self::SHORTCODE) . ']</code></p>';
		echo $this->import_ui_html();
		echo '</div>';
	}

	public function render_import_ui(): string {
		if (!current_user_can('manage_options')) {
			return '<div class="csv-import-wrap"><p>Você não tem permissão para importar dados.</p></div>';
		}

		return '<div class="csv-import-wrap" style="max-width:760px">' . $this->import_ui_html() . '</div>';
	}

	private function import_ui_html(): string {
		ob_start();

		if (!current_user_can('manage_options')) {
			echo '<div class="notice notice-error"><p>Você não tem permissão para acessar este importador.</p></div>';
			return ob_get_clean();
		}

		$step = isset($_POST['csv_ms_step'])
			? sanitize_key(wp_unslash($_POST['csv_ms_step']))
			: 'upload_and_select';

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (
				!isset($_POST[self::NONCE_NAME]) ||
				!wp_verify_nonce(
					sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])),
					self::NONCE_ACTION
				)
			) {
				echo '<div class="notice notice-error"><p>Falha de segurança. Recarregue a página e tente novamente.</p></div>';
				return ob_get_clean();
			}
		}

		if ($step === 'upload_and_select') {
			$this->render_upload_step();
		} elseif ($step === 'map_columns') {
			$this->render_mapping_step();
		} elseif ($step === 'run_import') {
			$this->run_import_step();
		} else {
			echo '<div class="notice notice-error"><p>Etapa inválida.</p></div>';
			$this->render_upload_step();
		}

		return ob_get_clean();
	}

	private function render_upload_step(): void {
		$tables     = $this->get_custom_meta_storage_tables();
		$post_types = $this->get_available_custom_post_types();

		if (empty($tables)) {
			echo '<div class="notice notice-warning"><p>Nenhuma tabela com estrutura parecida com JetEngine Custom Meta Storage foi encontrada.</p></div>';
		}

		if (empty($post_types)) {
			echo '<div class="notice notice-warning"><p>Nenhum Custom Post Type registrado foi encontrado.</p></div>';
		}

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

		echo '<input type="hidden" name="csv_ms_step" value="upload_and_select">';

		echo '<h3>Step 1: Upload CSV & Select CPT Meta Storage Table</h3>';

		echo '<p>';
		echo '<label><strong>Escolha a tabela de Custom Meta Storage:</strong><br>';
		echo '<select name="selected_table" required>';
		echo '<option value="">-- Escolha --</option>';

		foreach ($tables as $table => $data) {
			$label = $table;

			if (!empty($data['fields'])) {
				$label .= ' — ' . count($data['fields']) . ' campos detectados';
			}

			echo '<option value="' . esc_attr($table) . '">' . esc_html($label) . '</option>';
		}

		echo '</select>';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><strong>Escolha o Custom Post Type que será criado:</strong><br>';
		echo '<select name="selected_post_type" required>';
		echo '<option value="">-- Escolha --</option>';

		foreach ($post_types as $post_type => $object) {
			$label = $object->labels->singular_name ?: $object->label;
			echo '<option value="' . esc_attr($post_type) . '">' . esc_html($label . ' (' . $post_type . ')') . '</option>';
		}

		echo '</select>';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><strong>Status padrão dos posts criados:</strong><br>';
		echo '<select name="default_post_status">';
		echo '<option value="draft">Rascunho</option>';
		echo '<option value="publish">Publicado</option>';
		echo '<option value="pending">Pendente</option>';
		echo '<option value="private">Privado</option>';
		echo '</select>';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><strong>Separador do CSV:</strong><br>';
		echo '<select name="csv_delimiter">';
		echo '<option value=",">Vírgula (,)</option>';
		echo '<option value=";">Ponto e vírgula (;)</option>';
		echo '<option value="tab">Tabulação</option>';
		echo '</select>';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label><strong>Upload CSV File:</strong><br>';
		echo '<input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required>';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="has_header" value="yes" checked> Primeira linha contém cabeçalhos';
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<button type="submit" class="button button-primary">Continuar para mapear campos</button>';
		echo '</p>';

		echo '</form>';
	}

	private function render_mapping_step(): void {
		if (empty($_FILES['csv_file']['tmp_name'])) {
			echo '<div class="notice notice-error"><p>Nenhum arquivo CSV foi enviado.</p></div>';
			$this->render_upload_step();
			return;
		}

		$selected_table = isset($_POST['selected_table'])
			? sanitize_text_field(wp_unslash($_POST['selected_table']))
			: '';

		$selected_post_type = isset($_POST['selected_post_type'])
			? sanitize_key(wp_unslash($_POST['selected_post_type']))
			: '';

		$default_post_status = isset($_POST['default_post_status'])
			? sanitize_key(wp_unslash($_POST['default_post_status']))
			: 'draft';

		$has_header = isset($_POST['has_header']) && $_POST['has_header'] === 'yes';

		$delimiter = $this->get_delimiter_from_request();

		if (!$this->is_allowed_meta_storage_table($selected_table)) {
			echo '<div class="notice notice-error"><p>Tabela inválida ou não permitida.</p></div>';
			$this->render_upload_step();
			return;
		}

		if (!post_type_exists($selected_post_type)) {
			echo '<div class="notice notice-error"><p>Post type inválido.</p></div>';
			$this->render_upload_step();
			return;
		}

		$upload = wp_handle_upload(
			$_FILES['csv_file'],
			[
				'test_form' => false,
				'mimes'     => [
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				],
			]
		);

		if (empty($upload['file'])) {
			echo '<div class="notice notice-error"><p>Falha no upload do arquivo CSV.</p></div>';
			$this->render_upload_step();
			return;
		}

		$csv_path = $upload['file'];

		if (!$this->is_safe_uploaded_file_path($csv_path)) {
			echo '<div class="notice notice-error"><p>Caminho de arquivo inválido.</p></div>';
			return;
		}

		$headers = $this->read_first_csv_row($csv_path, $delimiter);

		if (empty($headers)) {
			echo '<div class="notice notice-error"><p>Não foi possível ler a primeira linha do CSV.</p></div>';
			return;
		}

		$table_columns = $this->get_table_columns($selected_table);

		$meta_columns = array_filter(
			$table_columns,
			static function ($column) {
				return !in_array($column, ['meta_ID', 'meta_id', 'object_ID', 'object_id'], true);
			}
		);

		$post_fields = [
			'post_title'   => 'Título do post',
			'post_name'    => 'Slug do post',
			'post_content' => 'Conteúdo',
			'post_excerpt' => 'Resumo',
			'post_status'  => 'Status',
			'post_date'    => 'Data',
			'post_author'  => 'Autor ID',
			'post_parent'  => 'Post Parent ID',
			'menu_order'   => 'Menu Order',
		];

		echo '<form method="post">';
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

		echo '<input type="hidden" name="csv_ms_step" value="run_import">';
		echo '<input type="hidden" name="csv_path" value="' . esc_attr($csv_path) . '">';
		echo '<input type="hidden" name="selected_table" value="' . esc_attr($selected_table) . '">';
		echo '<input type="hidden" name="selected_post_type" value="' . esc_attr($selected_post_type) . '">';
		echo '<input type="hidden" name="default_post_status" value="' . esc_attr($default_post_status) . '">';
		echo '<input type="hidden" name="csv_delimiter" value="' . esc_attr($this->get_delimiter_request_value()) . '">';
		echo '<input type="hidden" name="has_header" value="' . esc_attr($has_header ? 'yes' : 'no') . '">';

		echo '<h3>Step 2: Map CSV Columns</h3>';

		echo '<p>';
		echo 'Tabela selecionada: <code>' . esc_html($selected_table) . '</code><br>';
		echo 'Post type selecionado: <code>' . esc_html($selected_post_type) . '</code>';
		echo '</p>';

		foreach ($headers as $index => $header) {
			$label = $has_header ? $header : 'Column ' . ($index + 1);

			echo '<div style="margin:0 0 16px 0;padding:12px;border:1px solid #ddd;background:#fff;">';
			echo '<label>';
			echo '<strong>' . esc_html($label) . '</strong><br>';
			echo '<select name="column_map[' . esc_attr($index) . ']" style="min-width:320px;">';

			echo '<option value="">-- Ignorar --</option>';

			echo '<optgroup label="Campos principais do post">';
			foreach ($post_fields as $field_key => $field_label) {
				echo '<option value="' . esc_attr('post:' . $field_key) . '">' . esc_html($field_label . ' (' . $field_key . ')') . '</option>';
			}
			echo '</optgroup>';

			echo '<optgroup label="Campos da tabela Custom Meta Storage">';
			foreach ($meta_columns as $column) {
				echo '<option value="' . esc_attr('meta:' . $column) . '">' . esc_html($this->beautify_column_label($column) . ' (' . $column . ')') . '</option>';
			}
			echo '</optgroup>';

			echo '</select>';
			echo '</label>';
			echo '</div>';
		}

		echo '<p>';
		echo '<button type="submit" class="button button-primary">Importar posts</button>';
		echo '</p>';

		echo '</form>';
	}

	private function run_import_step(): void {
		global $wpdb;

		$csv_path = isset($_POST['csv_path'])
			? sanitize_text_field(wp_unslash($_POST['csv_path']))
			: '';

		$selected_table = isset($_POST['selected_table'])
			? sanitize_text_field(wp_unslash($_POST['selected_table']))
			: '';

		$selected_post_type = isset($_POST['selected_post_type'])
			? sanitize_key(wp_unslash($_POST['selected_post_type']))
			: '';

		$default_post_status = isset($_POST['default_post_status'])
			? sanitize_key(wp_unslash($_POST['default_post_status']))
			: 'draft';

		$column_map = isset($_POST['column_map']) && is_array($_POST['column_map'])
			? array_map('sanitize_text_field', wp_unslash($_POST['column_map']))
			: [];

		$has_header = isset($_POST['has_header']) && $_POST['has_header'] === 'yes';

		$delimiter = $this->get_delimiter_from_request();

		if (!$this->is_safe_uploaded_file_path($csv_path) || !file_exists($csv_path)) {
			echo '<div class="notice notice-error"><p>Arquivo temporário não encontrado ou inválido. Faça o upload novamente.</p></div>';
			return;
		}

		if (!$this->is_allowed_meta_storage_table($selected_table)) {
			echo '<div class="notice notice-error"><p>Tabela inválida ou não permitida.</p></div>';
			return;
		}

		if (!post_type_exists($selected_post_type)) {
			echo '<div class="notice notice-error"><p>Post type inválido.</p></div>';
			return;
		}

		$allowed_statuses = ['draft', 'publish', 'pending', 'private'];
		if (!in_array($default_post_status, $allowed_statuses, true)) {
			$default_post_status = 'draft';
		}

		$table_columns = $this->get_table_columns($selected_table);

		$object_id_column = in_array('object_ID', $table_columns, true)
			? 'object_ID'
			: 'object_id';

		$imported_count = 0;
		$skipped_count  = 0;
		$error_count    = 0;
		$errors         = [];

		$handle = fopen($csv_path, 'r');

		if (!$handle) {
			echo '<div class="notice notice-error"><p>Não foi possível abrir o CSV.</p></div>';
			return;
		}

		if ($has_header) {
			fgetcsv($handle, 0, $delimiter);
		}

		while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
			if ($this->is_empty_csv_row($row)) {
				$skipped_count++;
				continue;
			}

			$post_data = [
				'post_type'   => $selected_post_type,
				'post_status' => $default_post_status,
			];

			$meta_data = [];

			foreach ($column_map as $csv_index => $target) {
				if ($target === '') {
					continue;
				}

				$value = $row[$csv_index] ?? '';
				$value = $this->normalize_csv_value($value);

				if (str_starts_with($target, 'post:')) {
					$post_field = substr($target, 5);
					$post_data[$post_field] = $value;
					continue;
				}

				if (str_starts_with($target, 'meta:')) {
					$meta_field = substr($target, 5);

					if (in_array($meta_field, $table_columns, true)) {
						$meta_data[$meta_field] = $value;
					}
				}
			}

			if (empty($post_data['post_title'])) {
				$post_data['post_title'] = 'Imported ' . $selected_post_type . ' - ' . current_time('mysql');
			}

			$post_id = wp_insert_post(wp_slash($post_data), true);

			if (is_wp_error($post_id)) {
				$error_count++;
				$errors[] = $post_id->get_error_message();
				continue;
			}

			$meta_data[$object_id_column] = (int) $post_id;

			$inserted = $wpdb->insert($selected_table, $meta_data);

			if ($inserted === false) {
				$error_count++;
				$errors[] = 'Erro ao inserir metadados para o post ID ' . $post_id . ': ' . $wpdb->last_error;

				wp_delete_post($post_id, true);
				continue;
			}

			clean_post_cache($post_id);

			$imported_count++;
		}

		fclose($handle);
		@unlink($csv_path);

		echo '<div class="notice notice-success is-dismissible">';
		echo '<p><strong>Importação concluída.</strong></p>';
		echo '<p>';
		echo 'Posts importados: <strong>' . esc_html((string) $imported_count) . '</strong><br>';
		echo 'Linhas ignoradas: <strong>' . esc_html((string) $skipped_count) . '</strong><br>';
		echo 'Erros: <strong>' . esc_html((string) $error_count) . '</strong>';
		echo '</p>';
		echo '</div>';

		if (!empty($errors)) {
			echo '<div class="notice notice-error">';
			echo '<p><strong>Erros encontrados:</strong></p>';
			echo '<ul>';

			foreach (array_slice($errors, 0, 20) as $error) {
				echo '<li>' . esc_html($error) . '</li>';
			}

			echo '</ul>';

			if (count($errors) > 20) {
				echo '<p>Existem mais erros além dos 20 primeiros exibidos.</p>';
			}

			echo '</div>';
		}
	}

	private function get_custom_meta_storage_tables(): array {
		global $wpdb;

		$tables = [];
		$all_tables = $wpdb->get_col('SHOW TABLES');

		if (empty($all_tables)) {
			return [];
		}

		foreach ($all_tables as $table) {
			if (!$this->starts_with($table, $wpdb->prefix)) {
				continue;
			}

			if ($this->is_core_wp_table($table)) {
				continue;
			}

			$columns = $this->get_table_columns($table);

			$has_object_id = in_array('object_ID', $columns, true) || in_array('object_id', $columns, true);
			$has_meta_id   = in_array('meta_ID', $columns, true) || in_array('meta_id', $columns, true);

			if (!$has_object_id || !$has_meta_id) {
				continue;
			}

			$fields = array_filter(
				$columns,
				static function ($column) {
					return !in_array($column, ['meta_ID', 'meta_id', 'object_ID', 'object_id'], true);
				}
			);

			$tables[$table] = [
				'columns' => $columns,
				'fields'  => array_values($fields),
			];
		}

		return $tables;
	}

	private function get_available_custom_post_types(): array {
		$post_types = get_post_types(
			[
				'_builtin' => false,
			],
			'objects'
		);

		unset($post_types['elementor_library']);
		unset($post_types['jet-engine']);
		unset($post_types['jet-smart-filters']);

		return $post_types;
	}

	private function is_allowed_meta_storage_table(string $table): bool {
		$tables = $this->get_custom_meta_storage_tables();

		return isset($tables[$table]);
	}

	private function get_table_columns(string $table): array {
		global $wpdb;

		if ($table === '') {
			return [];
		}

		$quoted_table = $this->quote_identifier($table);

		$results = $wpdb->get_results("SHOW COLUMNS FROM {$quoted_table}", ARRAY_A);

		if (empty($results)) {
			return [];
		}

		return array_map(
			static function ($column) {
				return $column['Field'];
			},
			$results
		);
	}

	private function quote_identifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	private function is_core_wp_table(string $table): bool {
		global $wpdb;

		$core_tables = [
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->terms,
			$wpdb->termmeta,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->options,
			$wpdb->links,
		];

		return in_array($table, $core_tables, true);
	}

	private function read_first_csv_row(string $csv_path, string $delimiter): array {
		$handle = fopen($csv_path, 'r');

		if (!$handle) {
			return [];
		}

		$row = fgetcsv($handle, 0, $delimiter);

		fclose($handle);

		if (!is_array($row)) {
			return [];
		}

		return $row;
	}

	private function get_delimiter_from_request(): string {
		$value = $this->get_delimiter_request_value();

		if ($value === ';') {
			return ';';
		}

		if ($value === 'tab') {
			return "\t";
		}

		return ',';
	}

	private function get_delimiter_request_value(): string {
		if (!isset($_POST['csv_delimiter'])) {
			return ',';
		}

		$value = sanitize_text_field(wp_unslash($_POST['csv_delimiter']));

		if ($value === ';' || $value === 'tab') {
			return $value;
		}

		return ',';
	}

	private function is_safe_uploaded_file_path(string $path): bool {
		if ($path === '') {
			return false;
		}

		$uploads = wp_get_upload_dir();

		if (empty($uploads['basedir'])) {
			return false;
		}

		$real_path = realpath($path);
		$real_base = realpath($uploads['basedir']);

		if (!$real_path || !$real_base) {
			return false;
		}

		return $this->starts_with($real_path, $real_base);
	}

	private function is_empty_csv_row(array $row): bool {
		foreach ($row as $value) {
			if (trim((string) $value) !== '') {
				return false;
			}
		}

		return true;
	}

	private function normalize_csv_value($value): string {
		$value = (string) $value;
		$value = trim($value);

		if (function_exists('mb_convert_encoding')) {
			$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
		}

		return $value;
	}

	private function beautify_column_label(string $column): string {
		return ucwords(str_replace(['_', '-'], ' ', $column));
	}

	private function starts_with(string $haystack, string $needle): bool {
		return substr($haystack, 0, strlen($needle)) === $needle;
	}
}

new CSV_Metastorage_CPT_Importer();