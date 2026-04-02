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

	/* -----------------------------------------------------------
	   Helpers
	   ----------------------------------------------------------- */
	function esc( str ) {
		var el = document.createElement( 'span' );
		el.textContent = str || '';
		return el.innerHTML;
	}

	function ratingClass( rating ) {
		if ( rating === 'positive' || rating === 'improving' ) return 'positive';
		if ( rating === 'negative' || rating === 'declining' ) return 'negative';
		if ( rating === 'mixed' || rating === 'stable' ) return 'mixed';
		return 'nodata';
	}

	function ratingLabel( rating ) {
		return i18n[ rating ] || rating || i18n.no_data;
	}

	function badge( rating ) {
		return '<span class="wairm-rating-badge wairm-rating-' + ratingClass( rating ) + '">' + esc( ratingLabel( rating ) ) + '</span>';
	}

	function listItems( arr ) {
		if ( ! arr || ! arr.length ) return '<li class="wairm-empty-item">' + esc( i18n.no_data ) + '</li>';
		return arr.map( function ( item ) {
			return '<li>' + esc( item ) + '</li>';
		} ).join( '' );
	}

	function card( cls, heading, content ) {
		return '<div class="wairm-card ' + cls + '">' +
			( heading ? '<div class="wairm-card-header">' + heading + '</div>' : '' ) +
			'<div class="wairm-card-body">' + content + '</div>' +
			'</div>';
	}

	/* -----------------------------------------------------------
	   Category Renderers
	   ----------------------------------------------------------- */
	function renderProduct( data ) {
		var html = '';

		if ( data.products && data.products.length ) {
			html += '<div class="wairm-card-grid">';
			data.products.forEach( function ( p ) {
				var inner = '<div class="wairm-product-meta">' +
					badge( p.quality_score ) +
					'<span class="wairm-review-count">' + esc( p.review_count + ' ' + i18n.reviews ) + '</span>' +
					'</div>';

				if ( p.strengths && p.strengths.length ) {
					inner += '<div class="wairm-card-section wairm-strengths">' +
						'<h4>' + esc( i18n.strengths ) + '</h4>' +
						'<ul>' + listItems( p.strengths ) + '</ul></div>';
				}

				if ( p.complaints && p.complaints.length ) {
					inner += '<div class="wairm-card-section wairm-complaints">' +
						'<h4>' + esc( i18n.complaints ) + '</h4>' +
						'<ul>' + listItems( p.complaints ) + '</ul></div>';
				}

				if ( p.sizing && p.sizing !== 'Insufficient data' ) {
					inner += '<div class="wairm-card-section"><h4>' + esc( i18n.sizing ) + '</h4><p>' + esc( p.sizing ) + '</p></div>';
				}

				if ( p.priority_action && p.priority_action !== 'Insufficient data' ) {
					inner += '<div class="wairm-card-action"><span class="dashicons dashicons-flag"></span> ' + esc( p.priority_action ) + '</div>';
				}

				html += card( 'wairm-product-card', '<h3>' + esc( p.name ) + '</h3>', inner );
			} );
			html += '</div>';
		}

		if ( data.summary ) {
			html += '<div class="wairm-summary-bar">' + esc( data.summary ) + '</div>';
		}

		return html;
	}

	function renderTrends( data ) {
		var html = '';

		// Overall direction.
		html += '<div class="wairm-trend-overview">' +
			'<div class="wairm-trend-direction wairm-trend-' + ratingClass( data.overall_direction ) + '">' +
			'<span class="wairm-trend-arrow"></span>' +
			'<span class="wairm-trend-label">' + esc( ratingLabel( data.overall_direction ) ) + '</span>' +
			'</div>' +
			'<p class="wairm-trend-summary">' + esc( data.overall_summary ) + '</p>' +
			'</div>';

		// Emerging issues.
		if ( data.emerging_issues && data.emerging_issues.length ) {
			var issuesHtml = '<div class="wairm-issues-list">';
			data.emerging_issues.forEach( function ( issue ) {
				issuesHtml += '<div class="wairm-issue-item">' +
					'<strong>' + esc( issue.issue ) + '</strong>' +
					( issue.product ? '<span class="wairm-issue-product">' + esc( issue.product ) + '</span>' : '' ) +
					'<p>' + esc( issue.detail ) + '</p>' +
					'</div>';
			} );
			issuesHtml += '</div>';
			html += card( 'wairm-issues-card', '<h3>' + esc( i18n.emerging_issues ) + '</h3>', issuesHtml );
		}

		// Product shifts.
		if ( data.product_shifts && data.product_shifts.length ) {
			var shiftsHtml = '<div class="wairm-shifts-list">';
			data.product_shifts.forEach( function ( shift ) {
				shiftsHtml += '<div class="wairm-shift-item">' +
					'<span class="wairm-shift-product">' + esc( shift.product ) + '</span>' +
					badge( shift.direction ) +
					'<p>' + esc( shift.detail ) + '</p>' +
					'</div>';
			} );
			shiftsHtml += '</div>';
			html += card( 'wairm-shifts-card', '<h3>' + esc( i18n.product_shifts ) + '</h3>', shiftsHtml );
		}

		// Patterns.
		if ( data.patterns && data.patterns.length ) {
			html += card( 'wairm-patterns-card', '<h3>' + esc( i18n.patterns ) + '</h3>',
				'<ul>' + listItems( data.patterns ) + '</ul>'
			);
		}

		return html;
	}

	function renderOperational( data ) {
		var sections = [
			{ key: 'shipping', label: i18n.shipping },
			{ key: 'expectations', label: i18n.expectations },
			{ key: 'price_value', label: i18n.price_value },
			{ key: 'support', label: i18n.support },
		];

		var html = '<div class="wairm-card-grid wairm-ops-grid">';
		sections.forEach( function ( section ) {
			var d = data[ section.key ];
			if ( ! d ) return;

			var inner = '<div class="wairm-ops-rating">' + badge( d.rating ) + '</div>';
			if ( d.findings && d.findings.length ) {
				inner += '<ul>' + listItems( d.findings ) + '</ul>';
			}

			html += card( 'wairm-ops-card', '<h3>' + esc( section.label ) + '</h3>', inner );
		} );
		html += '</div>';

		if ( data.priority_actions && data.priority_actions.length ) {
			html += card( 'wairm-priority-card', '<h3>' + esc( i18n.priority_actions ) + '</h3>',
				'<ol class="wairm-priority-list">' + data.priority_actions.map( function ( a ) {
					return '<li>' + esc( a ) + '</li>';
				} ).join( '' ) + '</ol>'
			);
		}

		return html;
	}

	function renderStrategic( data ) {
		var html = '';

		// Feature requests.
		if ( data.feature_requests && data.feature_requests.length ) {
			var frHtml = '<div class="wairm-feature-list">';
			data.feature_requests.forEach( function ( fr ) {
				frHtml += '<div class="wairm-feature-item">' +
					'<div class="wairm-feature-header">' +
					'<strong>' + esc( fr.request ) + '</strong>' +
					'<span class="wairm-mention-count">' + fr.mentions + ' ' + esc( i18n.mentions ) + '</span>' +
					'</div>' +
					( fr.products && fr.products.length ?
						'<div class="wairm-feature-products">' + fr.products.map( function ( p ) {
							return '<span class="wairm-tag">' + esc( p ) + '</span>';
						} ).join( '' ) + '</div>' : '' ) +
					'</div>';
			} );
			frHtml += '</div>';
			html += card( 'wairm-feature-card', '<h3>' + esc( i18n.feature_requests ) + '</h3>', frHtml );
		}

		// Competitive mentions.
		if ( data.competitive && data.competitive.length ) {
			var compHtml = '<div class="wairm-competitive-list">';
			data.competitive.forEach( function ( c ) {
				compHtml += '<div class="wairm-competitive-item">' +
					'<strong>' + esc( c.brand ) + '</strong>' +
					'<p>' + esc( c.context ) + '</p>' +
					'</div>';
			} );
			compHtml += '</div>';
			html += card( 'wairm-competitive-card', '<h3>' + esc( i18n.competitive ) + '</h3>', compHtml );
		}

		// Two side-by-side cards.
		var sideCards = '';
		if ( data.repeat_signals && data.repeat_signals.length ) {
			sideCards += card( 'wairm-repeat-card', '<h3>' + esc( i18n.repeat_signals ) + '</h3>',
				'<ul>' + listItems( data.repeat_signals ) + '</ul>'
			);
		}
		if ( data.marketing_quotes && data.marketing_quotes.length ) {
			sideCards += card( 'wairm-quotes-card', '<h3>' + esc( i18n.marketing_quotes ) + '</h3>',
				'<div class="wairm-quotes">' + data.marketing_quotes.map( function ( q ) {
					return '<blockquote>' + esc( q ) + '</blockquote>';
				} ).join( '' ) + '</div>'
			);
		}
		if ( sideCards ) {
			html += '<div class="wairm-card-grid wairm-two-col">' + sideCards + '</div>';
		}

		if ( data.summary ) {
			html += '<div class="wairm-summary-bar">' + esc( data.summary ) + '</div>';
		}

		return html;
	}

	/* -----------------------------------------------------------
	   Main Render
	   ----------------------------------------------------------- */
	var renderers = {
		product: renderProduct,
		trends: renderTrends,
		operational: renderOperational,
		strategic: renderStrategic,
	};

	function renderInsight( cat, data ) {
		var renderer = renderers[ cat ];
		if ( ! renderer || ! data ) return '';
		return '<div class="wairm-insight-cards">' + renderer( data ) + '</div>';
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

	function showResult( cat, responseData ) {
		// Support both new JSON data and legacy HTML.
		if ( responseData.data ) {
			output.innerHTML = renderInsight( cat, responseData.data );
		} else if ( responseData.html ) {
			output.innerHTML = '<div class="wairm-insight-body">' + responseData.html + '</div>';
		}
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
					showResult( data.data.category || category, data.data );
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
					showResult( data.data.category || category, data.data );
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

	// Render initial data if available (from page load).
	if ( wairmInsights.initial_data ) {
		output.innerHTML = renderInsight( category, wairmInsights.initial_data );
	}
} );
