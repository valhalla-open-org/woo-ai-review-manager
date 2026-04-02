/* global wairmResponses */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var i18n = wairmResponses.i18n;

	function ajaxPost( data, callback ) {
		data.nonce = wairmResponses.nonce;

		fetch( wairmResponses.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( data )
		} )
			.then( function ( res ) { return res.json(); } )
			.then( callback )
			.catch( function () {
				/* eslint-disable-next-line no-alert */
				alert( i18n.error );
			} );
	}

	function getCard( btn ) {
		return btn.closest( '.wairm-response-card' );
	}

	function disableCard( card ) {
		card.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
	}

	function enableCard( card ) {
		card.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } );
	}

	function showNotice( card, message, type ) {
		var existing = card.querySelector( '.wairm-inline-notice' );
		if ( existing ) {
			existing.remove();
		}
		var notice = document.createElement( 'div' );
		notice.className = 'wairm-inline-notice notice-' + ( type || 'success' );
		notice.textContent = message;
		card.querySelector( '.wairm-response-actions' ).prepend( notice );
		setTimeout( function () { notice.remove(); }, 4000 );
	}

	// Approve.
	document.querySelectorAll( '.wairm-action-approve' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var card = getCard( btn );
			var text = card.querySelector( '.wairm-response-text' ).value;
			disableCard( card );

			ajaxPost( {
				action: 'wairm_update_response',
				sentiment_id: card.dataset.id,
				response_action: 'approved',
				response_text: text
			}, function ( data ) {
				enableCard( card );
				if ( data.success ) {
					card.dataset.status = 'approved';
					card.querySelector( '.wairm-response-status' ).textContent = i18n.status_approved;
					card.querySelector( '.wairm-response-status' ).className = 'wairm-badge-base wairm-response-status status-approved';
					showNotice( card, i18n.updated );
				} else {
					showNotice( card, i18n.error, 'error' );
				}
			} );
		} );
	} );

	// Dismiss.
	document.querySelectorAll( '.wairm-action-dismiss' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			/* eslint-disable-next-line no-alert */
			if ( ! window.confirm( i18n.confirm_dismiss ) ) {
				return;
			}
			var card = getCard( btn );
			disableCard( card );

			ajaxPost( {
				action: 'wairm_update_response',
				sentiment_id: card.dataset.id,
				response_action: 'dismissed'
			}, function ( data ) {
				if ( data.success ) {
					card.classList.add( 'wairm-card-dismissed' );
					card.dataset.status = 'dismissed';
					card.querySelector( '.wairm-response-status' ).textContent = i18n.status_dismissed;
					card.querySelector( '.wairm-response-status' ).className = 'wairm-badge-base wairm-response-status status-dismissed';
					// Remove Post/Approve/Dismiss buttons but keep Regenerate.
					var actions = card.querySelector( '.wairm-response-actions' );
					actions.querySelectorAll( '.wairm-action-post, .wairm-action-approve, .wairm-action-dismiss' ).forEach( function ( b ) { b.remove(); } );
					var existing = actions.querySelector( '.wairm-inline-notice' );
					if ( existing ) { existing.remove(); }
				} else {
					enableCard( card );
					showNotice( card, i18n.error, 'error' );
				}
			} );
		} );
	} );

	// Post Reply.
	document.querySelectorAll( '.wairm-action-post' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var card = getCard( btn );
			var text = card.querySelector( '.wairm-response-text' ).value;

			if ( ! text.trim() ) {
				showNotice( card, i18n.empty_response, 'error' );
				return;
			}

			disableCard( card );

			ajaxPost( {
				action: 'wairm_post_response',
				sentiment_id: card.dataset.id,
				response_text: text
			}, function ( data ) {
				if ( data.success ) {
					card.dataset.status = 'sent';
					card.querySelector( '.wairm-response-status' ).textContent = i18n.status_posted;
					card.querySelector( '.wairm-response-status' ).className = 'wairm-badge-base wairm-response-status status-sent';
					card.querySelector( '.wairm-response-text' ).readOnly = true;
					var actionsEl = card.querySelector( '.wairm-response-actions' );
					actionsEl.textContent = '';
					var postedLabel = document.createElement( 'span' );
					postedLabel.className = 'wairm-posted-label dashicons-before dashicons-yes-alt';
					postedLabel.textContent = ' ' + i18n.posted;
					actionsEl.appendChild( postedLabel );
				} else {
					enableCard( card );
					showNotice( card, i18n.error, 'error' );
				}
			} );
		} );
	} );

	// Regenerate.
	document.querySelectorAll( '.wairm-action-regenerate' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var card = getCard( btn );
			disableCard( card );
			btn.textContent = i18n.regenerating;

			ajaxPost( {
				action: 'wairm_regenerate_response',
				sentiment_id: card.dataset.id
			}, function ( data ) {
				enableCard( card );
				btn.textContent = i18n.regenerate;
				if ( data.success && data.data.suggestion ) {
					card.querySelector( '.wairm-response-text' ).value = data.data.suggestion;
					card.dataset.status = 'generated';
					card.querySelector( '.wairm-response-status' ).textContent = i18n.status_new;
					card.querySelector( '.wairm-response-status' ).className = 'wairm-badge-base wairm-response-status status-generated';
					showNotice( card, i18n.regenerated );
				} else {
					showNotice( card, ( data.data && data.data.message ) || i18n.error, 'error' );
				}
			} );
		} );
	} );
} );
