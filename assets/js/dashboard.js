/* global wairm, Chart */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	// Sentiment pie chart.
	var canvas = document.getElementById( 'wairm-sentiment-chart' );
	if ( canvas && typeof Chart !== 'undefined' && wairm.chart ) {
		new Chart( canvas.getContext( '2d' ), {
			type: 'pie',
			data: {
				labels: [ 'Positive', 'Neutral', 'Negative' ],
				datasets: [ {
					data: [
						wairm.chart.positive,
						wairm.chart.neutral,
						wairm.chart.negative
					],
					backgroundColor: [ '#2ecc71', '#f39c12', '#e74c3c' ]
				} ]
			},
			options: {
				responsive: true,
				plugins: {
					legend: { position: 'bottom' }
				}
			}
		} );
	}

	// View full AI suggestion.
	document.querySelectorAll( '.view-suggestion' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var suggestion = JSON.parse( this.dataset.suggestion );
			/* eslint-disable-next-line no-alert */
			alert( 'AI Response Suggestion:\n\n' + suggestion );
		} );
	} );

	// Analyze old reviews button.
	var analyzeBtn = document.getElementById( 'wairm-analyze-old-reviews' );
	if ( analyzeBtn ) {
		analyzeBtn.addEventListener( 'click', function () {
			/* eslint-disable-next-line no-alert */
			if ( ! confirm( wairm.i18n.confirm_analyze ) ) {
				return;
			}

			var btn = this;
			btn.disabled = true;
			btn.textContent = wairm.i18n.processing;

			fetch( wairm.ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action: 'wairm_analyze_old_reviews',
					nonce: wairm.nonce
				} )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					/* eslint-disable-next-line no-alert */
					alert( ( data.data && data.data.message ) || wairm.i18n.complete );
					if ( data.success ) {
						location.reload();
					}
				} )
				.catch( function () {
					/* eslint-disable-next-line no-alert */
					alert( wairm.i18n.request_failed );
				} )
				.finally( function () {
					btn.disabled = false;
					btn.textContent = wairm.i18n.analyze_button;
				} );
		} );
	}
} );
