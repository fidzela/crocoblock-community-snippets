/**
 * Fontes externas de cálculo — Sessão Tattoo
 *
 * O campo 'tatuador' (tipo POST do JetEngine — exibe post_title, grava o ID)
 * é o MOTIVO de alguns campos do produto terem ou não valor. Ele se comporta
 * como qualquer outra fonte de cálculo: ao mudar, dispara recálculo ao vivo.
 *
 * São TRÊS tipos de campo controlados pelo tatuador:
 *
 *  1. VALOR PURO  — cópia direta de um meta do tatuador, sem cálculo.
 *                   Ex.: taxa_do_retoque  ←  post 'pro'.taxa_retoque
 *                   Comportamento: readonly. Só espera o 'tatuador'.
 *
 *  2. CÁLCULO     — usa um valor do tatuador + metas do produto.
 *                   Ex.: percentual_dia_artista =
 *                          (tempo_de_sessao / atendimento_maximo_de_horas_por_dia) * 100
 *                   Comportamento: readonly, recalculado.
 *
 *  3. VALIDAÇÃO   — uma meta do produto é checada contra um valor do tatuador.
 *                   Ex.: tempo_de_sessao deve ser <= atendimento_maximo_de_horas_por_dia
 *                   Comportamento: campo editável, mas marcado em erro se violar.
 *
 * COMPORTAMENTO GERAL:
 *   - sem tatuador           → campos puros/calculados vazios; validações off
 *   - tatuador selecionado   → busca os valores do post e aplica
 *   - tatuador removido      → limpa tudo que depende dele
 *
 * Como os valores do tatuador estão em OUTRO post, o editor usa um endpoint
 * AJAX para buscá-los ao vivo a cada troca. O save (server-side) repete a
 * mesma lógica para persistir os metas.
 *
 * PIPELINE NO SAVE (produto tipo 'service'):
 *   JetEngine grava metas (~10) → FontesExternas (90) → CalcProduto_Sincronizador (99)
 *
 * INSTALAÇÃO (child theme):
 *   require_once get_stylesheet_directory()
 *       . '/includes/woocommerce/woocommerce-fontes-externas.php';
 *
 * @package SessaoTattoo
 */

if (!defined('ABSPATH')) return;

