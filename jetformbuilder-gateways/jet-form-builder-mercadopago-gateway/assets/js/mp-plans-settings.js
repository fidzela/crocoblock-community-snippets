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

	// Tipos que o MP NAO deixa excluir do Checkout Pro -> sempre ATIVOS (espelha
	// Payment_Methods_Config::NEVER_EXCLUDE no servidor). O switcher fica travado on.
	var NEVER_EXCLUDE = [ 'account_money' ];
	function alwaysOn( id ) { return NEVER_EXCLUDE.indexOf( id ) !== -1; }

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

	// Formatação POR MOEDA (símbolo, casas decimais e separadores). Em vez de tudo
	// herdar o formato BRL, cada moeda formata do seu jeito. CLP não usa centavos.
	// (Estrutura local da aba; a formatação GLOBAL — Payments/Subscriptions — vira
	// um módulo à parte, ver MARCO-ASSINATURA §6.)
	var CURRENCY_FMT = {
		BRL: { symbol: 'R$',  decimals: 2, dec: ',', thou: '.' },
		ARS: { symbol: '$',   decimals: 2, dec: ',', thou: '.' },
		CLP: { symbol: '$',   decimals: 0, dec: ',', thou: '.' },
		COP: { symbol: '$',   decimals: 2, dec: ',', thou: '.' },
		MXN: { symbol: '$',   decimals: 2, dec: '.', thou: ',' },
		PEN: { symbol: 'S/',  decimals: 2, dec: '.', thou: ',' },
		UYU: { symbol: '$U',  decimals: 2, dec: ',', thou: '.' }
	};

	function moneyFmt( currency ) {
		return CURRENCY_FMT[ currency ] || CURRENCY_FMT.BRL;
	}

	// Formata um número conforme a moeda: símbolo + milhar + decimal corretos.
	function formatMoney( value, currency ) {
		var f = moneyFmt( currency );
		var n = Number( value );

		if ( ! isFinite( n ) ) {
			n = 0;
		}

		var parts = n.toFixed( f.decimals ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, f.thou );

		return f.symbol + ' ' + ( parts.length > 1 ? parts.join( f.dec ) : parts[ 0 ] );
	}

	// Data ISO -> dd/mm/aaaa (pt-BR). Vazio se não houver/for inválida.
	function formatDate( iso ) {
		if ( ! iso ) {
			return '';
		}

		var d = new Date( iso );

		return isNaN( d.getTime() ) ? '' : d.toLocaleDateString( 'pt-BR' );
	}

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
				showHelp: false,
				form: { reason: '', amount: '', frequency: 1, frequency_type: 'months', currency: 'BRL' },
				// Meios de pagamento por-formulário (Pay Now). pmKept[typeId]=true => MANTÉM
				// (o resto é excluído). pmFormId = formulário sendo configurado.
				pmFormId: '',
				pmTypes: [],
				pmKept: {},
				pmSynced: false,
				pmLoading: false,
				pmBusy: false
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
			toggleHelp: function ( state ) {
				this.showHelp = ( true === state || false === state ) ? state : ! this.showHelp;
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
			},

			// --- meios de pagamento (Pay Now) -----------------------------------
			// Ha config salva (option isolada) para o formulario escolhido?
			pmHasConfig: function () {
				return !! ( CFG.formExclusions && this.pmFormId &&
					Object.prototype.hasOwnProperty.call( CFG.formExclusions, this.pmFormId ) );
			},
			// SYNC: busca os meios ATUAIS da conta no MP (dinamico). Exige um
			// formulario escolhido primeiro -- a config e POR formulario.
			syncPm: function () {
				var self = this;
				if ( ! CFG.hasToken ) { self.setNotice( t.noToken, 'error' ); return; }
				if ( ! self.pmFormId ) { self.setNotice( t.pmPickForm || 'Escolha um formulario primeiro.', 'error' ); return; }
				self.pmLoading = true;
				self.setNotice( '' );
				api( CFG.urls.pmList, { force_refresh: true } )
					.then( function ( res ) {
						self.pmTypes = ( res && res.data ) || [];
						self.pmSynced = true;
						self.applyFormExclusions();
						self.setNotice( ( t.pmSyncedMsg || 'Ha %d meios de pagamento disponiveis e sincronizados com o Mercado Pago.' ).replace( '%d', self.pmTypes.length ), 'success' );
					} )
					.catch( function ( e ) { self.setNotice( e.message, 'error' ); } )
					.then( function () { self.pmLoading = false; } );
			},
			// Deriva pmKept (boolean) das exclusoes salvas do form. Tipos que o MP
			// nao deixa excluir (saldo) ficam SEMPRE ativos.
			applyFormExclusions: function () {
				var ex = ( CFG.formExclusions && this.pmFormId ) ? ( CFG.formExclusions[ this.pmFormId ] || [] ) : [];
				var kept = {};
				this.pmTypes.forEach( function ( tp ) {
					kept[ tp.id ] = alwaysOn( tp.id ) ? true : ( ex.indexOf( tp.id ) === -1 );
				} );
				this.pmKept = kept;
			},
			onSelectForm: function ( v ) {
				this.pmFormId = v;
				// Reaplica as exclusoes do form recem-escolhido se ja sincronizamos os
				// meios antes -> trocar de formulario NAO exige novo SYNC.
				if ( this.pmTypes.length ) { this.applyFormExclusions(); }
			},
			togglePm: function ( id, val ) {
				if ( alwaysOn( id ) ) { return; } // saldo: travado ligado (MP nao deixa excluir)
				// Substitui o objeto inteiro com booleans limpos -> reatividade garantida
				// no Vue 2 e elimina o "" que o switcher emite como returnFalse (a causa de
				// o 2o save nao "pegar" a alteracao e parecer que nao sobrescreve).
				var self = this;
				var next = {};
				this.pmTypes.forEach( function ( tp ) {
					next[ tp.id ] = alwaysOn( tp.id ) ? true : !! self.pmKept[ tp.id ];
				} );
				next[ id ] = !! val;
				this.pmKept = next;
			},
			savePm: function () {
				var self = this;
				if ( ! self.pmFormId ) { self.setNotice( t.pmPickForm || 'Escolha um formulario primeiro.', 'error' ); return; }
				// Excluidos = tipos NAO mantidos, exceto os que o MP nunca deixa excluir.
				var excluded = self.pmTypes
					.filter( function ( tp ) { return ! alwaysOn( tp.id ) && ! self.pmKept[ tp.id ]; } )
					.map( function ( tp ) { return tp.id; } );
				var excludable = self.pmTypes.filter( function ( tp ) { return ! alwaysOn( tp.id ); } ).length;
				if ( excludable && excluded.length >= excludable ) {
					self.setNotice( t.pmKeepOne || 'Mantenha pelo menos um meio de pagamento ativo.', 'error' );
					return;
				}
				self.pmBusy = true;
				self.setNotice( t.loading || '...' );
				return api( CFG.urls.pmSave, { form_id: self.pmFormId, excluded: excluded } )
					.then( function ( res ) {
						// Verdade canonica = o que o servidor confirmou ter excluido (ja sem saldo).
						var saved = ( res && res.excluded ) ? res.excluded : excluded;
						if ( ! CFG.formExclusions ) { CFG.formExclusions = {}; }
						CFG.formExclusions[ self.pmFormId ] = saved;
						self.applyFormExclusions(); // re-sincroniza a tela com o que foi salvo
						self.setNotice( t.pmSaved || 'Meios de pagamento salvos!', 'success' );
					} )
					.catch( function ( e ) { self.setNotice( e.message, 'error' ); } )
					.then( function () { self.pmBusy = false; } );
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
			children.push( h( 'p', { staticClass: 'fb-description jfb-mp-plans__intro' }, [
				( t.intro || '' ) + ' ',
				h( 'a', {
					attrs: { href: '#' },
					staticClass: 'jfb-mp-plans__help-link',
					on: { click: function ( e ) { e.preventDefault(); self.toggleHelp( true ); } }
				}, t.helpLink || 'Como funciona? →' )
			] ) );

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
					var val = ( p.amount != null ) ? formatMoney( p.amount, p.currency || 'BRL' ) : '—';

					// Datas. "Excluído" só vale para planos DESATIVADOS PELO DONO daqui
					// (nós só cancelamos via UI), por isso a nomenclatura é explícita —
					// não confundir com status interno do MP.
					var created = formatDate( p.date_created );
					var endedOn = active ? '' : formatDate( p.last_modified );
					var dates   = [];
					if ( created ) { dates.push( ( t.createdOn || 'Criado em' ) + ' ' + created ); }
					if ( endedOn ) { dates.push( ( t.cancelledOn || 'Excluído em' ) + ' ' + endedOn ); }

					return h( 'div', { staticClass: 'jfb-mp-plans__row' + ( active ? '' : ' is-cancelled' ) }, [
						h( 'div', { staticClass: 'jfb-mp-plans__row-main' }, [
							h( 'strong', { staticClass: 'jfb-mp-plans__row-name' }, p.reason || '(sem nome)' ),
							h( 'div', { staticClass: 'jfb-mp-plans__row-meta' }, [
								val + ' · ' + freq + ' · ',
								h( 'span', { staticClass: active ? 'jfb-mp-plans__badge is-active' : 'jfb-mp-plans__badge is-cancelled' }, active ? ( t.statusActive || 'Ativo' ) : ( t.statusCancelled || 'Excluído pelo dono' ) )
							] ),
							dates.length ? h( 'div', { staticClass: 'jfb-mp-plans__row-dates' }, dates.join( ' · ' ) ) : null,
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
			// Ordem: a MOEDA vem antes do VALOR — é ela que define como o valor é
			// formatado (símbolo/decimais), então o valor "herda" o formato da moeda
			// escolhida (não mais sempre BRL).
			children.push( sectionTitle( t.createTitle || 'Criar novo plano' ) );
			children.push( field( 'reason', t.fReason || 'Nome / descrição', { placeholder: 'Plano Mensal Premium' } ) );
			children.push( select( 'currency', t.fCurrency || 'Moeda', CURRENCIES ) );

			var curFmt = moneyFmt( self.form.currency );
			children.push( field( 'amount', t.fAmount || 'Valor do plano', {
				type: 'number',
				step: curFmt.decimals ? '0.01' : '1',
				min: '0',
				placeholder: curFmt.decimals ? '10.00' : '10'
			} ) );

			// Preview dinâmico: mostra como o valor fica formatado NA MOEDA escolhida.
			if ( '' !== String( self.form.amount ) && Number( self.form.amount ) > 0 ) {
				children.push( h( 'div', { staticClass: 'jfb-mp-plans__amount-preview' }, [
					( t.willCharge || 'Valor formatado:' ) + ' ',
					h( 'strong', formatMoney( self.form.amount, self.form.currency ) )
				] ) );
			}

			children.push( field( 'frequency', t.fFrequency || 'Frequência', { type: 'number', min: '1', placeholder: '1' } ) );
			children.push( select( 'frequency_type', t.fType || 'Tipo de frequência', [
				{ value: 'months', label: t.months || 'mês(es)' },
				{ value: 'days', label: t.days || 'dia(s)' }
			] ) );
			children.push( h( 'div', { staticClass: 'jfb-mp-plans__actions' }, [
				button( t.createBtn || 'Criar plano', 'accent', { click: self.create }, { disabled: self.busy } )
			] ) );

			// ---- secao: meios de pagamento (Pay Now) --------------------------
			children.push( sectionTitle( t.pmTitle || 'Meios de pagamento (Pay Now)' ) );
			children.push( h( 'p', { staticClass: 'fb-description jfb-mp-plans__intro' }, t.pmIntro || '' ) );

			// seletor de formulario
			children.push( h( 'cx-vui-select', {
				attrs: {
					label: t.pmForm || 'Formulario',
					'wrapper-css': [ 'equalwidth' ],
					size: 'fullwidth',
					'options-list': ( CFG.forms || [] ),
					value: self.pmFormId
				},
				on: { input: self.onSelectForm }
			} ) );

			// separador + respiro apos o seletor (bloco de meios nao cola no botao). E3.
			children.push( h( 'div', { staticClass: 'jfb-mp-plans__pm-sep' } ) );

			if ( ! self.pmFormId ) {
				// E1: sem formulario escolhido, nao deixa sincronizar -- so instrui.
				children.push( h( 'div', { staticClass: 'jfb-mp-plans__hint' }, t.pmChooseFirst || 'Escolha um formulario acima para configurar e sincronizar os meios de pagamento.' ) );
			} else {
				// D: form sem config -> avisa o DEFAULT (so cartoes de credito + saldo).
				if ( ! self.pmHasConfig() ) {
					children.push( h( 'div', { staticClass: 'cx-vui-notice cx-vui-notice--warning jfb-mp-plans__notice-box' }, t.pmDefaultNote || 'Este formulario ainda nao tem meios definidos: por padrao, aceita apenas cartoes de credito (e o saldo Mercado Pago, que nao pode ser desativado). Sincronize e salve para personalizar.' ) );
				}

				// botao SYNC dos meios da conta
				children.push( h( 'div', { staticClass: 'jfb-mp-plans__actions' }, [
					button( t.pmSync || 'Sincronizar meios do Mercado Pago', 'accent-border', { click: self.syncPm }, { disabled: self.pmLoading } )
				] ) );

				// switchers 'manter ativo' + salvar -- so apos SYNC
				if ( self.pmSynced ) {
					if ( self.pmTypes.length ) {
						var pmInner = self.pmTypes.map( function ( tp ) {
							var locked = alwaysOn( tp.id );
							return h( 'cx-vui-switcher', {
								key: tp.id,
								attrs: {
									label: tp.label + ( tp.methods ? ' \u2014 ' + tp.methods : '' ),
									description: locked ? ( t.pmAlwaysOn || 'Sempre disponivel -- o Mercado Pago nao permite desativar o saldo.' ) : '',
									'wrapper-css': [ 'equalwidth' ],
									value: !! self.pmKept[ tp.id ],
									disabled: locked
								},
								on: { input: function ( val ) { self.togglePm( tp.id, val ); } }
							} );
						} );
						children.push( h( 'div', { key: 'pm-panel-' + self.pmFormId, staticClass: 'cx-vui-inner-panel jfb-mp-plans__list' }, pmInner ) );
						children.push( h( 'div', { staticClass: 'jfb-mp-plans__actions' }, [
							button( t.pmSave2 || 'Salvar meios deste formulario', 'accent', { click: self.savePm }, { disabled: self.pmBusy } )
						] ) );
					} else {
						children.push( h( 'div', { staticClass: 'jfb-mp-plans__empty' }, t.pmEmpty || 'Nenhum meio retornado.' ) );
					}
				}
			}

			// ---- notice --------------------------------------------------------
			if ( self.notice ) {
				children.push( h( 'div', {
					staticClass: 'jfb-mp-plans__notice is-' + ( self.noticeType || 'info' )
				}, self.notice ) );
			}

			// ---- popup de documentação (premium) ------------------------------
			if ( self.showHelp ) {
				var docs = [
					{ label: t.docSubs || 'Assinaturas — visão geral (docs MP)', url: 'https://www.mercadopago.com.br/developers/pt/docs/subscriptions/landing' },
					{ label: t.docPlan || 'API: criar plano (preapproval_plan)', url: 'https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval_plan/post' },
					{ label: t.docPre || 'API: criar assinatura (preapproval)', url: 'https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval/post' }
				];

				children.push( h( 'div', {
					staticClass: 'jfb-mp-plans__modal-overlay',
					on: { click: function ( e ) { if ( e.target === e.currentTarget ) { self.toggleHelp( false ); } } }
				}, [
					h( 'div', { staticClass: 'jfb-mp-plans__modal' }, [
						h( 'button', {
							attrs: { type: 'button', 'aria-label': 'Fechar' },
							staticClass: 'jfb-mp-plans__modal-close',
							on: { click: function () { self.toggleHelp( false ); } }
						}, '×' ),
						h( 'h2', { staticClass: 'jfb-mp-plans__page-title' }, t.helpTitle || 'Como funcionam os planos do Mercado Pago' ),
						h( 'div', { staticClass: 'jfb-mp-plans__modal-body' },
							( t.helpBody || [] ).map( function ( para ) { return h( 'p', para ); } )
						),
						h( 'h3', { staticClass: 'jfb-mp-plans__title' }, t.helpRefs || 'Referências oficiais (Mercado Pago)' ),
						h( 'ul', { staticClass: 'jfb-mp-plans__modal-links' }, docs.map( function ( d ) {
							return h( 'li', [ h( 'a', { attrs: { href: d.url, target: '_blank', rel: 'noopener noreferrer' } }, d.label ) ] );
						} ) )
					] )
				] ) );
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
				title: t.title || 'MercadoPago Settings',
				component: component,
				displayButton: false
			} );
			return tabs;
		}
	);
})();
