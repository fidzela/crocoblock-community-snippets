/**
 * Secure Meta Fields
 *   - SMF_CPT  → CPT inteiro: edição + listagem (colunas) + quick edit
 *   - SMF_CCT  → CCT inteiro: edição + listagem (colunas) + quick edit (mascaramento)
 *
 * v1.3 — Bloqueio de submit quando há campos protegidos sem desbloquear.
 *        Reforço do valor após desbloqueio (sincroniza atributo + dispara events).
 *
 * Constante necessária no wp-config.php:
 *   define( 'SECURE_META_ADMIN_PASSWORD', 'sua-senha-forte' );
 */


/* ========================================================================== *
 *                                                                            *
 *   CLASSE 1 — SMF_CPT — Custom Post Types                                   *
 *   Cobre: edit screen, listagem (colunas) e quick edit                      *
 *                                                                            *
 * ========================================================================== */

class SMF_CPT {

    /** Configuração: 'post_type' => [ 'meta_key', ... ] */
    private $protected = [
        'code' => [ 'codigo', 'senha_temporaria', 'login_temporario_codigo' ],
    ];

    const MAX_ATTEMPTS       = 5;
    const LOCKOUT_SECONDS    = 30 * MINUTE_IN_SECONDS;
    const UNLOCK_TTL_SECONDS = 5  * MINUTE_IN_SECONDS;
    const PLACEHOLDER        = '••••••••••••';
    const MASK_LIST          = '••••••••••••';

    public function __construct() {
        add_action( 'admin_enqueue_scripts',     [ $this, 'enqueue_edit' ] );
        add_action( 'admin_footer-post.php',     [ $this, 'render_modal' ] );
        add_action( 'admin_footer-post-new.php', [ $this, 'render_modal' ] );

        add_action( 'wp_ajax_smf_cpt_unlock',     [ $this, 'ajax_unlock' ] );
        add_action( 'wp_ajax_smf_cpt_unlock_all', [ $this, 'ajax_unlock_all' ] );
        add_action( 'wp_ajax_smf_cpt_lock',       [ $this, 'ajax_lock' ] );
        add_action( 'wp_ajax_smf_cpt_reveal',     [ $this, 'ajax_reveal' ] );

        add_action( 'admin_head',   [ $this, 'maybe_start_buffer_edit' ], 1 );
        add_action( 'admin_footer', [ $this, 'maybe_end_buffer_edit' ], PHP_INT_MAX );

        add_action( 'admin_init', [ $this, 'register_column_filters' ] );
        add_action( 'admin_head-edit.php',   [ $this, 'maybe_start_buffer_list' ], 1 );
        add_action( 'admin_footer-edit.php', [ $this, 'maybe_end_buffer_list' ], PHP_INT_MAX );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list' ] );
    }

    /* =========================================================================
     *  HELPERS
     * ========================================================================= */

    private function is_protected( $post_type, $field ) {
        return isset( $this->protected[ $post_type ] )
            && in_array( $field, $this->protected[ $post_type ], true );
    }
    private function unlock_key( $post_id, $field ) {
        return 'smf_cpt_unlock_' . get_current_user_id() . '_' . $post_id . '_' . md5( $field );
    }
    private function attempts_key() {
        return 'smf_cpt_attempts_' . get_current_user_id();
    }
    private function is_unlocked( $post_id, $field ) {
        return (bool) get_transient( $this->unlock_key( $post_id, $field ) );
    }

