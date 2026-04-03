/* global wairm */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	// Animate bar fills on load.
	var bars = document.querySelectorAll( '.wairm-bar-fill[data-width]' );
	requestAnimationFrame( function () {
		bars.forEach( function ( bar ) {
			bar.style.width = bar.getAttribute( 'data-width' ) + '%';
		} );
	} );

	// Sparkline tooltips.
	var sparklines = document.querySelectorAll( '.wairm-kpi-sparkline' );
	sparklines.forEach( function ( container ) {
		var svg = container.querySelector( 'svg' );
		if ( ! svg ) return;

		var tooltip = document.createElement( 'div' );
		tooltip.className = 'wairm-sparkline-tooltip';
		container.appendChild( tooltip );

		svg.addEventListener( 'mouseenter', function () {
			tooltip.classList.add( 'is-visible' );
		} );

		svg.addEventListener( 'mouseleave', function () {
			tooltip.classList.remove( 'is-visible' );
		} );

		svg.addEventListener( 'mousemove', function ( e ) {
			var rect = svg.getBoundingClientRect();
			var x = e.clientX - rect.left;
			var pct = x / rect.width;

			var card = container.closest( '.wairm-kpi-card' );
			var cardIndex = card ? Array.from( card.parentNode.children ).indexOf( card ) : 0;

			var series;
			if ( typeof wairm !== 'undefined' && wairm.sparkline_data ) {
				if ( cardIndex === 0 ) series = wairm.sparkline_data.reviews;
				else if ( cardIndex === 1 ) series = wairm.sparkline_data.scores;
				else if ( cardIndex === 2 ) series = wairm.sparkline_data.conversions;
			}

			if ( ! series || series.length === 0 ) return;

			var idx = Math.min( Math.round( pct * ( series.length - 1 ) ), series.length - 1 );
			var val = series[ idx ];

			var display;
			if ( cardIndex === 1 ) display = Number( val ).toFixed( 2 );
			else if ( cardIndex === 2 ) display = Number( val ).toFixed( 1 ) + '%';
			else display = String( val );

			tooltip.textContent = display;
			tooltip.style.left = ( pct * 100 ) + '%';
			tooltip.style.bottom = '100%';
		} );
	} );

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
