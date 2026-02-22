/* global rentalGcal, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		const $btn    = $( '#rental-gcal-test-btn' );
		const $result = $( '#rental-gcal-test-result' );

		if ( ! $btn.length ) return;

		$btn.on( 'click', function () {
			$btn.prop( 'disabled', true );
			$result.text( rentalGcal.i18n.testing ).removeClass( 'success error' );

			$.post( rentalGcal.ajaxUrl, {
				action : 'rental_gcal_test_connection',
				nonce  : rentalGcal.testNonce,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						$result.text( response.data.message ).addClass( 'success' );
					} else {
						$result.text( response.data.message ).addClass( 'error' );
					}
				} )
				.fail( function () {
					$result.text( 'Request failed. Check browser console.' ).addClass( 'error' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	} );
} )( jQuery );
