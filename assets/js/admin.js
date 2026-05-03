/**
 * WP PureSMTP – Admin JavaScript
 *
 * Responsibilities:
 *  - Port auto-fill when encryption radio changes
 *  - Show/hide auth rows based on Authentication toggle
 *  - Test Email AJAX
 *  - Select-all checkbox in tables
 *  - Confirm dialogs for destructive actions
 */
( function ( $ ) {
	'use strict';

	/* ── Port auto-fill ──────────────────────────────────────────────────── */
	var portMap = { none: 25, ssl: 465, tls: 587 };

	$( 'input[name="puresmtp_encryption"]' ).on( 'change', function () {
		var enc  = $( this ).val();
		var port = portMap[ enc ];
		if ( port ) {
			$( '#puresmtp_port' ).val( port );
		}
	} );

	/* ── Auth rows show/hide ─────────────────────────────────────────────── */
	function toggleAuthRows() {
		var checked = $( '#puresmtp_auth' ).is( ':checked' );
		$( '.puresmtp-auth-row' ).toggle( checked );
	}

	$( '#puresmtp_auth' ).on( 'change', toggleAuthRows );
	toggleAuthRows(); // Apply on load.

	/* ── Select-all checkbox ─────────────────────────────────────────────── */
	$( document ).on( 'change', '.js-check-all', function () {
		var checked = $( this ).is( ':checked' );
		$( this ).closest( 'table' ).find( 'input[type="checkbox"]' ).prop( 'checked', checked );
	} );

	/* ── Confirm dialogs ─────────────────────────────────────────────────── */
	$( document ).on( 'click', '.js-confirm-delete-log', function ( e ) {
		if ( ! window.confirm( pureSMTP.confirmDeleteLog ) ) {
			e.preventDefault();
		}
	} );

	$( document ).on( 'click submit', '.js-confirm-clear-log', function ( e ) {
		if ( ! window.confirm( pureSMTP.confirmClearLog ) ) {
			e.preventDefault();
			e.stopPropagation();
		}
	} );

	$( document ).on( 'click', '.js-confirm-delete-queue', function ( e ) {
		if ( ! window.confirm( pureSMTP.confirmDeleteQ ) ) {
			e.preventDefault();
		}
	} );

	$( document ).on( 'click submit', '.js-confirm-clear-queue', function ( e ) {
		if ( ! window.confirm( pureSMTP.confirmClearQ ) ) {
			e.preventDefault();
			e.stopPropagation();
		}
	} );

	/* ── Test Email AJAX ─────────────────────────────────────────────────── */
	$( '#puresmtp-send-test' ).on( 'click', function ( e ) {
		e.preventDefault();

		var $btn    = $( this );
		var $result = $( '#puresmtp-test-result' );
		var to      = $( '#puresmtp_test_to' ).val().trim();
		var subject = $( '#puresmtp_test_subject' ).val().trim();
		var message = $( '#puresmtp_test_message' ).val().trim();

		if ( ! to ) {
			$result.show().removeClass( 'success error' ).addClass( 'error' )
				.text( 'Please enter a recipient email address.' );
			return;
		}

		$btn.prop( 'disabled', true ).text( pureSMTP.sending );
		$result.hide().removeClass( 'success error' );

		$.post( pureSMTP.ajaxurl, {
			action : 'puresmtp_test_mail',
			nonce  : pureSMTP.nonce,
			to     : to,
			subject: subject,
			message: message
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$result.show().addClass( 'success' ).text( response.data );
			} else {
				$result.show().addClass( 'error' ).text( response.data );
			}
		} )
		.fail( function () {
			$result.show().addClass( 'error' ).text( 'Request failed. Please try again.' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Send Test Email' );
		} );
	} );

	/* ── Dismiss "saved" notice after 3 s ───────────────────────────────── */
	setTimeout( function () {
		$( '.notice-success.is-dismissible' ).fadeOut( 400 );
	}, 3000 );

} )( jQuery );
