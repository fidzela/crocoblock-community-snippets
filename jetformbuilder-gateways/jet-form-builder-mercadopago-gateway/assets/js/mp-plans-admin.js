/**
 * MP Planos — admin (criar / listar / excluir planos de assinatura via API).
 * Consome os endpoints REST jet-form-builder/v1 (fetch/create/delete plans).
 * Sem dependências (vanilla JS).
 */
(function () {
	'use strict';

	var CFG = window.JFB_MP_PLANS || { urls: {}, i18n: {} };

	function $( id ) {
		return document.getElementById( id );
	}

	function token() {
		var el = $( 'jfb-mp-token' );
		return el ? ( el.value || '' ).trim() : '';
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function notice( msg, type ) {
		var el = $( 'jfb-mp-notice' );
		if ( ! el ) {
			return;
		}
		el.textContent = msg || '';
		el.style.color = type === 'error' ? '#b32d2e' : ( type === 'success' ? '#1a7f37' : '#666' );
	}

	function api( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + r.status ) );
				}
				return data;
			} );
		} );
	}

	function amountLabel( plan ) {
		if ( plan.amount === null || plan.amount === undefined ) {
			return '—';
		}
		return ( plan.currency || 'BRL' ) + ' ' + Number( plan.amount ).toFixed( 2 );
	}

	function freqLabel( plan ) {
		if ( ! plan.frequency || ! plan.frequency_type ) {
			return '—';
		}
		var unit = plan.frequency_type === 'days' ? 'dia(s)' : 'mês(es)';
		return 'a cada ' + plan.frequency + ' ' + unit;
	}

	function renderRows( plans ) {
		var body = $( 'jfb-mp-plans-body' );
		if ( ! body ) {
			return;
		}
		body.innerHTML = '';

		if ( ! plans || ! plans.length ) {
			body.innerHTML = '<tr><td colspan="6">' + escapeHtml( CFG.i18n.empty ) + '</td></tr>';
			return;
		}

		plans.forEach( function ( plan ) {
			var status = plan.status || 'active';
			var color = status === 'active' ? '#1a7f37' : '#b32d2e';

			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td>' + escapeHtml( plan.reason || '' ) + '</td>' +
				'<td>' + escapeHtml( amountLabel( plan ) ) + '</td>' +
				'<td>' + escapeHtml( freqLabel( plan ) ) + '</td>' +
				'<td><span style="color:' + color + '">' + escapeHtml( status ) + '</span></td>' +
				'<td><code style="font-size:11px">' + escapeHtml( plan.id ) + '</code></td>' +
				'<td></td>';

			var actions = tr.lastChild;
			var del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'button button-link-delete';
			del.textContent = CFG.i18n.delete || 'Excluir';
			del.disabled = status !== 'active';
			del.addEventListener( 'click', function () {
				onDelete( plan.id );
			} );
			actions.appendChild( del );

			body.appendChild( tr );
		} );
	}

	function loadList() {
		if ( ! token() ) {
			notice( CFG.i18n.noToken, 'error' );
			renderRows( [] );
			return;
		}
		var body = $( 'jfb-mp-plans-body' );
		if ( body ) {
			body.innerHTML = '<tr><td colspan="6">' + escapeHtml( CFG.i18n.loading ) + '</td></tr>';
		}
		notice( '' );

		api( CFG.urls.list, { secret: token(), force_refresh: true } )
			.then( function ( res ) {
				renderRows( ( res && res.data ) || [] );
			} )
			.catch( function ( e ) {
				if ( body ) {
					body.innerHTML = '<tr><td colspan="6" style="color:#b32d2e">' + escapeHtml( e.message ) + '</td></tr>';
				}
			} );
	}

	function onCreate() {
		if ( ! token() ) {
			notice( CFG.i18n.noToken, 'error' );
			return;
		}
		var btn = $( 'jfb-mp-create' );
		if ( btn ) {
			btn.disabled = true;
		}
		notice( CFG.i18n.loading );

		api( CFG.urls.create, {
			secret: token(),
			reason: ( $( 'jfb-mp-reason' ) || {} ).value,
			amount: ( $( 'jfb-mp-amount' ) || {} ).value,
			frequency: ( $( 'jfb-mp-frequency' ) || {} ).value,
			frequency_type: ( $( 'jfb-mp-frequency-type' ) || {} ).value,
			currency: ( $( 'jfb-mp-currency' ) || {} ).value
		} )
			.then( function ( res ) {
				var id = res && res.plan ? res.plan.id : '';
				notice( ( CFG.i18n.created || 'Criado!' ) + ( id ? ' (ID: ' + id + ')' : '' ), 'success' );
				if ( $( 'jfb-mp-reason' ) ) { $( 'jfb-mp-reason' ).value = ''; }
				if ( $( 'jfb-mp-amount' ) ) { $( 'jfb-mp-amount' ).value = ''; }
				loadList();
			} )
			.catch( function ( e ) {
				notice( e.message, 'error' );
			} )
			.then( function () {
				if ( btn ) { btn.disabled = false; }
			} );
	}

	function onDelete( id ) {
		if ( ! window.confirm( CFG.i18n.confirmDelete ) ) {
			return;
		}
		notice( CFG.i18n.loading );
		api( CFG.urls.delete, { secret: token(), id: id } )
			.then( function () {
				notice( CFG.i18n.deleted, 'success' );
				loadList();
			} )
			.catch( function ( e ) {
				notice( e.message, 'error' );
			} );
	}

	function init() {
		if ( ! $( 'jfb-mp-plans-body' ) ) {
			return;
		}
		if ( $( 'jfb-mp-refresh' ) ) {
			$( 'jfb-mp-refresh' ).addEventListener( 'click', loadList );
		}
		if ( $( 'jfb-mp-create' ) ) {
			$( 'jfb-mp-create' ).addEventListener( 'click', onCreate );
		}
		if ( token() ) {
			loadList();
		} else {
			renderRows( [] );
			notice( CFG.i18n.noToken, 'error' );
		}
	}

	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
})();
