/**
 * Mercado Pago Plans — aba de settings do JetFormBuilder (Vue 2, render-function,
 * sem build). Registra-se no SPA de settings via o filtro
 * `jet.fb.register.settings-page.tabs` (mesma mecânica do MailChimp/ActiveCampaign).
 *
 * Estilo "repeater" (como os glossários): cada plano é um card com
 * título / valor / frequência / tipo / moeda / status + excluir, e um formulário
 * para criar novos.
 *
 * SEGURANÇA: NÃO trafega Access Token. Os endpoints REST usam SEMPRE a chave do
 * gateway (server-side). O JS só dispara list/create/delete com o nonce REST.
 */
(function () {
	'use strict';

	var CFG = window.JFB_MP_PLANS || { urls: {}, i18n: {}, hasToken: false };
	var t = CFG.i18n || {};

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
		// Declaramos para não vazarem como atributos no <div> raiz. Não os usamos —
		// esta aba não persiste no store de settings; faz CRUD via REST.
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
				form: { reason: '', amount: '', frequency: 1, frequency_type: 'months', currency: 'BRL' }
			};
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
				api( CFG.urls.list, { force_refresh: true } )
					.then( function ( res ) { self.plans = ( res && res.data ) || []; } )
					.catch( function ( e ) { self.setNotice( e.message, 'error' ); } )
					.then( function () { self.loading = false; } );
			},
			create: function () {
				var self = this;
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

			function row( label, control ) {
				return h( 'div', { style: 'display:flex;align-items:center;gap:12px;margin-bottom:12px' }, [
					h( 'label', { style: 'min-width:130px;font-weight:600' }, label ),
					control
				] );
			}

			function input( key, attrs ) {
				return h( 'input', {
					attrs: Object.assign( { type: 'text' }, attrs || {} ),
					domProps: { value: self.form[ key ] },
					on: { input: function ( e ) { self.form[ key ] = e.target.value; } },
					style: 'padding:6px 8px;border:1px solid #8c8f94;border-radius:4px'
				} );
			}

			// --- lista de planos (repeater) ---
			var list;
			if ( self.loading ) {
				list = [ h( 'div', { style: 'padding:10px;color:#666' }, t.loading || '…' ) ];
			} else if ( ! self.plans.length ) {
				list = [ h( 'div', { style: 'padding:10px;color:#666' }, t.empty || 'Nenhum plano.' ) ];
			} else {
				list = self.plans.map( function ( p ) {
					var active = ( p.status || 'active' ) === 'active';
					var unit = p.frequency_type === 'days' ? 'dia(s)' : 'mês(es)';
					var freq = ( p.frequency && p.frequency_type ) ? ( 'a cada ' + p.frequency + ' ' + unit ) : '—';
					var val = ( p.currency || 'BRL' ) + ' ' + ( p.amount != null ? Number( p.amount ).toFixed( 2 ) : '—' );

					return h( 'div', { style: 'display:flex;align-items:center;gap:16px;padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:8px;background:#fff' }, [
						h( 'div', { style: 'flex:1' }, [
							h( 'strong', p.reason || '(sem nome)' ),
							h( 'div', { style: 'font-size:12px;color:#646970;margin:3px 0' }, [
								val + ' · ' + freq + ' · ',
								h( 'span', { style: 'color:' + ( active ? '#1a7f37' : '#b32d2e' ) }, p.status || 'active' )
							] ),
							h( 'code', { style: 'font-size:11px;color:#8c8f94' }, p.id )
						] ),
						h( 'button', {
							attrs: { type: 'button', disabled: ! active },
							class: 'button button-link-delete',
							on: { click: function () { self.remove( p.id ); } }
						}, t.delete || 'Excluir' )
					] );
				} );
			}

			var children = [
				h( 'h2', t.title || 'Mercado Pago — Planos de Assinatura' ),
				h( 'p', { style: 'color:#555;max-width:760px' }, t.intro || '' )
			];

			if ( ! CFG.hasToken ) {
				children.push( h( 'div', { class: 'notice notice-warning', style: 'padding:10px;margin:10px 0' }, t.noToken || '' ) );
			}

			children.push( h( 'h3', t.existing || 'Planos existentes' ) );
			children.push( h( 'div', list ) );
			children.push( h( 'p', [
				h( 'button', { attrs: { type: 'button', disabled: self.loading }, class: 'button', on: { click: self.load } }, t.refresh || 'Atualizar lista' )
			] ) );

			children.push( h( 'h3', { style: 'margin-top:26px' }, t.createTitle || 'Criar novo plano' ) );
			children.push( row( t.fReason || 'Nome / descrição', input( 'reason', { placeholder: 'Plano Mensal Premium' } ) ) );
			children.push( row( t.fAmount || 'Valor', input( 'amount', { type: 'number', step: '0.01', min: '0.5', placeholder: '10.00' } ) ) );
			children.push( row( ( t.fFrequency || 'Frequência' ) + ' / ' + ( t.fType || 'Tipo' ), h( 'div', { style: 'display:flex;gap:8px;align-items:center' }, [
				input( 'frequency', { type: 'number', min: '1', style: 'width:90px' } ),
				h( 'select', {
					domProps: { value: self.form.frequency_type },
					on: { change: function ( e ) { self.form.frequency_type = e.target.value; } },
					style: 'padding:6px;border:1px solid #8c8f94;border-radius:4px'
				}, [
					h( 'option', { attrs: { value: 'months' } }, t.months || 'mês(es)' ),
					h( 'option', { attrs: { value: 'days' } }, t.days || 'dia(s)' )
				] )
			] ) ) );
			children.push( row( t.fCurrency || 'Moeda', input( 'currency', { maxlength: '3', style: 'width:90px' } ) ) );
			children.push( h( 'p', [
				h( 'button', { attrs: { type: 'button', disabled: self.busy }, class: 'button button-primary', on: { click: self.create } }, t.createBtn || 'Criar plano' )
			] ) );

			if ( self.notice ) {
				children.push( h( 'div', {
					style: 'margin-top:12px;font-size:13px;color:' + ( self.noticeType === 'error' ? '#b32d2e' : ( self.noticeType === 'success' ? '#1a7f37' : '#646970' ) )
				}, self.notice ) );
			}

			return h( 'div', { style: 'max-width:860px;padding:8px 4px' }, children );
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
