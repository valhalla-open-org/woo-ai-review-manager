/* global wairmInsights */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var container = document.querySelector( '.wairm-insights-content' );
	if ( ! container ) {
		return;
	}

	var output     = document.getElementById( 'wairm-insight-output' );
	var meta       = document.getElementById( 'wairm-insight-meta' );
	var refreshBtn = document.getElementById( 'wairm-refresh-insight' );
	var category   = container.getAttribute( 'data-category' );
	var i18n       = wairmInsights.i18n;

	function showLoading() {
		output.innerHTML = '<div class="wairm-insight-loading">' +
			'<span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>' +
			i18n.generating +
			'</div>';
		meta.textContent = '';
		refreshBtn.disabled = true;
	}

	function showError( message ) {
		output.innerHTML = '<div class="wairm-insight-error">' +
			'<span class="dashicons dashicons-warning" style="color: #d63638; margin-right: 6px;"></span>' +
			message +
			'</div>';
		refreshBtn.disabled = false;
	}

	function showResult( data ) {
		output.innerHTML = '<div class="wairm-insight-body">' + data.html + '</div>';

		if ( data.generated ) {
			meta.textContent = i18n.last_updated + ' ' + data.generated +
				' (' + data.review_count + ' reviews)';
		}

		refreshBtn.disabled = false;
	}

	function loadInsight( forceRefresh ) {
		showLoading();

		var body = new URLSearchParams( {
			action: 'wairm_generate_insight',
			nonce: wairmInsights.nonce,
			category: category
		} );

		if ( forceRefresh ) {
			body.append( 'refresh', '1' );
		}

		fetch( wairmInsights.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
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

	// Load on page load (uses cache if available).
	loadInsight( false );

	// Refresh button.
	refreshBtn.addEventListener( 'click', function () {
		loadInsight( true );
	} );
} );
