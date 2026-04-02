/* global wairmSettings */
document.addEventListener( 'DOMContentLoaded', function () {
	'use strict';

	var btn = document.getElementById( 'wairm-send-test-email' );
	if ( ! btn ) {
		return;
	}

	var i18n = wairmSettings.i18n;

	btn.addEventListener( 'click', function () {
		var emailInput = document.getElementById( 'wairm-test-email-address' );
		var resultEl   = document.getElementById( 'wairm-test-email-result' );
		var email      = emailInput ? emailInput.value : '';

		btn.disabled = true;
		btn.textContent = i18n.sending;
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
				btn.disabled = false;
				btn.textContent = i18n.send_test;
				if ( data.success ) {
					resultEl.textContent = i18n.sent;
					resultEl.className = 'wairm-test-success';
				} else {
					resultEl.textContent = ( data.data && data.data.message ) || i18n.error;
					resultEl.className = 'wairm-test-error';
				}
			} )
			.catch( function () {
				btn.disabled = false;
				btn.textContent = i18n.send_test;
				resultEl.textContent = i18n.error;
				resultEl.className = 'wairm-test-error';
			} );
	} );
} );
