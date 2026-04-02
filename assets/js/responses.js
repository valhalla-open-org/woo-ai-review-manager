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
					// Replace action buttons with Undo Dismiss + Regenerate.
					var actions = card.querySelector( '.wairm-response-actions' );
					actions.querySelectorAll( '.wairm-action-post, .wairm-action-approve, .wairm-action-dismiss' ).forEach( function ( b ) { b.remove(); } );
					var cb = card.querySelector( '.wairm-bulk-check' );
					if ( cb ) { cb.remove(); }
					var existing = actions.querySelector( '.wairm-inline-notice' );
					if ( existing ) { existing.remove(); }
					// Add undo dismiss button.
					var undoBtn = document.createElement( 'button' );
					undoBtn.type = 'button';
					undoBtn.className = 'button wairm-action-undo-dismiss';
					undoBtn.textContent = i18n.undo_dismiss;
					actions.prepend( undoBtn );
					bindUndoDismiss( undoBtn );
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
					var cb = card.querySelector( '.wairm-bulk-check' );
					if ( cb ) { cb.remove(); }
					var actionsEl = card.querySelector( '.wairm-response-actions' );
					actionsEl.textContent = '';
					// Add Edit Reply button.
					var editBtn = document.createElement( 'button' );
					editBtn.type = 'button';
					editBtn.className = 'button wairm-action-edit-reply';
					editBtn.textContent = i18n.edit_reply;
					actionsEl.appendChild( editBtn );
					bindEditReply( editBtn );
					// Add posted label.
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

	// Undo Dismiss.
	function bindUndoDismiss( btn ) {
		btn.addEventListener( 'click', function () {
			var card = getCard( btn );
			disableCard( card );

			ajaxPost( {
				action: 'wairm_undo_dismiss',
				sentiment_id: card.dataset.id
			}, function ( data ) {
				if ( data.success ) {
					card.classList.remove( 'wairm-card-dismissed' );
					card.dataset.status = 'generated';
					card.querySelector( '.wairm-response-status' ).textContent = i18n.status_new;
					card.querySelector( '.wairm-response-status' ).className = 'wairm-badge-base wairm-response-status status-generated';
					card.querySelector( '.wairm-response-text' ).readOnly = false;
					// Rebuild action buttons.
					var actions = card.querySelector( '.wairm-response-actions' );
					btn.remove();
					var postBtn = document.createElement( 'button' );
					postBtn.type = 'button';
					postBtn.className = 'button button-primary wairm-action-post';
					postBtn.textContent = 'Post Reply';
					var approveBtn = document.createElement( 'button' );
					approveBtn.type = 'button';
					approveBtn.className = 'button wairm-action-approve';
					approveBtn.textContent = 'Approve';
					var dismissBtn = document.createElement( 'button' );
					dismissBtn.type = 'button';
					dismissBtn.className = 'button wairm-action-dismiss';
					dismissBtn.textContent = 'Dismiss';
					actions.prepend( dismissBtn );
					actions.prepend( approveBtn );
					actions.prepend( postBtn );
					// Reload to rebind all handlers cleanly.
					location.reload();
				} else {
					enableCard( card );
					showNotice( card, ( data.data && data.data.message ) || i18n.error, 'error' );
				}
			} );
		} );
	}
	document.querySelectorAll( '.wairm-action-undo-dismiss' ).forEach( bindUndoDismiss );

	// Edit Posted Reply.
	function bindEditReply( btn ) {
		btn.addEventListener( 'click', function () {
			var card = getCard( btn );
			var textarea = card.querySelector( '.wairm-response-text' );
			textarea.readOnly = false;
			textarea.focus();

			btn.textContent = i18n.edit_reply;
			btn.className = 'button button-primary wairm-action-save-edit';

			btn.removeEventListener( 'click', arguments.callee );
			btn.addEventListener( 'click', function () {
				var text = textarea.value;
				if ( ! text.trim() ) {
					showNotice( card, i18n.empty_response, 'error' );
					return;
				}
				disableCard( card );
				ajaxPost( {
					action: 'wairm_edit_posted_reply',
					sentiment_id: card.dataset.id,
					response_text: text
				}, function ( data ) {
					enableCard( card );
					if ( data.success ) {
						textarea.readOnly = true;
						btn.className = 'button wairm-action-edit-reply';
						btn.textContent = i18n.edit_reply;
						showNotice( card, i18n.reply_updated );
						// Re-bind for future edits.
						var newBtn = btn.cloneNode( true );
						btn.parentNode.replaceChild( newBtn, btn );
						bindEditReply( newBtn );
					} else {
						showNotice( card, ( data.data && data.data.message ) || i18n.error, 'error' );
					}
				} );
			} );
		} );
	}
	document.querySelectorAll( '.wairm-action-edit-reply' ).forEach( bindEditReply );

	// Bulk Actions.
	var bulkBar = document.getElementById( 'wairm-bulk-bar' );
	var selectAll = document.getElementById( 'wairm-select-all' );
	var checkboxes = document.querySelectorAll( '.wairm-card-checkbox' );

	if ( bulkBar && checkboxes.length > 0 ) {
		bulkBar.style.display = 'flex';

		function getSelectedIds() {
			var ids = [];
			document.querySelectorAll( '.wairm-card-checkbox:checked' ).forEach( function ( cb ) {
				ids.push( cb.value );
			} );
			return ids;
		}

		function updateBulkCount() {
			var count = document.querySelectorAll( '.wairm-card-checkbox:checked' ).length;
			var countEl = bulkBar.querySelector( '.wairm-bulk-count' );
			countEl.textContent = count > 0 ? '(' + count + ')' : '';
		}

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				checkboxes.forEach( function ( cb ) { cb.checked = selectAll.checked; } );
				updateBulkCount();
			} );
		}

		checkboxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', updateBulkCount );
		} );

		function doBulk( bulkAction, confirmMsg ) {
			var ids = getSelectedIds();
			if ( ids.length === 0 ) {
				/* eslint-disable-next-line no-alert */
				alert( i18n.no_selection );
				return;
			}
			/* eslint-disable-next-line no-alert */
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			var formData = {
				action: 'wairm_bulk_response',
				bulk_action: bulkAction,
				nonce: wairmResponses.nonce
			};

			// Build URLSearchParams with array of IDs.
			var params = new URLSearchParams( formData );
			ids.forEach( function ( id ) { params.append( 'ids[]', id ); } );

			fetch( wairmResponses.ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( data.success ) {
						location.reload();
					} else {
						/* eslint-disable-next-line no-alert */
						alert( ( data.data && data.data.message ) || i18n.error );
					}
				} )
				.catch( function () {
					/* eslint-disable-next-line no-alert */
					alert( i18n.error );
				} );
		}

		bulkBar.querySelector( '.wairm-bulk-approve' ).addEventListener( 'click', function () {
			doBulk( 'approved', i18n.bulk_confirm_approve );
		} );

		bulkBar.querySelector( '.wairm-bulk-post' ).addEventListener( 'click', function () {
			doBulk( 'post', i18n.bulk_confirm_post );
		} );

		bulkBar.querySelector( '.wairm-bulk-dismiss' ).addEventListener( 'click', function () {
			doBulk( 'dismissed', i18n.bulk_confirm_dismiss );
		} );
	}
} );
