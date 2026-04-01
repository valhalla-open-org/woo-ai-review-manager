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
					card.querySelector( '.wairm-response-status' ).textContent = 'Approved';
					card.querySelector( '.wairm-response-status' ).className = 'wairm-response-status status-approved';
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
			if ( ! confirm( i18n.confirm_dismiss ) ) {
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
					card.style.opacity = '0.5';
					card.querySelector( '.wairm-response-status' ).textContent = 'Dismissed';
					card.querySelector( '.wairm-response-status' ).className = 'wairm-response-status status-dismissed';
					card.querySelector( '.wairm-response-actions' ).innerHTML = '';
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
			/* eslint-disable-next-line no-alert */
			if ( ! confirm( i18n.confirm_post ) ) {
				return;
			}
			var card = getCard( btn );
			var text = card.querySelector( '.wairm-response-text' ).value;

			if ( ! text.trim() ) {
				showNotice( card, 'Response text cannot be empty.', 'error' );
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
					card.querySelector( '.wairm-response-status' ).textContent = 'Posted';
					card.querySelector( '.wairm-response-status' ).className = 'wairm-response-status status-sent';
					card.querySelector( '.wairm-response-text' ).readOnly = true;
					card.querySelector( '.wairm-response-actions' ).innerHTML =
						'<span class="wairm-posted-label dashicons-before dashicons-yes-alt" style="color: #2ecc71;"> ' + i18n.posted + '</span>';
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
				btn.textContent = 'Regenerate';
				if ( data.success && data.data.suggestion ) {
					card.querySelector( '.wairm-response-text' ).value = data.data.suggestion;
					card.dataset.status = 'generated';
					card.querySelector( '.wairm-response-status' ).textContent = 'New';
					card.querySelector( '.wairm-response-status' ).className = 'wairm-response-status status-generated';
					showNotice( card, i18n.regenerated );
				} else {
					showNotice( card, ( data.data && data.data.message ) || i18n.error, 'error' );
				}
			} );
		} );
	} );
} );
