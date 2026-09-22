/* global jQuery, wp, offairAdmin */
( function ( $ ) {
	'use strict';

	$( function () {
		var $tabs = $( '.offair-tabs .nav-tab' );
		var $panels = $( '.offair-panel' );
		var $save = $( '.offair-save' );
		var $currentTab = $( '#offair-current-tab' );

		function activate( id ) {
			id = ( id || '' ).replace( /[^a-z0-9-]/gi, '' );

			if ( ! id || ! $panels.filter( '#' + id ).length ) {
				id = $panels.first().attr( 'id' );
			}

			$tabs.removeClass( 'nav-tab-active' ).filter( '[href="#' + id + '"]' ).addClass( 'nav-tab-active' );
			$panels.hide().filter( '#' + id ).show();
			$save.toggle( -1 === [ 'offair-tab-history', 'offair-tab-advanced' ].indexOf( id ) );
			$currentTab.val( id.replace( 'offair-tab-', '' ) );

			// The preview iframes load only when their tab is opened.
			$( '#' + id + ' iframe[data-src]' ).each( function () {
				if ( ! this.getAttribute( 'src' ) ) {
					this.setAttribute( 'src', this.getAttribute( 'data-src' ) );
				}
			} );
		}

		$tabs.on( 'click', function ( event ) {
			var id = this.getAttribute( 'href' ).substring( 1 );

			event.preventDefault();
			activate( id );

			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + id );
			}
		} );

		activate( ( window.location.hash || '' ).substring( 1 ) );

		if ( $.fn.wpColorPicker ) {
			$( '.offair-color' ).wpColorPicker();
		}

		// Each logo field has its own media frame, preview and warning.
		$( '.offair-logo-field' ).each( function () {
			var $field = $( this );
			var $cell = $field.closest( 'td' );
			var frame;

			$field.find( '.offair-logo-choose' ).on( 'click', function ( event ) {
				event.preventDefault();

				if ( ! window.wp || ! wp.media ) {
					return;
				}

				if ( ! frame ) {
					frame = wp.media( {
						title: offairAdmin.chooseLogo,
						button: { text: offairAdmin.useLogo },
						library: { type: 'image' },
						multiple: false
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

						$field.find( '.offair-logo-id' ).val( attachment.id );
						$field.find( '.offair-logo-preview' ).attr( 'src', url ).prop( 'hidden', false );
						$field.find( '.offair-logo-remove' ).prop( 'hidden', false );
						$cell.find( '.offair-logo-warning' ).prop( 'hidden', true );
					} );
				}

				frame.open();
			} );

			$field.find( '.offair-logo-remove' ).on( 'click', function ( event ) {
				event.preventDefault();
				$field.find( '.offair-logo-id' ).val( '0' );
				$field.find( '.offair-logo-preview' ).attr( 'src', '' ).prop( 'hidden', true );
				$( this ).prop( 'hidden', true );
				$cell.find( '.offair-logo-warning' ).prop( 'hidden', true );
			} );
		} );

		// On a network, the preview can show the page of another site.
		$( '.offair-preview-site' ).on( 'change', function () {
			var site = parseInt( this.value, 10 ) || 0;
			var $frame = $( this ).closest( '.offair-panel' ).find( '.offair-preview' );
			var url = $frame.attr( 'data-base' ) + ( site ? '&site=' + site : '' );

			$frame.attr( 'data-src', url );
			if ( $frame.attr( 'src' ) ) {
				$frame.attr( 'src', url );
			}
			$( this ).closest( '.offair-panel' ).find( '.offair-preview-link' ).attr( 'href', url );
		} );

		$( '.offair-confirm' ).on( 'submit', function () {
			return window.confirm( $( this ).data( 'confirm' ) );
		} );
	} );
}( jQuery ) );
