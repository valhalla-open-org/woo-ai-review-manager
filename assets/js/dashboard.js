/* global wairm, Chart */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	// Sentiment pie chart.
	var canvas = document.getElementById( 'wairm-sentiment-chart' );
	if ( canvas && typeof Chart !== 'undefined' && wairm.chart ) {
		var chartData = wairm.chart;
		var hasData   = chartData.positive + chartData.neutral + chartData.negative > 0;

		if ( hasData ) {
			new Chart( canvas.getContext( '2d' ), {
				type: 'pie',
				data: {
					labels: [ 'Positive', 'Neutral', 'Negative' ],
					datasets: [ {
						data: [ chartData.positive, chartData.neutral, chartData.negative ],
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
		} else {
			canvas.parentNode.innerHTML = '<p style="color: #888; text-align: center;">No sentiment data yet. Analyze some reviews to see the chart.</p>';
		}
	}

	// Analyze old reviews with batch processing and progress bar.
	var analyzeBtn = document.getElementById( 'wairm-analyze-old-reviews' );
	if ( analyzeBtn ) {
		analyzeBtn.addEventListener( 'click', function () {
			var i18n        = wairm.i18n;
			var total       = parseInt( wairm.pending_count, 10 ) || 0;
			var processed   = 0;
			var progressWrap = document.getElementById( 'wairm-analyze-progress' );
			var progressBar  = document.getElementById( 'wairm-progress-bar' );
			var progressText = document.getElementById( 'wairm-progress-text' );

			if ( total <= 0 ) {
				/* eslint-disable-next-line no-alert */
				alert( i18n.nothing );
				return;
			}

			analyzeBtn.disabled = true;
			analyzeBtn.textContent = i18n.analyzing;
			progressWrap.style.display = 'block';
			progressBar.style.width = '0%';
			progressText.textContent = '';

			function updateProgress() {
				var pct = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
				progressBar.style.width = pct + '%';
				progressText.textContent = i18n.batch_progress
					.replace( '%1$d', processed )
					.replace( '%2$d', total );
			}

			function runBatch() {
				fetch( wairm.ajax_url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams( {
						action: 'wairm_analyze_batch',
						nonce: wairm.nonce
					} )
				} )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) {
						if ( ! data.success ) {
							/* eslint-disable-next-line no-alert */
							alert( ( data.data && data.data.message ) || i18n.error );
							resetButton();
							return;
						}

						var result = data.data;
						var batchProcessed = ( result.processed || 0 );
						processed += batchProcessed + ( result.failed || 0 );
						updateProgress();

						// Stop if nothing was actually analyzed (prevents infinite loop).
						if ( batchProcessed === 0 && result.remaining > 0 ) {
							/* eslint-disable-next-line no-alert */
							alert( i18n.error );
							resetButton();
							return;
						}

						if ( result.remaining > 0 ) {
							runBatch();
						} else {
							progressBar.style.width = '100%';
							progressText.textContent = i18n.complete;
							setTimeout( function () {
								location.reload();
							}, 1000 );
						}
					} )
					.catch( function () {
						/* eslint-disable-next-line no-alert */
						alert( i18n.error );
						resetButton();
					} );
			}

			function resetButton() {
				analyzeBtn.disabled = false;
				analyzeBtn.textContent = i18n.analyze_button;
			}

			updateProgress();
			runBatch();
		} );
	}
} );
