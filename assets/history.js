/* Offair outage history: asks before clearing the history. */
( function () {
	'use strict';

	document.querySelectorAll( '.offair-confirm' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
