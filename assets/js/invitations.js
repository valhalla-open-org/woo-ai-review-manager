/**
 * AI Review Manager – Invitations Page
 *
 * Handles send-now and resend AJAX actions for review invitations.
 *
 * @package WooAIReviewManager
 */

/* global jQuery, wairmInvitations */

(function ( $ ) {
	'use strict';

	var i18n = wairmInvitations.i18n;

	/**
	 * Status label map matching PHP output.
	 */
	var statusLabels = {
		pending:  wairmInvitations.i18n.status_pending  || 'Pending',
		sent:     wairmInvitations.i18n.status_sent     || 'Sent',
		clicked:  wairmInvitations.i18n.status_clicked  || 'Clicked',
		reviewed: wairmInvitations.i18n.status_reviewed || 'Reviewed',
		expired:  wairmInvitations.i18n.status_expired  || 'Expired'
	};

	/**
	 * Show an inline notice in the row.
	 */
	function showRowNotice( $row, message, type ) {
		var $actions = $row.find( 'td:last-child' );
		$actions.find( '.wairm-inline-notice' ).remove();
		var $notice = $( '<span>', {
			'class': 'wairm-inline-notice notice-' + type,
			text: message
		} );
		$actions.append( $notice );

		if ( 'success' === type ) {
			setTimeout( function () {
				$actions.find( '.wairm-inline-notice' ).fadeOut( 300, function () {
					$( this ).remove();
				} );
			}, 3000 );
		}
	}

	/**
	 * Update the row UI after a successful send/resend.
	 */
	function updateRow( $row, newStatus ) {
		var $statusBadge = $row.find( '.wairm-invitation-status' );
		$statusBadge
			.removeClass( 'status-pending status-sent status-clicked status-reviewed status-expired' )
			.addClass( 'status-' + newStatus )
			.text( statusLabels[ newStatus ] || newStatus );

		var $actions = $row.find( 'td:last-child' );
		$actions.find( '.wairm-send-now, .wairm-resend' ).remove();

		if ( 'sent' === newStatus || 'clicked' === newStatus ) {
			var $btn = $( '<button>', {
				type: 'button',
				'class': 'button button-small wairm-resend',
				'data-id': $row.data( 'id' ),
				text: i18n.resend
			} );
			$actions.prepend( $btn );
		}
	}

	// Send Now.
	$( document ).on( 'click', '.wairm-send-now', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var id   = $btn.data( 'id' );

		if ( ! window.confirm( i18n.confirm_send ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.sending );

		$.post( wairmInvitations.ajax_url, {
			action:        'wairm_send_invitation',
			nonce:         wairmInvitations.nonce,
			invitation_id: id
		} )
		.done( function ( response ) {
			if ( response.success ) {
				updateRow( $row, response.data.status );
				showRowNotice( $row, i18n.sent, 'success' );
			} else {
				$btn.prop( 'disabled', false ).text( wairmInvitations.i18n.send_now || 'Send Now' );
				showRowNotice( $row, response.data.message || i18n.error, 'error' );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false ).text( wairmInvitations.i18n.send_now || 'Send Now' );
			showRowNotice( $row, i18n.error, 'error' );
		} );
	} );

	// Resend.
	$( document ).on( 'click', '.wairm-resend', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var id   = $btn.data( 'id' );

		if ( ! window.confirm( i18n.confirm_resend ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.sending );

		$.post( wairmInvitations.ajax_url, {
			action:        'wairm_resend_invitation',
			nonce:         wairmInvitations.nonce,
			invitation_id: id
		} )
		.done( function ( response ) {
			if ( response.success ) {
				updateRow( $row, response.data.status );
				showRowNotice( $row, i18n.sent, 'success' );
			} else {
				$btn.prop( 'disabled', false ).text( wairmInvitations.i18n.resend || 'Resend' );
				showRowNotice( $row, response.data.message || i18n.error, 'error' );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false ).text( wairmInvitations.i18n.resend || 'Resend' );
			showRowNotice( $row, i18n.error, 'error' );
		} );
	} );

	// Email Log Modal.
	var $modal = $( '#wairm-email-log-modal' );
	var $modalBody = $( '#wairm-email-log-body' );

	$( document ).on( 'click', '.wairm-view-email-log', function () {
		var id = $( this ).data( 'id' );
		$modalBody.html( '<p>' + ( i18n.loading || 'Loading...') + '</p>' );
		$modal.show();

		$.post( wairmInvitations.ajax_url, {
			action:        'wairm_email_log',
			nonce:         wairmInvitations.nonce,
			invitation_id: id
		} )
		.done( function ( response ) {
			if ( response.success && response.data.emails.length > 0 ) {
				var html = '<table class="widefat striped"><thead><tr>';
				html += '<th>Type</th><th>Status</th><th>Scheduled</th><th>Sent</th>';
				html += '</tr></thead><tbody>';
				response.data.emails.forEach( function ( email ) {
					html += '<tr>';
					html += '<td>' + $( '<span>' ).text( email.type ).html() + '</td>';
					html += '<td>' + $( '<span>' ).text( email.status ).html() + '</td>';
					html += '<td>' + $( '<span>' ).text( email.scheduled_at ).html() + '</td>';
					html += '<td>' + $( '<span>' ).text( email.sent_at ).html() + '</td>';
					html += '</tr>';
				} );
				html += '</tbody></table>';
				$modalBody.html( html );
			} else if ( response.success ) {
				$modalBody.html( '<p>' + ( i18n.no_emails || 'No emails found.') + '</p>' );
			} else {
				$modalBody.html( '<p class="wairm-inline-notice notice-error">' + ( response.data.message || i18n.error ) + '</p>' );
			}
		} )
		.fail( function () {
			$modalBody.html( '<p class="wairm-inline-notice notice-error">' + i18n.error + '</p>' );
		} );
	} );

	$modal.on( 'click', '.wairm-modal-close', function () {
		$modal.hide();
	} );

	$modal.on( 'click', function ( e ) {
		if ( $( e.target ).is( $modal ) ) {
			$modal.hide();
		}
	} );

})( jQuery );
