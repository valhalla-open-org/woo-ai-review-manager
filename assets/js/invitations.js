/**
 * WooAI Review Manager – Invitations Page
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
		var $actions = $row.find( 'td:last' );
		$actions.find( '.wairm-inline-notice' ).remove();
		$actions.append(
			'<span class="wairm-inline-notice notice-' + type + '" style="display:inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 3px; font-size: 12px;">' +
			message +
			'</span>'
		);

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

		var $actions = $row.find( 'td:last' );
		$actions.find( '.wairm-send-now, .wairm-resend' ).remove();

		if ( 'sent' === newStatus || 'clicked' === newStatus ) {
			$actions.prepend(
				'<button type="button" class="button button-small wairm-resend" data-id="' + $row.data( 'id' ) + '">' +
				( wairmInvitations.i18n.resend || 'Resend' ) +
				'</button>'
			);
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

})( jQuery );