    private function read_value( $post_type, $post_id, $field ) {
        global $wpdb;
        $val = get_post_meta( $post_id, $field, true );
        if ( $val !== '' && $val !== null ) return $val;

        $table  = $wpdb->prefix . $post_type . '_meta';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $row = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM `{$table}` WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id, $field
            ) );
            if ( $row !== null ) return $row;
        }
        return $val;
    }

    /* =========================================================================
     *  DETECÇÃO
     * ========================================================================= */

    private function detect_edit() {
        global $pagenow;
        if ( $pagenow === 'post.php' && isset( $_GET['action'] ) && $_GET['action'] === 'edit' && isset( $_GET['post'] ) ) {
            $post_id   = (int) $_GET['post'];
            $post_type = get_post_type( $post_id );
            if ( $post_type && isset( $this->protected[ $post_type ] ) ) {
                return [
                    'post_type' => $post_type,
                    'post_id'   => $post_id,
                    'fields'    => $this->protected[ $post_type ],
                ];
            }
        }
        if ( $pagenow === 'post-new.php' && isset( $_GET['post_type'] ) ) {
            $post_type = sanitize_key( $_GET['post_type'] );
            if ( isset( $this->protected[ $post_type ] ) ) {
                return [
                    'post_type' => $post_type,
                    'post_id'   => 0,
                    'fields'    => $this->protected[ $post_type ],
                ];
            }
        }
        return null;
    }

    private function detect_list() {
        global $pagenow;
        if ( $pagenow !== 'edit.php' ) return null;
        $pt = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
        if ( ! isset( $this->protected[ $pt ] ) ) return null;
        return [ 'post_type' => $pt, 'fields' => $this->protected[ $pt ] ];
    }

    /* =========================================================================
     *  EDIÇÃO — Output buffering
     * ========================================================================= */

    public function maybe_start_buffer_edit() {
        if ( ! $this->detect_edit() ) return;
        ob_start( [ $this, 'filter_edit_html' ] );
    }
    public function maybe_end_buffer_edit() {
        if ( ob_get_level() === 0 || ! $this->detect_edit() ) return;
        @ob_end_flush();
    }
    public function filter_edit_html( $html ) {
        $ctx = $this->detect_edit();
        if ( ! $ctx ) return $html;
        foreach ( $ctx['fields'] as $field ) {
            $pattern = '/<input\b([^>]*\bname=(["\'])' . preg_quote( $field, '/' ) . '\2[^>]*)>/i';
            $html = preg_replace_callback( $pattern, function( $m ) {
                $attrs = preg_replace( '/\svalue=(["\'])[^"\']*\1/i', '', $m[1] );
                return '<input' . $attrs . ' value="">';
            }, $html );
        }
        return $html;
    }

    /* =========================================================================
     *  EDIÇÃO — Assets, modal, AJAX
     * ========================================================================= */

    public function enqueue_edit( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        $ctx = $this->detect_edit();
        if ( ! $ctx ) return;

        $unlock_state = [];
        foreach ( $ctx['fields'] as $field ) {
            $unlock_state[ $field ] = $this->is_unlocked( $ctx['post_id'], $field );
        }

        wp_register_script( 'smf-cpt-edit', '', [ 'jquery' ], '1.3', true );
        wp_enqueue_script( 'smf-cpt-edit' );

        wp_localize_script( 'smf-cpt-edit', 'SMF_CPT_DATA', [
            'ajax'        => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'smf_cpt_nonce' ),
            'postType'    => $ctx['post_type'],
            'postId'      => $ctx['post_id'],
            'fields'      => $ctx['fields'],
            'unlocked'    => $unlock_state,
            'placeholder' => self::PLACEHOLDER,
        ] );

        wp_add_inline_script( 'smf-cpt-edit', $this->js_edit() );
        wp_add_inline_style( 'wp-admin', $this->css_edit() );
    }

    public function render_modal() {
        if ( ! $this->detect_edit() ) return;
        ?>
        <div id="smf-cpt-modal" class="smf-cpt-modal" aria-hidden="true">
          <div class="smf-cpt-modal__backdrop"></div>
          <form class="smf-cpt-modal__dialog" role="dialog" aria-modal="true" onsubmit="return false;">
            <h2>Desbloquear campo</h2>
            <p class="smf-cpt-modal__desc">Informe a senha administrativa para revelar este valor.</p>
            <input type="password" id="smf-cpt-modal-input" autocomplete="current-password" />
            <label class="smf-cpt-modal__check">
              <input type="checkbox" id="smf-cpt-modal-all" checked />
              <span>Desbloquear todos os campos protegidos desta página</span>
            </label>
            <div class="smf-cpt-modal__msg" aria-live="polite"></div>
            <div class="smf-cpt-modal__actions">
              <button type="button" class="button" id="smf-cpt-modal-cancel">Cancelar</button>
              <button type="button" class="button button-primary" id="smf-cpt-modal-submit">Desbloquear</button>
            </div>
          </form>
        </div>
        <?php
    }

    private function validate( $require_field = true ) {
        check_ajax_referer( 'smf_cpt_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão.' ], 403 );
        }
        $post_id   = isset( $_POST['post_id'] )   ? (int) $_POST['post_id'] : 0;
        $post_type = get_post_type( $post_id );
        if ( ! $post_type || ! isset( $this->protected[ $post_type ] ) ) {
            wp_send_json_error( [ 'message' => 'Post inválido.' ], 400 );
        }
        $field = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
        if ( $require_field && ! $this->is_protected( $post_type, $field ) ) {
            wp_send_json_error( [ 'message' => 'Campo inválido.' ], 400 );
        }
        return compact( 'post_id', 'post_type', 'field' );
    }

    private function check_password_and_attempts() {
        if ( ! defined( 'SECURE_META_ADMIN_PASSWORD' ) ) {
            wp_send_json_error( [ 'message' => 'Senha admin não configurada.' ], 500 );
        }
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

        $attempts = (int) get_transient( $this->attempts_key() );
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            wp_send_json_error( [ 'locked_out' => true, 'message' => 'Muitas tentativas.' ], 429 );
        }
        if ( ! hash_equals( SECURE_META_ADMIN_PASSWORD, $password ) ) {
            $attempts++;
            set_transient( $this->attempts_key(), $attempts, self::LOCKOUT_SECONDS );
            $remaining = max( 0, self::MAX_ATTEMPTS - $attempts );
            wp_send_json_error( [
                'remaining'  => $remaining,
                'locked_out' => $attempts >= self::MAX_ATTEMPTS,
                'message'    => 'Senha incorreta.',
            ], 401 );
        }
        delete_transient( $this->attempts_key() );
    }

    public function ajax_unlock() {
        $d = $this->validate( true );
        $this->check_password_and_attempts();
        set_transient( $this->unlock_key( $d['post_id'], $d['field'] ), 1, self::UNLOCK_TTL_SECONDS );
        wp_send_json_success( [
            'ok'    => true,
            'value' => (string) $this->read_value( $d['post_type'], $d['post_id'], $d['field'] ),
        ] );
    }
    public function ajax_unlock_all() {
        $d = $this->validate( false );
        $this->check_password_and_attempts();
        $values = [];
        foreach ( $this->protected[ $d['post_type'] ] as $field ) {
            set_transient( $this->unlock_key( $d['post_id'], $field ), 1, self::UNLOCK_TTL_SECONDS );
            $values[ $field ] = (string) $this->read_value( $d['post_type'], $d['post_id'], $field );
        }
        wp_send_json_success( [ 'ok' => true, 'values' => $values ] );
    }
    public function ajax_reveal() {
        $d = $this->validate( true );
        if ( ! $this->is_unlocked( $d['post_id'], $d['field'] ) ) {
            wp_send_json_error( [ 'message' => 'Bloqueado.' ], 403 );
        }
        wp_send_json_success( [
            'value' => (string) $this->read_value( $d['post_type'], $d['post_id'], $d['field'] ),
        ] );
    }
    public function ajax_lock() {
        $d = $this->validate( true );
        delete_transient( $this->unlock_key( $d['post_id'], $d['field'] ) );
        wp_send_json_success();
    }

    /* =========================================================================
     *  LISTAGEM — Colunas + Quick Edit
     * ========================================================================= */

    public function register_column_filters() {
        foreach ( array_keys( $this->protected ) as $post_type ) {
            add_action(
                "manage_{$post_type}_posts_custom_column",
                [ $this, 'mask_column' ],
                1, 2
            );
        }
    }

    public function mask_column( $column, $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( ! isset( $this->protected[ $post_type ] ) ) return;
        if ( ! in_array( $column, $this->protected[ $post_type ], true ) ) return;

        echo '<span class="smf-cpt-masked">' . esc_html( self::MASK_LIST ) . '</span>';

        ob_start();
        add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'discard_column_buffer' ], PHP_INT_MAX, 2 );
    }

    public function discard_column_buffer( $column, $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( ! isset( $this->protected[ $post_type ] ) ) return;
        if ( ! in_array( $column, $this->protected[ $post_type ], true ) ) return;
        if ( ob_get_level() > 0 ) ob_end_clean();
    }

    public function maybe_start_buffer_list() {
        if ( ! $this->detect_list() ) return;
        ob_start( [ $this, 'filter_list_html' ] );
    }
    public function maybe_end_buffer_list() {
        if ( ob_get_level() === 0 || ! $this->detect_list() ) return;
        @ob_end_flush();
    }
    public function filter_list_html( $html ) {
        $ctx = $this->detect_list();
        if ( ! $ctx ) return $html;

        foreach ( $ctx['fields'] as $field ) {
            foreach ( [ 'name', 'id' ] as $attr ) {
                $pattern = '/<input\b([^>]*\b' . $attr . '=(["\'])' . preg_quote( $field, '/' ) . '\2[^>]*)>/i';
                $html = preg_replace_callback( $pattern, function( $m ) {
                    $attrs = preg_replace( '/\svalue=(["\'])[^"\']*\1/i', '', $m[1] );
                    return '<input' . $attrs . ' value="">';
                }, $html );
            }
            $pattern_hidden = '/<div\b([^>]*\b(?:id|class)=(["\'])[^"\']*\b' . preg_quote( $field, '/' ) . '\b[^"\']*\2[^>]*)>(.*?)<\/div>/is';
            $html = preg_replace_callback( $pattern_hidden, function( $m ) {
                if ( strpos( $m[1], 'hidden' ) !== false ) {
                    return '<div' . $m[1] . '></div>';
                }
                return $m[0];
            }, $html );
            $pattern_data = '/\sdata-' . preg_quote( $field, '/' ) . '=(["\'])[^"\']*\1/i';
            $html = preg_replace( $pattern_data, ' data-' . $field . '=""', $html );
        }
        return $html;
    }

    public function enqueue_list( $hook ) {
        if ( $hook !== 'edit.php' ) return;
        $ctx = $this->detect_list();
        if ( ! $ctx ) return;

        wp_register_script( 'smf-cpt-list', '', [ 'jquery' ], '1.3', true );
        wp_enqueue_script( 'smf-cpt-list' );

        wp_localize_script( 'smf-cpt-list', 'SMF_CPT_LIST', [
            'fields'      => $ctx['fields'],
            'placeholder' => self::MASK_LIST,
        ] );

        wp_add_inline_script( 'smf-cpt-list', $this->js_list() );
    }

    /* =========================================================================
     *  CSS
     * ========================================================================= */

    private function css_edit() {
        return '
        .cx-ui-container.smf-cpt-has-lock{display:flex !important;align-items:center;gap:6px;}
        .cx-ui-container.smf-cpt-has-lock > input{flex:1;min-width:0;}

        .smf-cpt-lock-btn{display:inline-flex;align-items:center;justify-content:center;
            width:30px;height:30px;border:1px solid #c3c4c7;background:#fff;
            border-radius:4px;cursor:pointer;flex-shrink:0;padding:0;transition:.15s;}
        .smf-cpt-lock-btn:hover{background:#f6f7f7;border-color:#8c8f94;}
        .smf-cpt-lock-btn .dashicons{font-size:16px;width:16px;height:16px;color:#2271b1;}
        .smf-cpt-lock-btn.is-unlocked{border-color:#008a20;}
        .smf-cpt-lock-btn.is-unlocked .dashicons{color:#008a20;}

        .smf-cpt-modal{display:none;position:fixed;inset:0;z-index:160000;}
        .smf-cpt-modal[aria-hidden="false"]{display:block;}
        .smf-cpt-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);}
        .smf-cpt-modal__dialog{position:relative;max-width:420px;margin:10vh auto 0;background:#fff;
            border-radius:8px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.25);}
        .smf-cpt-modal__dialog h2{margin:0 0 8px;font-size:18px;}
        .smf-cpt-modal__desc{margin:0 0 14px;color:#50575e;}
        #smf-cpt-modal-input{width:100%;padding:8px;}
        .smf-cpt-modal__check{display:flex;align-items:center;gap:8px;margin-top:12px;
            font-size:13px;color:#50575e;cursor:pointer;user-select:none;}
        .smf-cpt-modal__check input{margin:0;}
        .smf-cpt-modal__msg{min-height:20px;margin:10px 0;font-size:13px;}
        .smf-cpt-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
        ';
    }

    /* =========================================================================
     *  JS — Edição
     * ========================================================================= */

    private function js_edit() {
        return <<<'JS'
        (function($){
            var D = window.SMF_CPT_DATA;
            if (!D) return;

            var WARN_MSG = 'Desbloqueie os campos protegidos para salvar as alterações.';
            var fields = {};

            function findInputs(field){
                var $a = $('.jet-engine-meta-wrap input[name="'+field+'"]');
                if ($a.length) return $a;
                return $('input[name="'+field+'"]');
            }
            function applyMasked(input){
                input.type = 'password';
                input.readOnly = true;
                input.autocomplete = 'new-password';
                input.value = D.placeholder;
                input.setAttribute('value','');
                input.setAttribute('data-smf-state','masked');
            }
            // Reforça o valor pós-desbloqueio: sincroniza atributo HTML + dispara
            // events nativos pra notificar qualquer framework (Vue, jQuery, etc).
            function applyRevealed(input, real){
                input.type = 'text';
                input.readOnly = false;
                input.autocomplete = 'new-password';
                input.value = real;
                input.setAttribute('value', real); // sincroniza atributo HTML
                input.setAttribute('data-smf-state','revealed');
                // Notifica frameworks que o valor mudou
                ['input','change','blur'].forEach(function(evtName){
                    input.dispatchEvent(new Event(evtName, { bubbles: true }));
                });
            }
            function updateLockUI(field, isUnlocked){
                var input = fields[field];
                if (!input) return;
                var $c = $(input).closest('.cx-ui-container');
                var $b = $c.find('.smf-cpt-lock-btn');
                $b.toggleClass('is-unlocked', isUnlocked);
                $b.attr('title', isUnlocked ? 'Bloquear novamente' : 'Desbloquear');
                $b.find('.dashicons')
                  .toggleClass('dashicons-lock', !isUnlocked)
                  .toggleClass('dashicons-unlock', isUnlocked);
            }
            function fetchReal(field, cb){
                $.post(D.ajax, {
                    action:'smf_cpt_reveal', nonce:D.nonce,
                    post_id:D.postId, field:field
                }).done(function(res){
                    cb(res && res.data ? (res.data.value || '') : '');
                }).fail(function(){ cb(''); });
            }

            function transformField(input, field){
                var $input = $(input);
                if ($input.data('smfDone')) return;
                $input.data('smfDone', true);

                fields[field] = input;
                var isUnlocked = !!(D.unlocked && D.unlocked[field]);

                if (isUnlocked) {
                    applyMasked(input);
                    fetchReal(field, function(real){ applyRevealed(input, real); });
                } else {
                    applyMasked(input);
                    $input.on('keydown paste cut drop', function(e){ e.preventDefault(); });
                }

                var $container = $input.closest('.cx-ui-container');
                if (!$container.length) $container = $input.parent();
                $container.addClass('smf-cpt-has-lock');

                if (!$container.find('.smf-cpt-lock-btn').length) {
                    var iconClass = isUnlocked ? 'dashicons-unlock' : 'dashicons-lock';
                    var $btn = $('<button type="button" class="smf-cpt-lock-btn"></button>')
                        .toggleClass('is-unlocked', isUnlocked)
                        .attr('title', isUnlocked ? 'Bloquear novamente' : 'Desbloquear')
                        .append('<span class="dashicons '+iconClass+'"></span>');
                    $input.after($btn);
                    $btn.on('click', function(e){
                        e.preventDefault(); e.stopPropagation();
                        if ($btn.hasClass('is-unlocked')) {
                            $.post(D.ajax, {
                                action:'smf_cpt_lock', nonce:D.nonce,
                                post_id:D.postId, field:field
                            }).always(function(){ window.location.reload(); });
                        } else {
                            openModal(field);
                        }
                    });
                }
            }

            function scan(){
                (D.fields || []).forEach(function(field){
                    findInputs(field).each(function(){ transformField(this, field); });
                });
            }

            // BLOQUEIO DE SUBMIT — se algum campo estiver bloqueado, cancela.
            $(document).on('submit', '#post', function(e){
                var masked = Object.keys(fields).filter(function(field){
                    var input = fields[field];
                    return input && input.getAttribute('data-smf-state') === 'masked';
                });
                if (masked.length > 0) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    console.warn('[SMF_CPT] Submit bloqueado — campos protegidos sem desbloqueio:', masked);
                    alert(WARN_MSG);
                    return false;
                }
            });

            var currentField = null, $modal, $input, $msg, $checkAll;

            function openModal(field){
                currentField = field;
                $modal.attr('aria-hidden','false');
                $msg.text('').css('color','');
                $input.prop('disabled', false).val('').focus();
                $checkAll.prop('checked', true);
            }
            function closeModal(){ $modal.attr('aria-hidden','true'); currentField = null; }

            function applyUnlockedAll(values){
                Object.keys(values || {}).forEach(function(field){
                    var input = fields[field];
                    if (!input) return;
                    applyRevealed(input, values[field]);
                    $(input).off('keydown paste cut drop');
                    D.unlocked[field] = true;
                    updateLockUI(field, true);
                });
            }
            function applyUnlockedSingle(field, value){
                var input = fields[field];
                if (!input) return;
                applyRevealed(input, value);
                $(input).off('keydown paste cut drop');
                D.unlocked[field] = true;
                updateLockUI(field, true);
            }

            function submit(){
                var pwd = $input.val();
                if (!pwd) return;
                $msg.css('color','#50575e').text('Verificando…');

                var unlockAll = $checkAll.is(':checked');
                var action    = unlockAll ? 'smf_cpt_unlock_all' : 'smf_cpt_unlock';
                var payload   = {
                    action:  action,
                    nonce:   D.nonce,
                    post_id: D.postId,
                    password: pwd
                };
                if (!unlockAll) payload.field = currentField;

                $.post(D.ajax, payload).done(function(res){
                    var data = res && res.data ? res.data : {};
                    if (unlockAll) {
                        applyUnlockedAll(data.values || {});
                        $msg.css('color','#008a20').text('Todos os campos desbloqueados.');
                    } else {
                        applyUnlockedSingle(currentField, data.value || '');
                        $msg.css('color','#008a20').text('Desbloqueado.');
                    }
                    setTimeout(closeModal, 400);
                }).fail(function(xhr){
                    var d = (xhr.responseJSON && xhr.responseJSON.data) || {};
                    if (d.locked_out){
                        $msg.css('color','#b32d2e').text('Muitas tentativas. Bloqueado por 30 minutos.');
                        $input.prop('disabled', true);
                    } else if (typeof d.remaining !== 'undefined'){
                        $msg.css('color','#b32d2e').text('Senha incorreta. Tentativas restantes: ' + d.remaining);
                        $input.val('').focus();
                    } else {
                        $msg.css('color','#b32d2e').text(d.message || 'Erro.');
                    }
                });
            }

            function init(){
                $modal    = $('#smf-cpt-modal');
                $input    = $('#smf-cpt-modal-input');
                $msg      = $modal.find('.smf-cpt-modal__msg');
                $checkAll = $('#smf-cpt-modal-all');

                $('#smf-cpt-modal-cancel').on('click', closeModal);
                $modal.find('.smf-cpt-modal__backdrop').on('click', closeModal);
                $('#smf-cpt-modal-submit').on('click', submit);
                $input.on('keydown', function(e){
                    if (e.key === 'Enter'){ e.preventDefault(); submit(); }
                    if (e.key === 'Escape'){ closeModal(); }
                });

                scan();
                setTimeout(scan, 500);
                setTimeout(scan, 1500);

                var target = document.querySelector('.jet-engine-meta-wrap') || document.body;
                new MutationObserver(scan).observe(target, { childList:true, subtree:true });
            }

            if (document.readyState === 'complete') init();
            else $(window).on('load', init);

        })(jQuery);
JS;
    }

    /* =========================================================================
     *  JS — Listagem (Quick Edit do CPT)
     * ========================================================================= */

    private function js_list() {
        return <<<'JS'
        (function($){
            var D = window.SMF_CPT_LIST;
            if (!D) return;

            var WARN_MSG = 'Desbloqueie os campos protegidos para salvar as alterações.';
            var maskedInputs = [];

            function maskInput(input){
                if (input.dataset.smfListDone === '1') return;
                input.dataset.smfListDone = '1';
                input.type = 'password';
                input.readOnly = true;
                input.autocomplete = 'new-password';
                input.value = D.placeholder;
                input.setAttribute('value','');
                input.setAttribute('data-smf-state','masked');
                maskedInputs.push(input);
                $(input).off('.smfblock').on('keydown.smfblock paste.smfblock cut.smfblock drop.smfblock',
                    function(e){ e.preventDefault(); });
            }

            function scanQuickEdit(){
                (D.fields || []).forEach(function(field){
                    document.querySelectorAll('input[name="'+field+'"], input[id="'+field+'"]')
                        .forEach(function(input){
                            if (input.closest('.inline-edit-row, .quick-edit-row')) {
                                maskInput(input);
                            }
                        });
                });
            }

            // BLOQUEIO DE SUBMIT do Quick Edit
            $(document).on('click', '.inline-edit-save .save, .inline-edit-save .button-primary, .quick-edit-row .save',
            function(e){
                var hasMasked = maskedInputs.some(function(input){
                    return document.body.contains(input)
                        && input.getAttribute('data-smf-state') === 'masked';
                });
                if (hasMasked) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    console.warn('[SMF_CPT] Submit do Quick Edit bloqueado — campos protegidos presentes.');
                    alert(WARN_MSG);
                    return false;
                }
            });

            function init(){
                var target = document.getElementById('the-list') || document.body;
                new MutationObserver(function(){
                    scanQuickEdit();
                    setTimeout(scanQuickEdit, 100);
                    setTimeout(scanQuickEdit, 500);
                }).observe(target, { childList:true, subtree:true });

                scanQuickEdit();
            }

            if (document.readyState === 'complete') init();
            else $(window).on('load', init);
        })(jQuery);
JS;
    }
}

