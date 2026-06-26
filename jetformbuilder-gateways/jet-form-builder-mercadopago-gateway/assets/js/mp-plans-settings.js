/**
 * Mercado Pago Plans — aba de settings do JetFormBuilder (Vue 2, render-function,
 * sem build). Registra-se no SPA de settings via o filtro
 * `jet.fb.register.settings-page.tabs` (mesma mecânica do MailChimp/ActiveCampaign).
 *
 * UI/UX 100% NATIVA: usa os componentes globais do framework Croco
 * (`cx-vui-input`, `cx-vui-select`, `cx-vui-switcher`, `cx-vui-button`), registrados
 * globalmente por `cxVueUi.registerGlobalComponents(Vue)`. Padrões confirmados lendo
 * o CORE (não supostos):
 *   - campo = `cx-vui-*` com `wrapper-css:["equalwidth"]` + `size:"fullwidth"`
 *     (linha label↔controle 49/49, igual MailChimp/GetResponse/Gateways);
 *   - botão = `cx-vui-button` com label em `slot="label"`; `button-style`:
 *       "accent" (azul primário), "accent-border" (azul contornado, secundário),
 *       "link-error" (#c92c2c, vermelho — excluir);
 *   - separadores: cada `.cx-vui-component` já tem `border-top:#ececec`; títulos de
 *     seção e linhas de plano ficam em mp-plans-settings.css com os MESMOS tokens.
 *
 * SEGURANÇA: NÃO trafega Access Token. Os endpoints REST usam SEMPRE a chave do
 * gateway (server-side). O JS só dispara list/create/delete com o nonce REST.
 */
