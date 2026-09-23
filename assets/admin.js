/* global jQuery, wp, offairAdmin */
( function ( $ ) {
	'use strict';

	$( function () {
		var $tabs = $( '.offair-tabs .nav-tab' );
		var $panels = $( '.offair-panel' );
		var $save = $( '.offair-save' );
		var $currentTab = $( '#offair-current-tab' );
		var previewTimer;

		// Address of the preview, with the site of the network to show.
		function previewUrl( $frame ) {
			var site = parseInt( $frame.closest( '.offair-preview-pane' ).find( '.offair-preview-site' ).val(), 10 ) || 0;

			return $frame.attr( 'data-base' ) + ( site ? '&site=' + site : '' );
		}

		/*
		 * The fields being edited are posted to the preview, which renders them
		 * without saving anything. A hidden form carries them, so that the answer
		 * lands straight in the frame.
		 */
		function refreshPreview( $frame ) {
			if ( ! $frame || ! $frame.length ) {
				return;
			}

			var $form = $( '<form method="post"></form>' ).attr( { action: previewUrl( $frame ), target: $frame.attr( 'name' ) } ).css( 'display', 'none' );

			$.each( $( '#offair-form' ).serializeArray(), function ( index, field ) {
				if ( 0 === field.name.indexOf( 'offair[' ) ) {
					$( '<input type="hidden">' ).attr( 'name', field.name ).val( field.value ).appendTo( $form );
				}
			} );

			$form.appendTo( document.body );
			$form[ 0 ].submit();
			$form.remove();
		}

		function visiblePreview() {
			return $panels.filter( ':visible' ).find( '.offair-preview' ).first();
		}

		// A short wait keeps typing from reloading the frame on every letter.
		function schedulePreview() {
			window.clearTimeout( previewTimer );
			previewTimer = window.setTimeout( function () {
				refreshPreview( visiblePreview() );
			}, 400 );
		}

		function activate( id ) {
			id = ( id || '' ).replace( /[^a-z0-9-]/gi, '' );

			if ( ! id || ! $panels.filter( '#' + id ).length ) {
				id = $panels.first().attr( 'id' );
			}

			$tabs.removeClass( 'nav-tab-active' ).filter( '[href="#' + id + '"]' ).addClass( 'nav-tab-active' );
			$panels.hide().filter( '#' + id ).show();
			$save.toggle( -1 === [ 'offair-tab-history', 'offair-tab-advanced' ].indexOf( id ) );
			$currentTab.val( id.replace( 'offair-tab-', '' ) );

			// The preview of the tab being opened is rendered from the current fields.
			refreshPreview( $( '#' + id ).find( '.offair-preview' ).first() );
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
			$( '.offair-color' ).wpColorPicker( { change: schedulePreview, clear: schedulePreview } );
		}

		$( '#offair-form' ).on( 'input change', 'input, select, textarea', schedulePreview );

		$( '.offair-device' ).on( 'click', function () {
			var $pane = $( this ).closest( '.offair-preview-pane' );

			$pane.find( '.offair-device' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			$pane.find( '.offair-preview-frame' ).toggleClass( 'is-mobile', 'mobile' === $( this ).data( 'device' ) );
		} );

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
						schedulePreview();
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
				schedulePreview();
			} );
		} );

		// On a network, the preview can show the page of another site.
		$( '.offair-preview-site' ).on( 'change', function () {
			refreshPreview( $( this ).closest( '.offair-preview-pane' ).find( '.offair-preview' ).first() );
		} );

		$( '.offair-confirm' ).on( 'submit', function () {
			return window.confirm( $( this ).data( 'confirm' ) );
		} );
	} );
}( jQuery ) );