new SMF_CPT();


/* ========================================================================== *
 *                                                                            *
 *   CLASSE 2 — SMF_CCT — JetEngine Custom Content Types                      *
 *   Cobre: edit screen + listagem (colunas + quick edit modal)               *
 *                                                                            *
 * ========================================================================== */

class SMF_CCT {

    /** Configuração: 'cct_slug' => [ 'field_name', ... ] */
    private $protected = [
        'indicacao' => [ 'indicacao_user_ref' ],
    ];

    const MAX_ATTEMPTS       = 5;
    const LOCKOUT_SECONDS    = 30 * MINUTE_IN_SECONDS;
    const UNLOCK_TTL_SECONDS = 5  * MINUTE_IN_SECONDS;
    const PLACEHOLDER        = '••••••••••••';
    const MASK_LIST          = '••••••••••••';

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_edit' ] );
        add_action( 'admin_footer',          [ $this, 'render_modal' ] );

        add_action( 'wp_ajax_smf_cct_unlock',     [ $this, 'ajax_unlock' ] );
        add_action( 'wp_ajax_smf_cct_unlock_all', [ $this, 'ajax_unlock_all' ] );
        add_action( 'wp_ajax_smf_cct_lock',       [ $this, 'ajax_lock' ] );
        add_action( 'wp_ajax_smf_cct_reveal',     [ $this, 'ajax_reveal' ] );

