/* global wairmSettings */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var i18n = wairmSettings.i18n;

	// Email preview iframe.
	var previewFrame = document.getElementById( 'wairm-email-preview-frame' );
	if ( previewFrame && wairmSettings.preview_url ) {
		previewFrame.src = wairmSettings.preview_url;
	}

	// Test email.
	var testBtn = document.getElementById( 'wairm-send-test-email' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var emailInput = document.getElementById( 'wairm-test-email-address' );
			var resultEl   = document.getElementById( 'wairm-test-email-result' );
			var email      = emailInput ? emailInput.value : '';

			testBtn.disabled = true;
			testBtn.textContent = i18n.sending;
			resultEl.textContent = '';
			resultEl.className = '';

			fetch( wairmSettings.ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action: 'wairm_send_test_email',
					nonce: wairmSettings.nonce,
					email: email
				} )
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					testBtn.disabled = false;
					testBtn.textContent = i18n.send_test;
					if ( data.success ) {
						resultEl.textContent = i18n.sent;
						resultEl.className = 'wairm-test-success';
					} else {
						resultEl.textContent = ( data.data && data.data.message ) || i18n.error;
						resultEl.className = 'wairm-test-error';
					}
				} )
				.catch( function () {
					testBtn.disabled = false;
					testBtn.textContent = i18n.send_test;
					resultEl.textContent = i18n.error;
					resultEl.className = 'wairm-test-error';
				} );
		} );
	}

	// Delete all data.
	var deleteBtn = document.getElementById( 'wairm-delete-all-data' );
	if ( deleteBtn ) {
		deleteBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirm_delete ) ) {
				return;
			}

			var resultEl = document.getElementById( 'wairm-delete-result' );
			deleteBtn.disabled = true;
			deleteBtn.textContent = i18n.deleting;
			resultEl.textContent = '';
			resultEl.className = '';

			fetch( wairmSettings.ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action: 'wairm_delete_all_data',
					nonce: wairmSettings.nonce
				} )
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( data.success ) {
						resultEl.textContent = i18n.deleted;
						resultEl.className = 'wairm-test-success';
						setTimeout( function () {
							location.reload();
						}, 1500 );
					} else {
						deleteBtn.disabled = false;
						deleteBtn.textContent = i18n.confirm_delete;
						resultEl.textContent = ( data.data && data.data.message ) || i18n.delete_error;
						resultEl.className = 'wairm-test-error';
					}
				} )
				.catch( function () {
					deleteBtn.disabled = false;
					deleteBtn.textContent = i18n.confirm_delete;
					resultEl.textContent = i18n.delete_error;
					resultEl.className = 'wairm-test-error';
				} );
		} );
	}
} );
