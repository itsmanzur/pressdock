/* Pressdock Connector admin: copy buttons. No dependencies. */
( function () {
	'use strict';

	function fallbackCopy( input ) {
		input.focus();
		input.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {
			/* user can still copy the selected text */
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-pdock-copy]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var input = document.getElementById( button.getAttribute( 'data-pdock-copy' ) );
		if ( ! input ) {
			return;
		}
		var done = function () {
			var toast = button.parentNode.querySelector( '.pdock-toast' );
			if ( toast ) {
				toast.classList.add( 'is-shown' );
				window.setTimeout( function () {
					toast.classList.remove( 'is-shown' );
				}, 1500 );
			}
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done, function () {
				fallbackCopy( input );
				done();
			} );
		} else {
			fallbackCopy( input );
			done();
		}
	} );
} )();