if (!class_exists('CalcProduto_FontesExternas')) {
    class CalcProduto_FontesExternas {

        /** Meta no PRODUTO que guarda o ID do post do tatuador (campo tipo POST). */
        const META_TATUADOR = 'tatuador';

        /** Tipo de post esperado para o tatuador. */
        const POST_TYPE_TATUADOR = 'pro';

        /** Action AJAX e nonce. */
        const AJAX_ACTION  = 'st_fontes_tatuador';
        const NONCE_ACTION = 'st_fontes_tatuador_nonce';

        /** Meta (oculto) onde ficam registrados os erros de validação. */
        const META_ERROS = '_st_fontes_erros';

        /* =================================================================
         * MAPAS — fonte única de verdade. São espelhados em PHP e JS.
         * ================================================================= */

        /**
         * VALORES PUROS — cópia direta.
         *   chave = meta_key no PRODUTO (destino, campo readonly)
         *   valor = meta_key no POST do tatuador (origem)
         */
        public static function mapa_tatuador() {
            return [
                'taxa_do_retoque'                     => 'taxa_retoque',
                'atendimento_maximo_de_horas_por_dia' => 'atendimento_maximo_de_horas_por_dia',
            ];
        }

        /**
         * CÁLCULOS que dependem do tatuador.
         *   fontes = chaves; cada uma é meta do produto OU um valor vindo do
         *            tatuador (chave presente em mapa_tatuador()).
         *   op     = operação registrada em aplicar_op() / OPS no JS.
         *   casas  = casas decimais.
         */
        public static function mapa_calculos() {
            return [
                // "% do dia do Artista": quanto da jornada do tatuador esta
                // sessão consome.
                'proporcional_ao_dia_do_artista' => [
                    'fontes' => ['tempo_de_sessao', 'atendimento_maximo_de_horas_por_dia'],
                    'op'     => 'pct',   // (a / b) * 100
                    'casas'  => 1,
                ],
            ];
        }

        /**
         * VALIDAÇÕES.
         *   chave    = meta_key do produto a validar (campo editável)
         *   regra    = regra registrada em aplicar_validacao() / REGRAS no JS
         *   limite   = chave do valor de referência (vindo do tatuador)
         *   mensagem = texto do erro; %s recebe o valor do limite
         */
        public static function mapa_validacoes() {
            return [
                'tempo_de_sessao' => [
                    'regra'    => 'menor_igual',
                    'limite'   => 'atendimento_maximo_de_horas_por_dia',
                    'mensagem' => 'Tempo de sessão acima do limite diário do tatuador (%s h).',
                ],
            ];
        }

        /**
         * [ENGATILHADO — PRÓXIMO PASSO] Valores derivados de categoria/termos.
         * Ainda não está em uso — será tratado depois do tatuador.
         */
        public static function mapa_categorias() {
            return [
                // 'colorida' => [ 'valor_da_categoria' => 150 ],
            ];
        }

        /* =================================================================
         * CALCULADORA MÍNIMA — independente do woocommerce-calculos.php.
         * ================================================================= */

        /** Converte "5,5" / "5.5" / 5 / "" em float ou null. */
        public static function parse_numero($valor) {
            if ($valor === null || $valor === '' || $valor === false) return null;
            if (is_numeric($valor)) return (float) $valor;
            $limpo = str_replace(',', '.', trim((string) $valor));
            return is_numeric($limpo) ? (float) $limpo : null;
        }

        /** Aplica uma operação de cálculo. Retorna float ou null. */
        private static function aplicar_op($op, array $args) {
            switch ($op) {
                case 'pct': // (a / b) * 100
                    $a = self::parse_numero(isset($args[0]) ? $args[0] : null);
                    $b = self::parse_numero(isset($args[1]) ? $args[1] : null);
                    if ($a === null || $b === null || $b == 0.0) return null;
                    return ($a / $b) * 100;
            }
            return null;
        }

        /**
         * Aplica uma regra de validação.
         * Retorna true (ok), false (erro) ou null (sem dados — não valida).
         */
        private static function aplicar_validacao($regra, $valor, $limite) {
            $v = self::parse_numero($valor);
            $l = self::parse_numero($limite);
            if ($v === null || $l === null) return null;

            switch ($regra) {
                case 'menor_igual': return ($v <= $l);
            }
            return null;
        }

        /* =================================================================
         * RESOLUÇÃO
         * ================================================================= */

        /** Confirma que o ID é de um produto do tipo 'service'. */
        private static function eh_servico($product_id) {
            if (!$product_id || get_post_type($product_id) !== 'product') return false;
            if (!function_exists('wc_get_product')) return false;

            $product = wc_get_product($product_id);
            return $product && $product->get_type() === 'service';
        }

        /**
         * Resolve o ID do tatuador da meta do produto.
         * Retorna int > 0 válido, ou 0 se ausente / inválido / tipo errado.
         */
        private static function resolver_id_tatuador($product_id) {
            $raw = get_post_meta($product_id, self::META_TATUADOR, true);
            if (is_array($raw)) $raw = reset($raw); // campo POST multi-valor

            $id = (int) $raw;
            if ($id <= 0) return 0;
            if (get_post_type($id) !== self::POST_TYPE_TATUADOR) return 0;
            return $id;
        }

        /**
         * Lê os valores do tatuador, indexados pela meta_key de DESTINO no
         * produto. Sem tatuador (id = 0) devolve tudo como string vazia.
         *
         * @param int $tatuador_id
         * @return array<string,string>
         */
        public static function valores_do_tatuador($tatuador_id) {
            $out = [];
            foreach (self::mapa_tatuador() as $meta_produto => $meta_tatuador) {
                $out[$meta_produto] = $tatuador_id
                    ? (string) get_post_meta($tatuador_id, $meta_tatuador, true)
                    : '';
            }
            return $out;
        }

        /**
         * Ponto de entrada do save — grava puros, calculados e validações.
         */
        public static function sincronizar($product_id) {
            if (!self::eh_servico($product_id)) return;

            $tatuador_id = self::resolver_id_tatuador($product_id);
            $valores     = self::valores_do_tatuador($tatuador_id);

            // 1) VALORES PUROS — cópia direta. Sem tatuador → '' (limpa).
            foreach ($valores as $meta_produto => $valor) {
                update_post_meta($product_id, $meta_produto, $valor);
            }

            // 2) CÁLCULOS dependentes do tatuador.
            foreach (self::mapa_calculos() as $meta => $cfg) {
                $args = [];
                foreach ($cfg['fontes'] as $fonte) {
                    $args[] = array_key_exists($fonte, $valores)
                        ? $valores[$fonte]
                        : get_post_meta($product_id, $fonte, true);
                }

                $resultado = $tatuador_id ? self::aplicar_op($cfg['op'], $args) : null;

                if ($resultado === null) {
                    update_post_meta($product_id, $meta, '');
                } else {
                    $casas = isset($cfg['casas']) ? (int) $cfg['casas'] : 1;
                    update_post_meta(
                        $product_id,
                        $meta,
                        number_format((float) $resultado, $casas, '.', '')
                    );
                }
            }

            // 3) VALIDAÇÕES — registra erros (não bloqueia o save).
            $erros = [];
            if ($tatuador_id) {
                foreach (self::mapa_validacoes() as $campo => $cfg) {
                    $valor  = get_post_meta($product_id, $campo, true);
                    $limite = array_key_exists($cfg['limite'], $valores)
                        ? $valores[$cfg['limite']]
                        : get_post_meta($product_id, $cfg['limite'], true);

                    if (self::aplicar_validacao($cfg['regra'], $valor, $limite) === false) {
                        $erros[$campo] = sprintf($cfg['mensagem'], $limite);
                    }
                }
            }

            if ($erros) {
                update_post_meta($product_id, self::META_ERROS, $erros);
            } else {
                delete_post_meta($product_id, self::META_ERROS);
            }
        }

        /* =================================================================
         * AJAX — usado pelo editor para buscar valores ao trocar o tatuador.
         * ================================================================= */

        public static function ajax_buscar_tatuador() {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('edit_products')) {
                wp_send_json_error(['mensagem' => 'Sem permissão.']);
            }

            $tatuador_id = isset($_POST['tatuador']) ? absint($_POST['tatuador']) : 0;
            if ($tatuador_id && get_post_type($tatuador_id) !== self::POST_TYPE_TATUADOR) {
                $tatuador_id = 0;
            }

            wp_send_json_success([
                'tatuador' => $tatuador_id,
                'valores'  => self::valores_do_tatuador($tatuador_id),
            ]);
        }

        /* =================================================================
         * ADMIN JS — recálculo ao vivo (espelha a lógica do PHP).
         * ================================================================= */

        /** Valores atuais (salvos) do produto, para semear o cache do JS. */
        private static function valores_atuais_produto($post_id) {
            $out = [];
            foreach (self::mapa_tatuador() as $meta_produto => $ignorado) {
                $out[$meta_produto] = $post_id
                    ? (string) get_post_meta($post_id, $meta_produto, true)
                    : '';
            }
            return $out;
        }

        public static function render_admin_js() {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || $screen->id !== 'product') return;

            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

            $cfg = [
                'META_TATUADOR'    => self::META_TATUADOR,
                'CAMPOS_PUROS'     => array_keys(self::mapa_tatuador()),
                'CALCULOS'         => self::mapa_calculos(),
                'VALIDACOES'       => self::mapa_validacoes(),
                'VALORES_INICIAIS' => self::valores_atuais_produto($post_id),
                'AJAX'             => [
                    'url'    => admin_url('admin-ajax.php'),
                    'action' => self::AJAX_ACTION,
                    'nonce'  => wp_create_nonce(self::NONCE_ACTION),
                ],
            ];
            ?>
            <script id="sessao-tattoo-fontes-js">
            (function($){
                'use strict';

                var CFG = <?php echo wp_json_encode($cfg); ?>;

                // Cache de valores vindos do tatuador (strings cruas).
                var cacheArtista = {};
                // Cache de resultados dos cálculos desta passada.
                var cacheCalc = {};

                /* ---------- helpers numéricos ---------- */

                function parseNum(v){
                    if (v === null || v === undefined) return null;
                    var s = String(v).trim();
                    if (s === '') return null;
                    s = s.replace(',', '.');
                    var n = parseFloat(s);
                    return isNaN(n) ? null : n;
                }

                function fmt(n, casas){
                    if (n === null || n === undefined || isNaN(n)) return '';
                    return n.toFixed(casas).replace('.', ',');
                }

                /* ---------- operações de cálculo (espelham o PHP) ---------- */

                var OPS = {
                    // (a / b) * 100
                    'pct': function(a, b){
                        if (a === null || b === null || b === 0) return null;
                        return (a / b) * 100;
                    }
                };

                /* ---------- regras de validação (espelham o PHP) ---------- */

                var REGRAS = {
                    'menor_igual': function(v, l){ return v <= l; }
                };

                /* ---------- leitura de valores ---------- */

                // Resultado de cálculo → valor do tatuador → campo no DOM.
                function ler(chave){
                    if (cacheCalc.hasOwnProperty(chave))    return cacheCalc[chave];
                    if (cacheArtista.hasOwnProperty(chave)) return parseNum(cacheArtista[chave]);
                    var el = document.getElementById(chave);
                    return el ? parseNum(el.value) : null;
                }

                function setCampo(id, valor){
                    var el = document.getElementById(id);
                    if (el) el.value = (valor === null || valor === undefined) ? '' : valor;
                }

                function idTatuador(){
                    var $f = $('#' + CFG.META_TATUADOR);
                    if (!$f.length) $f = $('[name="' + CFG.META_TATUADOR + '"]').first();
                    var v = $f.val();
                    if (Array.isArray(v)) v = v[0];
                    v = parseInt(v, 10);
                    return (v > 0) ? v : 0;
                }

                /* ---------- aplicação ---------- */

                // Campos de valor puro recebem o valor cru do tatuador.
                function aplicarCamposPuros(){
                    CFG.CAMPOS_PUROS.forEach(function(k){
                        var v = cacheArtista[k];
                        setCampo(k, (v === undefined || v === null) ? '' : v);
                    });
                }

                function recalcular(){
                    cacheCalc = {};
                    Object.keys(CFG.CALCULOS).forEach(function(meta){
                        var cfg = CFG.CALCULOS[meta];
                        var args = cfg.fontes.map(ler);
                        var fn = OPS[cfg.op];
                        var r = fn ? fn.apply(null, args) : null;
                        cacheCalc[meta] = r;
                        setCampo(meta, r === null ? '' : fmt(r, cfg.casas));
                    });
                }

                function validar(){
                    Object.keys(CFG.VALIDACOES).forEach(function(campo){
                        var cfg = CFG.VALIDACOES[campo];
                        var v = ler(campo);
                        var l = ler(cfg.limite);
                        var fn = REGRAS[cfg.regra];

                        var temErro = false, msg = '';
                        if (v !== null && l !== null && fn && !fn(v, l)) {
                            temErro = true;
                            msg = cfg.mensagem.replace('%s', l);
                        }
                        marcarErro(campo, temErro, msg);
                    });
                }

                function marcarErro(campo, temErro, msg){
                    var el = document.getElementById(campo);
                    if (!el) return;

                    var span = document.getElementById('st-erro-' + campo);

                    if (temErro) {
                        el.style.borderColor = '#dc3232';
                        el.style.boxShadow   = '0 0 0 1px #dc3232';
                        if (!span) {
                            span = document.createElement('span');
                            span.id = 'st-erro-' + campo;
                            span.style.cssText =
                                'display:block;color:#dc3232;font-size:11px;' +
                                'line-height:1.4;margin-top:4px;';
                            el.insertAdjacentElement('afterend', span);
                        }
                        span.textContent = msg;
                        span.style.display = 'block';
                    } else {
                        el.style.borderColor = '';
                        el.style.boxShadow   = '';
                        if (span) { span.textContent = ''; span.style.display = 'none'; }
                    }
                }

                function aplicarTudo(){
                    aplicarCamposPuros();
                    recalcular();
                    validar();
                }

                // Sem tatuador: zera caches, esvazia campos, remove erros.
                function limparTudo(){
                    cacheArtista = {};
                    cacheCalc = {};
                    CFG.CAMPOS_PUROS.forEach(function(k){ setCampo(k, ''); });
                    Object.keys(CFG.CALCULOS).forEach(function(m){ setCampo(m, ''); });
                    Object.keys(CFG.VALIDACOES).forEach(function(c){ marcarErro(c, false, ''); });
                }

                /* ---------- busca AJAX ao trocar o tatuador ---------- */

                function buscarTatuador(){
                    var id = idTatuador();
                    if (!id) { limparTudo(); return; }

                    $.post(CFG.AJAX.url, {
                        action:   CFG.AJAX.action,
                        nonce:    CFG.AJAX.nonce,
                        tatuador: id
                    }).done(function(resp){
                        if (resp && resp.success && resp.data && resp.data.valores) {
                            cacheArtista = resp.data.valores; // strings cruas
                            aplicarTudo();
                        } else {
                            limparTudo();
                        }
                    }).fail(function(){
                        limparTudo();
                    });
                }

                /* ---------- inicialização ---------- */

                function travarCampo(id){
                    var el = document.getElementById(id);
                    if (!el) return;
                    el.setAttribute('readonly', 'readonly');
                    el.style.backgroundColor = '#f5f5f5';
                    el.style.cursor = 'not-allowed';
                    el.title = 'Campo automático — definido pelo tatuador';
                    if (!el.value) el.setAttribute('placeholder', 'Aguardando tatuador');
                }

                function init(){
                    // Semeia o cache com os valores já salvos (necessário para
                    // recalcular ao vivo quando só um metacampo muda).
                    cacheArtista = $.extend({}, CFG.VALORES_INICIAIS || {});

                    // Trava campos puros e calculados (readonly).
                    CFG.CAMPOS_PUROS.forEach(travarCampo);
                    Object.keys(CFG.CALCULOS).forEach(travarCampo);

                    // Listener do campo tatuador (delegação cobre select2/JetEngine).
                    var selTat = '#' + CFG.META_TATUADOR;
                    $(document).on(
                        'change select2:select select2:unselect select2:clear',
                        selTat,
                        buscarTatuador
                    );

                    // Listeners nos metacampos editáveis que alimentam
                    // cálculos / validações.
                    var fontes = {};
                    Object.keys(CFG.CALCULOS).forEach(function(m){
                        CFG.CALCULOS[m].fontes.forEach(function(f){ fontes[f] = true; });
                    });
                    Object.keys(CFG.VALIDACOES).forEach(function(c){
                        fontes[c] = true;
                        if (CFG.VALIDACOES[c].limite) fontes[CFG.VALIDACOES[c].limite] = true;
                    });
                    // Remove os não editáveis (puros e calculados).
                    CFG.CAMPOS_PUROS.forEach(function(k){ delete fontes[k]; });
                    Object.keys(CFG.CALCULOS).forEach(function(m){ delete fontes[m]; });

                    var sels = Object.keys(fontes).map(function(f){ return '#' + f; });
                    if (sels.length) {
                        $(document).on('input change', sels.join(','), function(){
                            recalcular();
                            validar();
                        });
                    }

                    // Estado inicial.
                    if (idTatuador()) {
                        aplicarTudo();
                    } else {
                        limparTudo();
                    }
                }

                $(init);

            })(jQuery);
            </script>
            <?php
        }
    }
}

/* =========================================================================
 * HOOKS DE SAVE — prioridade 90 (depois do JetEngine, antes dos cálculos 99).
 * ========================================================================= */
add_action('woocommerce_process_product_meta', function($post_id) {
    CalcProduto_FontesExternas::sincronizar($post_id);
}, 90);

add_action('woocommerce_update_product', function($product_id) {
    CalcProduto_FontesExternas::sincronizar($product_id);
}, 90);

add_action('woocommerce_new_product', function($product_id) {
    CalcProduto_FontesExternas::sincronizar($product_id);
}, 90);

/* =========================================================================
 * AJAX — busca dos valores do tatuador pelo editor.
 * ========================================================================= */
add_action('wp_ajax_' . CalcProduto_FontesExternas::AJAX_ACTION,
    ['CalcProduto_FontesExternas', 'ajax_buscar_tatuador']);

/* =========================================================================
 * ADMIN JS — recálculo ao vivo na tela de edição de produto.
 * ========================================================================= */
add_action('admin_footer', ['CalcProduto_FontesExternas', 'render_admin_js'], 99);