(function () {
	'use strict';

	var CFG = window.JFB_MP_PLANS || { urls: {}, i18n: {}, hasToken: false };
	var t = CFG.i18n || {};

	// Moedas aceitas pelo Mercado Pago (uma por país onde o MP opera). Evita digitar
	// errado / deixar em branco. Em assinatura (preapproval_plan) a moeda precisa ser
	// a do país da conta — por isso um select fechado em vez de texto livre.
	var CURRENCIES = [
		{ value: 'BRL', label: 'BRL — Real (Brasil)' },
		{ value: 'ARS', label: 'ARS — Peso (Argentina)' },
		{ value: 'CLP', label: 'CLP — Peso (Chile)' },
		{ value: 'COP', label: 'COP — Peso (Colômbia)' },
		{ value: 'MXN', label: 'MXN — Peso (México)' },
		{ value: 'PEN', label: 'PEN — Sol (Peru)' },
		{ value: 'UYU', label: 'UYU — Peso (Uruguai)' }
	];

	if ( ! window.wp || ! window.wp.hooks || ! window.wp.hooks.addFilter ) {
		return;
	}

	function api( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( d ) {
				if ( ! r.ok ) {
					throw new Error( ( d && d.message ) || ( 'HTTP ' + r.status ) );
				}
				return d;
			} );
		} );
	}

	var component = {
		name: 'jfb-mp-plans',
		// O SPA do JFB injeta `incoming` (dados salvos da aba) e `inner-slugs` como
		// props em TODA aba (jfb-settings.js: attrs:{incoming,...,"inner-slugs"}).
		// Declaramos para não vazarem como atributos no DOM. Não os usamos — esta aba
		// não persiste no store de settings; faz CRUD via REST.
		props: {
			incoming: { type: [ Object, Array ], default: function () { return {}; } },
			innerSlugs: { type: Array, default: function () { return []; } }
		},
		data: function () {
			return {
				plans: [],
				loading: false,
				busy: false,
				notice: '',
				noticeType: '',
				showCancelled: false,
				form: { reason: '', amount: '', frequency: 1, frequency_type: 'months', currency: 'BRL' }
			};
		},
		computed: {
			// Planos cancelados/excluídos (flag `disabled` vinda do endpoint).
			cancelledPlans: function () {
				return this.plans.filter( function ( p ) { return p.disabled; } );
			},
			// O que a lista mostra: ativos sempre; cancelados só com o switcher ligado.
			visiblePlans: function () {
				var show = this.showCancelled;
				return this.plans.filter( function ( p ) { return show || ! p.disabled; } );
			}
		},
		created: function () {
			this.load();
		},
		methods: {
			setNotice: function ( msg, type ) {
				this.notice = msg || '';
				this.noticeType = type || '';
			},
			load: function () {
				var self = this;
				if ( ! CFG.hasToken ) {
					self.setNotice( t.noToken, 'error' );
					return;
				}
				self.loading = true;
				self.setNotice( '' );
				// include_cancelled: a aba de gestão precisa VER os cancelados (status +
				// switcher). O editor NÃO manda isso → recebe só os ativos.
				api( CFG.urls.list, { force_refresh: true, include_cancelled: true } )
					.then( function ( res ) { self.plans = ( res && res.data ) || []; } )
					.catch( function ( e ) { self.setNotice( e.message, 'error' ); } )
					.then( function () { self.loading = false; } );
			},
			create: function () {
				var self = this;
				var f = self.form;

				// Todos os campos são obrigatórios (defesa na UI; o endpoint também valida).
				var missing = [];
				if ( ! String( f.reason ).trim() ) { missing.push( t.fReason || 'Nome' ); }
				if ( ! ( Number( f.amount ) > 0 ) ) { missing.push( t.fAmount || 'Valor' ); }
				if ( ! ( Number( f.frequency ) > 0 ) ) { missing.push( t.fFrequency || 'Frequência' ); }
				if ( ! f.frequency_type ) { missing.push( t.fType || 'Tipo' ); }
				if ( ! f.currency ) { missing.push( t.fCurrency || 'Moeda' ); }
				if ( missing.length ) {
					self.setNotice( ( t.required || 'Preencha os campos obrigatórios:' ) + ' ' + missing.join( ', ' ), 'error' );
					return;
				}

				self.busy = true;
				self.setNotice( t.loading || '…' );
				api( CFG.urls.create, {
					reason: self.form.reason,
					amount: self.form.amount,
					frequency: self.form.frequency,
					frequency_type: self.form.frequency_type,
					currency: self.form.currency
				} ).then( function ( res ) {
					var id = res && res.plan ? res.plan.id : '';
					self.setNotice( ( t.created || 'Criado!' ) + ( id ? ' (' + id + ')' : '' ), 'success' );
					self.form.reason = '';
					self.form.amount = '';
					self.load();
				} ).catch( function ( e ) {
					self.setNotice( e.message, 'error' );
				} ).then( function () { self.busy = false; } );
			},
			remove: function ( id ) {
				var self = this;
				if ( ! window.confirm( t.confirmDelete ) ) {
					return;
				}
				self.setNotice( t.loading || '…' );
				api( CFG.urls.delete, { id: id } )
					.then( function () { self.setNotice( t.deleted, 'success' ); self.load(); } )
					.catch( function ( e ) { self.setNotice( e.message, 'error' ); } );
			}
		},
		render: function ( h ) {
			var self = this;

			// --- helpers que montam os componentes NATIVOS cx-vui ----------------
			// campo de texto/número (linha equalwidth + controle fullwidth)
			function field( key, label, attrs ) {
				return h( 'cx-vui-input', {
					attrs: Object.assign(
						{ label: label, 'wrapper-css': [ 'equalwidth' ], size: 'fullwidth', value: self.form[ key ] },
						attrs || {}
					),
					on: { input: function ( v ) { self.form[ key ] = v; } }
				} );
			}

			// select nativo (options-list = [{value,label}])
			function select( key, label, options ) {
				return h( 'cx-vui-select', {
					attrs: {
						label: label,
						'wrapper-css': [ 'equalwidth' ],
						size: 'fullwidth',
						'options-list': options,
						value: self.form[ key ]
					},
					on: { input: function ( v ) { self.form[ key ] = v; } }
				} );
			}

			// botão nativo; label vai no slot "label" (igual o "Save" do core)
			function button( label, style, handlers, attrs ) {
				return h( 'cx-vui-button', {
					attrs: Object.assign( { 'button-style': style }, attrs || {} ),
					on: handlers
				}, [ h( 'span', { attrs: { slot: 'label' }, slot: 'label' }, label ) ] );
			}

			function sectionTitle( text ) {
				return h( 'h3', { staticClass: 'jfb-mp-plans__title' }, text );
			}

			var children = [];

			// ---- título + intro ------------------------------------------------
			children.push( h( 'h2', { staticClass: 'jfb-mp-plans__page-title' }, t.pageTitle || 'Mercado Pago Gateway' ) );
			children.push( h( 'p', { staticClass: 'fb-description jfb-mp-plans__intro' }, t.intro || '' ) );

			if ( ! CFG.hasToken ) {
				children.push( h( 'div', { staticClass: 'cx-vui-notice cx-vui-notice--warning jfb-mp-plans__notice-box' }, t.noToken || '' ) );
			}

			// ---- seção: planos existentes -------------------------------------
			children.push( sectionTitle( t.existing || 'Planos existentes' ) );

			// switcher "mostrar excluídos" — só quando há cancelados
			if ( self.cancelledPlans.length ) {
				children.push( h( 'cx-vui-switcher', {
					attrs: {
						label: t.showCancelled || 'Mostrar excluídos',
						description: ( t.showCancelledDesc || 'Planos cancelados (%d).' ).replace( '%d', self.cancelledPlans.length ),
						'wrapper-css': [ 'equalwidth' ],
						value: self.showCancelled
					},
					on: { input: function ( v ) { self.showCancelled = v; } }
				} ) );
			}

			// lista (painel cinza nativo)
			var listInner;
			if ( self.loading ) {
				listInner = [ h( 'div', { staticClass: 'jfb-mp-plans__empty' }, t.loading || '…' ) ];
			} else if ( ! self.visiblePlans.length ) {
				listInner = [ h( 'div', { staticClass: 'jfb-mp-plans__empty' }, t.empty || 'Nenhum plano.' ) ];
			} else {
				listInner = self.visiblePlans.map( function ( p ) {
					var active = ! p.disabled;
					var unit = p.frequency_type === 'days' ? ( t.days || 'dia(s)' ) : ( t.months || 'mês(es)' );
					var freq = ( p.frequency && p.frequency_type ) ? ( ( t.every || 'a cada' ) + ' ' + p.frequency + ' ' + unit ) : '—';
					var val = ( p.currency || 'BRL' ) + ' ' + ( p.amount != null ? Number( p.amount ).toFixed( 2 ) : '—' );

					return h( 'div', { staticClass: 'jfb-mp-plans__row' }, [
						h( 'div', { staticClass: 'jfb-mp-plans__row-main' }, [
							h( 'strong', { staticClass: 'jfb-mp-plans__row-name' }, p.reason || '(sem nome)' ),
							h( 'div', { staticClass: 'jfb-mp-plans__row-meta' }, [
								val + ' · ' + freq + ' · ',
								h( 'span', { staticClass: active ? 'jfb-mp-plans__badge is-active' : 'jfb-mp-plans__badge is-cancelled' }, p.status || ( active ? 'active' : 'cancelled' ) )
							] ),
							h( 'code', { staticClass: 'jfb-mp-plans__row-id' }, p.id )
						] ),
						button(
							t.delete || 'Excluir',
							'link-error',
							{ click: function () { self.remove( p.id ); } },
							{ size: 'mini', disabled: ! active }
						)
					] );
				} );
			}

			children.push( h( 'div', { staticClass: 'cx-vui-inner-panel jfb-mp-plans__list' }, listInner ) );
			children.push( h( 'div', { staticClass: 'jfb-mp-plans__actions' }, [
				button( t.refresh || 'Atualizar lista', 'accent-border', { click: self.load }, { disabled: self.loading } )
			] ) );

			// ---- seção: criar novo plano --------------------------------------
			children.push( sectionTitle( t.createTitle || 'Criar novo plano' ) );
			children.push( field( 'reason', t.fReason || 'Nome / descrição', { placeholder: 'Plano Mensal Premium' } ) );
			children.push( field( 'amount', t.fAmount || 'Valor', { type: 'number', step: '0.01', min: '0.5', placeholder: '10.00' } ) );
			children.push( field( 'frequency', t.fFrequency || 'Frequência', { type: 'number', min: '1', placeholder: '1' } ) );
			children.push( select( 'frequency_type', t.fType || 'Tipo de frequência', [
				{ value: 'months', label: t.months || 'mês(es)' },
				{ value: 'days', label: t.days || 'dia(s)' }
			] ) );
			children.push( select( 'currency', t.fCurrency || 'Moeda', CURRENCIES ) );
			children.push( h( 'div', { staticClass: 'jfb-mp-plans__actions' }, [
				button( t.createBtn || 'Criar plano', 'accent', { click: self.create }, { disabled: self.busy } )
			] ) );

			// ---- notice --------------------------------------------------------
			if ( self.notice ) {
				children.push( h( 'div', {
					staticClass: 'jfb-mp-plans__notice is-' + ( self.noticeType || 'info' )
				}, self.notice ) );
			}

			return h( 'section', { staticClass: 'jfb-mp-plans' }, children );
		}
	};

	wp.hooks.addFilter(
		'jet.fb.register.settings-page.tabs',
		'jet-form-builder-mercadopago/plans',
		function ( tabs ) {
			// SHAPE confirmado lendo o CORE do JFB (assets/build/admin/pages/jfb-settings.js):
			//   const Kt = applyFilters('jet.fb.register.settings-page.tabs', [...])  // no load do SPA
			//   render: attrs:{ name:s.component.name, label:s.title, disabled:s.disabled, icon }
			//           + (s.component.render ? renderiza s.component : nada)
			//           + (t.displayButton!==false ? botão Salvar -> getRequestOnSave() : nada)
			// Logo:
			//   • `title`     -> STRING (vira o label da aba);
			//   • `component` -> OBJETO Vue COM `name` e `render` (o SPA lê os dois);
			//   • `displayButton:false` -> esconde o botão "Salvar" genérico, que chamaria
			//     getRequestOnSave() (método que NÃO temos: a aba salva via REST própria).
			// (O addon AC do core usa ()=>c/()=>s, mas são GETTERS do webpack — não funções.)
			tabs.push( {
				title: t.title || 'Mercado Pago Plans',
				component: component,
				displayButton: false
			} );
			return tabs;
		}
	);
})();