        add_filter( 'jet-engine/custom-content-types/admin/columns/value', [ $this, 'filter_column_value' ], 99, 4 );
        add_action( 'admin_head',   [ $this, 'maybe_start_buffer_list' ], 1 );
        add_action( 'admin_footer', [ $this, 'maybe_end_buffer_list' ], PHP_INT_MAX );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list' ] );
    }

    /* =========================================================================
     *  HELPERS
     * ========================================================================= */

    private function is_protected( $cct_slug, $field ) {
        return isset( $this->protected[ $cct_slug ] )
            && in_array( $field, $this->protected[ $cct_slug ], true );
    }
    private function unlock_key( $item_id, $field ) {
        return 'smf_cct_unlock_' . get_current_user_id() . '_' . $item_id . '_' . md5( $field );
    }
    private function attempts_key() {
        return 'smf_cct_attempts_' . get_current_user_id();
    }
    private function is_unlocked( $item_id, $field ) {
        return (bool) get_transient( $this->unlock_key( $item_id, $field ) );
    }

    private function read_value( $cct_slug, $item_id, $field ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'jet_cct_' . $cct_slug;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) return '';

        $col = preg_replace( '/[^a-zA-Z0-9_]/', '', $field );
        if ( $col === '' ) return '';

        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT `{$col}` FROM `{$table}` WHERE _ID = %d LIMIT 1",
            $item_id
        ) );
        return $val !== null ? $val : '';
    }

    /* =========================================================================
     *  DETECÇÃO
     * ========================================================================= */

    private function detect_edit() {
        if ( ! isset( $_GET['page'] ) ) return null;
        $page = sanitize_text_field( $_GET['page'] );
        if ( strpos( $page, 'jet-cct-' ) !== 0 ) return null;
        if ( ! isset( $_GET['item_id'] ) ) return null;

        $cct_slug = substr( $page, strlen( 'jet-cct-' ) );
        if ( ! isset( $this->protected[ $cct_slug ] ) ) return null;

        return [
            'cct_slug' => $cct_slug,
            'item_id'  => (int) $_GET['item_id'],
            'fields'   => $this->protected[ $cct_slug ],
        ];
    }

    private function detect_list() {
        if ( ! isset( $_GET['page'] ) ) return null;
        $page = sanitize_text_field( $_GET['page'] );
        if ( strpos( $page, 'jet-cct-' ) !== 0 ) return null;
        if ( isset( $_GET['item_id'] ) ) return null;

        $slug = substr( $page, strlen( 'jet-cct-' ) );
        if ( ! isset( $this->protected[ $slug ] ) ) return null;
        return [ 'cct_slug' => $slug, 'fields' => $this->protected[ $slug ] ];
    }

    /* =========================================================================
     *  EDIÇÃO — Assets, modal, AJAX
     * ========================================================================= */

    public function enqueue_edit( $hook ) {
        $ctx = $this->detect_edit();
        if ( ! $ctx ) return;

        $unlock_state = [];
        foreach ( $ctx['fields'] as $field ) {
            $unlock_state[ $field ] = $this->is_unlocked( $ctx['item_id'], $field );
        }

        wp_register_script( 'smf-cct-edit', '', [ 'jquery' ], '1.3', true );
        wp_enqueue_script( 'smf-cct-edit' );

        wp_localize_script( 'smf-cct-edit', 'SMF_CCT_DATA', [
            'ajax'        => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'smf_cct_nonce' ),
            'cctSlug'     => $ctx['cct_slug'],
            'itemId'      => $ctx['item_id'],
            'fields'      => $ctx['fields'],
            'unlocked'    => $unlock_state,
            'placeholder' => self::PLACEHOLDER,
        ] );

        wp_add_inline_script( 'smf-cct-edit', $this->js_edit() );
        wp_add_inline_style( 'wp-admin', $this->css_edit() );
    }

    public function render_modal() {
        if ( ! $this->detect_edit() ) return;
        ?>
        <div id="smf-cct-modal" class="smf-cct-modal" aria-hidden="true">
          <div class="smf-cct-modal__backdrop"></div>
          <form class="smf-cct-modal__dialog" role="dialog" aria-modal="true" onsubmit="return false;">
            <h2>Desbloquear campo</h2>
            <p class="smf-cct-modal__desc">Informe a senha administrativa para revelar este valor.</p>
            <input type="password" id="smf-cct-modal-input" autocomplete="current-password" />
            <label class="smf-cct-modal__check">
              <input type="checkbox" id="smf-cct-modal-all" checked />
              <span>Desbloquear todos os campos protegidos desta página</span>
            </label>
            <div class="smf-cct-modal__msg" aria-live="polite"></div>
            <div class="smf-cct-modal__actions">
              <button type="button" class="button" id="smf-cct-modal-cancel">Cancelar</button>
              <button type="button" class="button button-primary" id="smf-cct-modal-submit">Desbloquear</button>
            </div>
          </form>
        </div>
        <?php
    }

    private function validate( $require_field = true ) {
        check_ajax_referer( 'smf_cct_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Sem permissão.' ], 403 );
        }
        $cct_slug = isset( $_POST['cct_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['cct_slug'] ) ) : '';
        $item_id  = isset( $_POST['item_id'] )  ? (int) $_POST['item_id'] : 0;
        if ( ! isset( $this->protected[ $cct_slug ] ) ) {
            wp_send_json_error( [ 'message' => 'CCT inválido.' ], 400 );
        }
        $field = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
        if ( $require_field && ! $this->is_protected( $cct_slug, $field ) ) {
            wp_send_json_error( [ 'message' => 'Campo inválido.' ], 400 );
        }
        return compact( 'cct_slug', 'item_id', 'field' );
    }

    private function check_password_and_attempts() {
        if ( ! defined( 'SECURE_META_ADMIN_PASSWORD' ) ) {
            wp_send_json_error( [ 'message' => 'Senha admin não configurada.' ], 500 );
        }
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

        $attempts = (int) get_transient( $this->attempts_key() );
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            wp_send_json_error( [ 'locked_out' => true, 'message' => 'Muitas tentativas.' ], 429 );
        }
        if ( ! hash_equals( SECURE_META_ADMIN_PASSWORD, $password ) ) {
            $attempts++;
            set_transient( $this->attempts_key(), $attempts, self::LOCKOUT_SECONDS );
            $remaining = max( 0, self::MAX_ATTEMPTS - $attempts );
            wp_send_json_error( [
                'remaining'  => $remaining,
                'locked_out' => $attempts >= self::MAX_ATTEMPTS,
                'message'    => 'Senha incorreta.',
            ], 401 );
        }
        delete_transient( $this->attempts_key() );
    }

    public function ajax_unlock() {
        $d = $this->validate( true );
        $this->check_password_and_attempts();
        set_transient( $this->unlock_key( $d['item_id'], $d['field'] ), 1, self::UNLOCK_TTL_SECONDS );
        wp_send_json_success( [
            'ok'    => true,
            'value' => (string) $this->read_value( $d['cct_slug'], $d['item_id'], $d['field'] ),
        ] );
    }
    public function ajax_unlock_all() {
        $d = $this->validate( false );
        $this->check_password_and_attempts();
        $values = [];
        foreach ( $this->protected[ $d['cct_slug'] ] as $field ) {
            set_transient( $this->unlock_key( $d['item_id'], $field ), 1, self::UNLOCK_TTL_SECONDS );
            $values[ $field ] = (string) $this->read_value( $d['cct_slug'], $d['item_id'], $field );
        }
        wp_send_json_success( [ 'ok' => true, 'values' => $values ] );
    }
    public function ajax_reveal() {
        $d = $this->validate( true );
        if ( ! $this->is_unlocked( $d['item_id'], $d['field'] ) ) {
            wp_send_json_error( [ 'message' => 'Bloqueado.' ], 403 );
        }
        wp_send_json_success( [
            'value' => (string) $this->read_value( $d['cct_slug'], $d['item_id'], $d['field'] ),
        ] );
    }
    public function ajax_lock() {
        $d = $this->validate( true );
        delete_transient( $this->unlock_key( $d['item_id'], $d['field'] ) );
        wp_send_json_success();
    }

    /* =========================================================================
     *  LISTAGEM — Filtro + buffer + JS guard
     * ========================================================================= */

    public function filter_column_value( $value, $column, $item, $cct_obj = null ) {
        $cct_slug = null;
        if ( is_object( $cct_obj ) && method_exists( $cct_obj, 'get_arg' ) ) {
            $cct_slug = $cct_obj->get_arg( 'slug' );
        } elseif ( is_object( $cct_obj ) && isset( $cct_obj->slug ) ) {
            $cct_slug = $cct_obj->slug;
        }

        if ( ! $cct_slug || ! isset( $this->protected[ $cct_slug ] ) ) return $value;
        if ( ! in_array( $column, $this->protected[ $cct_slug ], true ) ) return $value;

        return '<span class="smf-cct-masked">' . esc_html( self::MASK_LIST ) . '</span>';
    }

    public function maybe_start_buffer_list() {
        if ( ! $this->detect_list() ) return;
        ob_start( [ $this, 'filter_list_html' ] );
    }
    public function maybe_end_buffer_list() {
        if ( ob_get_level() === 0 || ! $this->detect_list() ) return;
        @ob_end_flush();
    }
    public function filter_list_html( $html ) {
        $ctx = $this->detect_list();
        if ( ! $ctx ) return $html;

        foreach ( $ctx['fields'] as $field ) {
            $pattern = '/(<td\b[^>]*\bclass=(["\'])[^"\']*\b' . preg_quote( $field, '/' ) . '\b[^"\']*\2[^>]*>)(.*?)(<\/td>)/is';
            $html = preg_replace_callback( $pattern, function( $m ) {
                $open = $m[1]; $content = $m[3]; $close = $m[4];
                $preserved = '';
                if ( preg_match_all( '/<button\b[^>]*class=(["\'])[^"\']*toggle-row[^"\']*\1[^>]*>.*?<\/button>/is', $content, $btns ) ) {
                    $preserved .= implode( '', $btns[0] );
                }
                $masked = '<span class="smf-cct-masked">' . esc_html( self::MASK_LIST ) . '</span>';
                return $open . $masked . $preserved . $close;
            }, $html );
        }
        return $html;
    }

    public function enqueue_list() {
        $ctx = $this->detect_list();
        if ( ! $ctx ) return;

        wp_register_script( 'smf-cct-list', '', [ 'jquery' ], '1.3', true );
        wp_enqueue_script( 'smf-cct-list' );

        wp_localize_script( 'smf-cct-list', 'SMF_CCT_LIST', [
            'fields'      => $ctx['fields'],
            'placeholder' => self::MASK_LIST,
        ] );

        wp_add_inline_script( 'smf-cct-list', $this->js_list() );
    }

    /* =========================================================================
     *  CSS
     * ========================================================================= */

    private function css_edit() {
        return '
        .cx-ui-container.smf-cct-has-lock{display:flex !important;align-items:center;gap:6px;}
        .cx-ui-container.smf-cct-has-lock > input{flex:1;min-width:0;}

        .smf-cct-lock-btn{display:inline-flex;align-items:center;justify-content:center;
            width:30px;height:30px;border:1px solid #c3c4c7;background:#fff;
            border-radius:4px;cursor:pointer;flex-shrink:0;padding:0;transition:.15s;}
        .smf-cct-lock-btn:hover{background:#f6f7f7;border-color:#8c8f94;}
        .smf-cct-lock-btn .dashicons{font-size:16px;width:16px;height:16px;color:#2271b1;}
        .smf-cct-lock-btn.is-unlocked{border-color:#008a20;}
        .smf-cct-lock-btn.is-unlocked .dashicons{color:#008a20;}

        .smf-cct-modal{display:none;position:fixed;inset:0;z-index:160000;}
        .smf-cct-modal[aria-hidden="false"]{display:block;}
        .smf-cct-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);}
        .smf-cct-modal__dialog{position:relative;max-width:420px;margin:10vh auto 0;background:#fff;
            border-radius:8px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.25);}
        .smf-cct-modal__dialog h2{margin:0 0 8px;font-size:18px;}
        .smf-cct-modal__desc{margin:0 0 14px;color:#50575e;}
        #smf-cct-modal-input{width:100%;padding:8px;}
        .smf-cct-modal__check{display:flex;align-items:center;gap:8px;margin-top:12px;
            font-size:13px;color:#50575e;cursor:pointer;user-select:none;}
        .smf-cct-modal__check input{margin:0;}
        .smf-cct-modal__msg{min-height:20px;margin:10px 0;font-size:13px;}
        .smf-cct-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
        ';
    }

    /* =========================================================================
     *  JS — Edição
     * ========================================================================= */

    private function js_edit() {
        return <<<'JS'
        (function($){
            var D = window.SMF_CCT_DATA;
            if (!D) return;

            var WARN_MSG = 'Desbloqueie os campos protegidos para salvar as alterações.';
            var fields = {};

            function hasMaskedFields(){
                return Object.keys(fields).some(function(f){
                    var i = fields[f];
                    return i && i.getAttribute('data-smf-state') === 'masked';
                });
            }

            function findInputs(field){
                var sel = '.cx-settings .cx-control .cx-ui-container > input[name="'+field+'"], '
                        + '.cx-settings .cx-control input[name="'+field+'"]';
                var $a = $(sel);
                if ($a.length) return $a;
                return $('input[name="'+field+'"]');
            }
            function applyMasked(input){
                input.type = 'password';
                input.readOnly = true;
                input.autocomplete = 'new-password';
                input.value = D.placeholder;
                input.setAttribute('value','');
                input.setAttribute('data-smf-state','masked');
            }
            // Reforço: atributo HTML + events nativos
            function applyRevealed(input, real){
                input.type = 'text';
                input.readOnly = false;
                input.autocomplete = 'new-password';
                input.value = real;
                input.setAttribute('value', real);
                input.setAttribute('data-smf-state','revealed');
                ['input','change','blur'].forEach(function(evtName){
                    input.dispatchEvent(new Event(evtName, { bubbles: true }));
                });
            }
            function updateLockUI(field, isUnlocked){
                var input = fields[field];
                if (!input) return;
                var $c = $(input).closest('.cx-ui-container');
                var $b = $c.find('.smf-cct-lock-btn');
                $b.toggleClass('is-unlocked', isUnlocked);
                $b.attr('title', isUnlocked ? 'Bloquear novamente' : 'Desbloquear');
                $b.find('.dashicons')
                  .toggleClass('dashicons-lock', !isUnlocked)
                  .toggleClass('dashicons-unlock', isUnlocked);
            }
            function fetchReal(field, cb){
                $.post(D.ajax, {
                    action:'smf_cct_reveal', nonce:D.nonce,
                    cct_slug:D.cctSlug, item_id:D.itemId, field:field
                }).done(function(res){
                    cb(res && res.data ? (res.data.value || '') : '');
                }).fail(function(){ cb(''); });
            }

            function transformField(input, field){
                var $input = $(input);
                if ($input.data('smfDone')) return;
                $input.data('smfDone', true);

                fields[field] = input;
                var isUnlocked = !!(D.unlocked && D.unlocked[field]);

                if (isUnlocked) {
                    applyMasked(input);
                    fetchReal(field, function(real){ applyRevealed(input, real); });
                } else {
                    applyMasked(input);
                    $input.on('keydown paste cut drop', function(e){ e.preventDefault(); });
                }

                var $container = $input.closest('.cx-ui-container');
                if (!$container.length) $container = $input.parent();
                $container.addClass('smf-cct-has-lock');

                if (!$container.find('.smf-cct-lock-btn').length) {
                    var iconClass = isUnlocked ? 'dashicons-unlock' : 'dashicons-lock';
                    var $btn = $('<button type="button" class="smf-cct-lock-btn"></button>')
                        .toggleClass('is-unlocked', isUnlocked)
                        .attr('title', isUnlocked ? 'Bloquear novamente' : 'Desbloquear')
                        .append('<span class="dashicons '+iconClass+'"></span>');
                    $input.after($btn);
                    $btn.on('click', function(e){
                        e.preventDefault(); e.stopPropagation();
                        if ($btn.hasClass('is-unlocked')) {
                            $.post(D.ajax, {
                                action:'smf_cct_lock', nonce:D.nonce,
                                cct_slug:D.cctSlug, item_id:D.itemId, field:field
                            }).always(function(){ window.location.reload(); });
                        } else {
                            openModal(field);
                        }
                    });
                }
            }

            function scan(){
                (D.fields || []).forEach(function(field){
                    findInputs(field).each(function(){ transformField(this, field); });
                });
            }

            // INTERCEPT REST do JetEngine — sanitiza RESPOSTAS,
            // e ABORTA submits quando há campos mascarados.
            (function intercept(){
                function sanitize(obj){
                    if (!obj || typeof obj !== 'object') return;
                    (D.fields || []).forEach(function(field){
                        if (Object.prototype.hasOwnProperty.call(obj, field) && !D.unlocked[field]) {
                            obj[field] = '';
                        }
                    });
                    Object.keys(obj).forEach(function(k){
                        if (obj[k] && typeof obj[k] === 'object') sanitize(obj[k]);
                    });
                }

                function isWriteMethod(m){
                    if (!m) return false;
                    m = (''+m).toUpperCase();
                    return m === 'POST' || m === 'PUT' || m === 'PATCH' || m === 'DELETE';
                }

                if (window.fetch) {
                    var origFetch = window.fetch;
                    window.fetch = function(input, init){
                        var url = (typeof input === 'string') ? input : (input && input.url) || '';
                        var method = (init && init.method) || (typeof input === 'object' && input.method) || 'GET';
                        if (url.indexOf('jet-cct') !== -1 && isWriteMethod(method) && hasMaskedFields()) {
                            console.warn('[SMF_CCT] Submit (fetch) bloqueado — campos protegidos sem desbloqueio.');
                            alert(WARN_MSG);
                            return Promise.reject(new Error('SMF_CCT: blocked'));
                        }
                        if (url.indexOf('jet-cct') === -1) return origFetch.apply(this, arguments);
                        return origFetch.apply(this, arguments).then(function(resp){
                            return resp.clone().text().then(function(text){
                                try {
                                    var data = JSON.parse(text);
                                    sanitize(data);
                                    return new Response(JSON.stringify(data), {
                                        status: resp.status, statusText: resp.statusText, headers: resp.headers
                                    });
                                } catch(e) { return resp; }
                            });
                        });
                    };
                }

                var origOpen = XMLHttpRequest.prototype.open;
                var origSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function(method, url){
                    this._smfUrl = url || '';
                    this._smfMethod = method || 'GET';
                    return origOpen.apply(this, arguments);
                };
                XMLHttpRequest.prototype.send = function(body){
                    var self = this;
                    if (self._smfUrl && self._smfUrl.indexOf('jet-cct') !== -1) {
                        if (isWriteMethod(self._smfMethod) && hasMaskedFields()) {
                            console.warn('[SMF_CCT] Submit (XHR) bloqueado — campos protegidos sem desbloqueio.');
                            alert(WARN_MSG);
                            self.abort();
                            return;
                        }
                        self.addEventListener('readystatechange', function(){
                            if (self.readyState === 4) {
                                try {
                                    var data = JSON.parse(self.responseText);
                                    sanitize(data);
                                    Object.defineProperty(self, 'responseText', { value: JSON.stringify(data), configurable: true });
                                    Object.defineProperty(self, 'response',     { value: JSON.stringify(data), configurable: true });
                                } catch(e){}
                            }
                        });
                    }
                    return origSend.call(this, body);
                };
            })();

            var currentField = null, $modal, $input, $msg, $checkAll;

            function openModal(field){
                currentField = field;
                $modal.attr('aria-hidden','false');
                $msg.text('').css('color','');
                $input.prop('disabled', false).val('').focus();
                $checkAll.prop('checked', true);
            }
            function closeModal(){ $modal.attr('aria-hidden','true'); currentField = null; }

            function applyUnlockedAll(values){
                Object.keys(values || {}).forEach(function(field){
                    var input = fields[field];
                    if (!input) return;
                    applyRevealed(input, values[field]);
                    $(input).off('keydown paste cut drop');
                    D.unlocked[field] = true;
                    updateLockUI(field, true);
                });
            }
            function applyUnlockedSingle(field, value){
                var input = fields[field];
                if (!input) return;
                applyRevealed(input, value);
                $(input).off('keydown paste cut drop');
                D.unlocked[field] = true;
                updateLockUI(field, true);
            }

            function submit(){
                var pwd = $input.val();
                if (!pwd) return;
                $msg.css('color','#50575e').text('Verificando…');

                var unlockAll = $checkAll.is(':checked');
                var action    = unlockAll ? 'smf_cct_unlock_all' : 'smf_cct_unlock';
                var payload   = {
                    action:   action,
                    nonce:    D.nonce,
                    cct_slug: D.cctSlug,
                    item_id:  D.itemId,
                    password: pwd
                };
                if (!unlockAll) payload.field = currentField;

                $.post(D.ajax, payload).done(function(res){
                    var data = res && res.data ? res.data : {};
                    if (unlockAll) {
                        applyUnlockedAll(data.values || {});
                        $msg.css('color','#008a20').text('Todos os campos desbloqueados.');
                    } else {
                        applyUnlockedSingle(currentField, data.value || '');
                        $msg.css('color','#008a20').text('Desbloqueado.');
                    }
                    setTimeout(closeModal, 400);
                }).fail(function(xhr){
                    var d = (xhr.responseJSON && xhr.responseJSON.data) || {};
                    if (d.locked_out){
                        $msg.css('color','#b32d2e').text('Muitas tentativas. Bloqueado por 30 minutos.');
                        $input.prop('disabled', true);
                    } else if (typeof d.remaining !== 'undefined'){
                        $msg.css('color','#b32d2e').text('Senha incorreta. Tentativas restantes: ' + d.remaining);
                        $input.val('').focus();
                    } else {
                        $msg.css('color','#b32d2e').text(d.message || 'Erro.');
                    }
                });
            }

            function init(){
                $modal    = $('#smf-cct-modal');
                $input    = $('#smf-cct-modal-input');
                $msg      = $modal.find('.smf-cct-modal__msg');
                $checkAll = $('#smf-cct-modal-all');

                $('#smf-cct-modal-cancel').on('click', closeModal);
                $modal.find('.smf-cct-modal__backdrop').on('click', closeModal);
                $('#smf-cct-modal-submit').on('click', submit);
                $input.on('keydown', function(e){
                    if (e.key === 'Enter'){ e.preventDefault(); submit(); }
                    if (e.key === 'Escape'){ closeModal(); }
                });

                scan();
                setTimeout(scan, 500);
                setTimeout(scan, 1500);
                setTimeout(scan, 3000);

                var target = document.querySelector('.cx-settings') || document.body;
                new MutationObserver(scan).observe(target, { childList:true, subtree:true });
            }

            if (document.readyState === 'complete') init();
            else $(window).on('load', init);

        })(jQuery);
JS;
    }

    /* =========================================================================
     *  JS — Listagem (Quick Edit modal do CCT)
     * ========================================================================= */

    private function js_list() {
        return <<<'JS'
        (function($){
            var D = window.SMF_CCT_LIST;
            if (!D) return;

            var WARN_MSG = 'Desbloqueie os campos protegidos para salvar as alterações.';
            var maskedInputs = [];

            function hasActiveMasked(){
                return maskedInputs.some(function(input){
                    return document.body.contains(input)
                        && input.getAttribute('data-smf-state') === 'masked';
                });
            }

            // Intercept REST — sanitiza respostas e bloqueia submits
            (function intercept(){
                function sanitize(obj){
                    if (!obj || typeof obj !== 'object') return;
                    (D.fields || []).forEach(function(field){
                        if (Object.prototype.hasOwnProperty.call(obj, field)) {
                            obj[field] = '';
                        }
                    });
                    Object.keys(obj).forEach(function(k){
                        if (obj[k] && typeof obj[k] === 'object') sanitize(obj[k]);
                    });
                }

                function isWriteMethod(m){
                    if (!m) return false;
                    m = (''+m).toUpperCase();
                    return m === 'POST' || m === 'PUT' || m === 'PATCH' || m === 'DELETE';
                }

                if (window.fetch) {
                    var origFetch = window.fetch;
                    window.fetch = function(input, init){
                        var url = (typeof input === 'string') ? input : (input && input.url) || '';
                        var method = (init && init.method) || (typeof input === 'object' && input.method) || 'GET';
                        if (url.indexOf('jet-cct') !== -1 && isWriteMethod(method) && hasActiveMasked()) {
                            console.warn('[SMF_CCT_LIST] Submit (fetch) bloqueado — campos protegidos no modal.');
                            alert(WARN_MSG);
                            return Promise.reject(new Error('SMF_CCT_LIST: blocked'));
                        }
                        if (url.indexOf('jet-cct') === -1) return origFetch.apply(this, arguments);
                        return origFetch.apply(this, arguments).then(function(resp){
                            return resp.clone().text().then(function(text){
                                try {
                                    var data = JSON.parse(text);
                                    sanitize(data);
                                    return new Response(JSON.stringify(data), {
                                        status: resp.status, statusText: resp.statusText, headers: resp.headers
                                    });
                                } catch(e) { return resp; }
                            });
                        });
                    };
                }

                var origOpen = XMLHttpRequest.prototype.open;
                var origSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function(method, url){
                    this._smfUrl = url || '';
                    this._smfMethod = method || 'GET';
                    return origOpen.apply(this, arguments);
                };
                XMLHttpRequest.prototype.send = function(body){
                    var self = this;
                    if (self._smfUrl && self._smfUrl.indexOf('jet-cct') !== -1) {
                        if (isWriteMethod(self._smfMethod) && hasActiveMasked()) {
                            console.warn('[SMF_CCT_LIST] Submit (XHR) bloqueado — campos protegidos no modal.');
                            alert(WARN_MSG);
                            self.abort();
                            return;
                        }
                        self.addEventListener('readystatechange', function(){
                            if (self.readyState === 4) {
                                try {
                                    var data = JSON.parse(self.responseText);
                                    sanitize(data);
                                    Object.defineProperty(self, 'responseText', { value: JSON.stringify(data), configurable: true });
                                    Object.defineProperty(self, 'response',     { value: JSON.stringify(data), configurable: true });
                                } catch(e){}
                            }
                        });
                    }
                    return origSend.call(this, body);
                };
            })();

            function maskInput(input){
                if (input.dataset.smfListDone === '1') return;
                input.dataset.smfListDone = '1';
                input.type = 'password';
                input.readOnly = true;
                input.autocomplete = 'new-password';
                input.value = D.placeholder;
                input.setAttribute('value','');
                input.setAttribute('data-smf-state','masked');
                maskedInputs.push(input);
                $(input).off('.smfblock').on('keydown.smfblock paste.smfblock cut.smfblock drop.smfblock',
                    function(e){ e.preventDefault(); });
            }

            function scan(){
                (D.fields || []).forEach(function(field){
                    document.querySelectorAll('input[name="'+field+'"]').forEach(maskInput);
                });
            }

            // Reforço extra: bloqueia clique no botão de salvar do modal
            $(document).on('click', '.cx-modal .button-primary, .cx-modal [type="submit"], button.cx-modal__action_primary',
            function(e){
                if (hasActiveMasked()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    console.warn('[SMF_CCT_LIST] Submit (click) bloqueado — campos protegidos no modal.');
                    alert(WARN_MSG);
                    return false;
                }
            });

            function init(){
                scan();
                new MutationObserver(function(){
                    scan();
                    setTimeout(scan, 100);
                    setTimeout(scan, 300);
                }).observe(document.body, { childList:true, subtree:true });
            }

            if (document.readyState === 'complete') init();
            else $(window).on('load', init);
        })(jQuery);
JS;
    }
}

new SMF_CCT();