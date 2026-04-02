/* global wairmInsights */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var container  = document.querySelector( '.wairm-insights-content' );
	if ( ! container ) {
		return;
	}

	var output      = document.getElementById( 'wairm-insight-output' );
	var generateBtn = document.getElementById( 'wairm-generate-insight' );
	var historySelect = document.getElementById( 'wairm-insight-history' );
	var category    = container.getAttribute( 'data-category' );
	var i18n        = wairmInsights.i18n;

	function showLoading() {
		output.innerHTML = '<div class="wairm-insight-loading">' +
			'<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>' +
			i18n.generating +
			'</div>';
		generateBtn.disabled = true;
	}

	function showError( message ) {
		output.innerHTML = '<div class="wairm-insight-error">' +
			'<span class="dashicons dashicons-warning" style="color: #d63638; margin-right: 6px;"></span>' +
			message +
			'</div>';
		generateBtn.disabled = false;
	}

	function showResult( data ) {
		output.innerHTML = '<div class="wairm-insight-body">' + data.html + '</div>';
		generateBtn.disabled = false;
	}

	function updateHistory( history ) {
		if ( ! historySelect ) {
			// Create the select element if it doesn't exist yet.
			historySelect = document.createElement( 'select' );
			historySelect.id = 'wairm-insight-history';
			historySelect.className = 'wairm-insight-history';
			generateBtn.parentNode.insertBefore( historySelect, generateBtn );

			historySelect.addEventListener( 'change', function () {
				loadInsight( this.value );
			} );
		}

		historySelect.innerHTML = '';
		history.forEach( function ( entry ) {
			var option = document.createElement( 'option' );
			option.value = entry.id;
			var date = new Date( entry.generated_at.replace( ' ', 'T' ) );
			var formatted = date.toLocaleDateString() + ' ' + date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
			option.textContent = formatted + ' (' + entry.review_count + ' ' + i18n.reviews + ')';
			historySelect.appendChild( option );
		} );
	}

	function generateInsight() {
		showLoading();

		fetch( wairmInsights.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( {
				action: 'wairm_generate_insight',
				nonce: wairmInsights.nonce,
				category: category
			} )
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					showResult( data.data );
					updateHistory( data.data.history );

					// Update button label after first generation.
					var label = historySelect && historySelect.options.length > 0 ? 'Generate New' : 'Generate';
					generateBtn.lastChild.textContent = ' ' + label;
				} else {
					showError( ( data.data && data.data.message ) || i18n.error );
				}
			} )
			.catch( function () {
				showError( i18n.error );
			} );
	}

	function loadInsight( insightId ) {
		showLoading();

		fetch( wairmInsights.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( {
				action: 'wairm_load_insight',
				nonce: wairmInsights.nonce,
				insight_id: insightId
			} )
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					showResult( data.data );
				} else {
					showError( ( data.data && data.data.message ) || i18n.error );
				}
			} )
			.catch( function () {
				showError( i18n.error );
			} );
	}

	// Generate button.
	generateBtn.addEventListener( 'click', function () {
		generateInsight();
	} );

	// History dropdown.
	if ( historySelect ) {
		historySelect.addEventListener( 'change', function () {
			loadInsight( this.value );
		} );
	}
} );
