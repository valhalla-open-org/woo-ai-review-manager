/* global wairmInsights */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var container = document.querySelector( '.wairm-insights-content' );
	if ( ! container ) {
		return;
	}

	var output        = document.getElementById( 'wairm-insight-output' );
	var generateBtn   = document.getElementById( 'wairm-generate-insight' );
	var historySelect = document.getElementById( 'wairm-insight-history' );
	var periodSelect  = document.getElementById( 'wairm-insight-period' );
	var category      = container.getAttribute( 'data-category' );
	var i18n          = wairmInsights.i18n;

	var periodLabels = {};
	if ( periodSelect ) {
		Array.prototype.forEach.call( periodSelect.options, function ( opt ) {
			periodLabels[ opt.value ] = opt.textContent.trim();
		} );
	}

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

	function ajaxPost( params ) {
		return fetch( wairmInsights.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( Object.assign( { nonce: wairmInsights.nonce }, params ) )
		} ).then( function ( res ) { return res.json(); } );
	}

	function formatDate( mysqlDate ) {
		var date = new Date( mysqlDate.replace( ' ', 'T' ) );
		return date.toLocaleDateString() + ' ' + date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	function formatHistoryLabel( entry ) {
		var period = periodLabels[ entry.period ] || entry.period || 'All time';
		return formatDate( entry.generated_at ) + ' \u2014 ' + period + ' (' + entry.review_count + ' ' + i18n.reviews + ')';
	}

	function updateHistory( history ) {
		if ( ! historySelect ) {
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
			option.textContent = formatHistoryLabel( entry );
			historySelect.appendChild( option );
		} );

		generateBtn.lastChild.textContent = ' Generate New';
	}

	function loadInsight( insightId ) {
		showLoading();

		ajaxPost( { action: 'wairm_load_insight', insight_id: insightId } )
			.then( function ( data ) {
				if ( data.success ) {
					showResult( data.data );
					// Update period selector to match the loaded insight.
					if ( periodSelect && data.data.period ) {
						periodSelect.value = data.data.period;
					}
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
		var period = periodSelect ? periodSelect.value : '90';
		showLoading();

		ajaxPost( { action: 'wairm_generate_insight', category: category, period: period } )
			.then( function ( data ) {
				if ( data.success ) {
					showResult( data.data );
					updateHistory( data.data.history );
				} else {
					showError( ( data.data && data.data.message ) || i18n.error );
				}
			} )
			.catch( function () {
				showError( i18n.error );
			} );
	} );

	// History dropdown.
	if ( historySelect ) {
		historySelect.addEventListener( 'change', function () {
			loadInsight( this.value );
		} );
	}
} );
