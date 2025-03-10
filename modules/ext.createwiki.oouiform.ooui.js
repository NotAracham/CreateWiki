( function () {
	$( function () {
		var tabs, previousTab, switchingNoHash;

		tabs = OO.ui.infuse( $( '.createwiki-tabs' ) );

		tabs.$element.addClass( 'createwiki-tabs-infused' );

		function enhancePanel( panel ) {
			var $infuse = $( panel.$element ).find( '.createwiki-infuse' );
			$infuse.each( function () {
				try {
					OO.ui.infuse( this );
				} catch ( error ) {
					return;
				}
			} );

			if ( !panel.$element.data( 'mw-section-infused' ) ) {
				panel.$element.removeClass( 'mw-htmlform-autoinfuse-lazy' );
				mw.hook( 'htmlform.enhance' ).fire( panel.$element );
				panel.$element.data( 'mw-section-infused', true );
			}
		// Add <hr> to divide between the wiki approval pane and the wiki request editing pane for WCs
		var targetElement = document.getElementById('ooui-php-53');

		if (targetElement) {
		// Create a <br> element
			var brElement = document.createElement('br');

			// Create an <hr> element
			var hrElement = document.createElement('hr');

			// Insert the <hr> element after the target element
			targetElement.parentNode.insertBefore(hrElement, targetElement.nextSibling);

			// Insert the <br> element after the target element
			targetElement.parentNode.insertBefore(brElement, targetElement.nextSibling);
		} else {
  			console.error("Element with ID 'ooui-php-53' not found.");
		}
		}

		function onTabPanelSet( panel ) {
			var scrollTop, active;

			if ( switchingNoHash ) {
				return;
			}
			// Handle hash manually to prevent jumping,
			// therefore save and restore scrollTop to prevent jumping.
			scrollTop = $( window ).scrollTop();
			// Changing the hash apparently causes keyboard focus to be lost?
			// Save and restore it. This makes no sense though.
			active = document.activeElement;
			location.hash = '#' + panel.getName();
			if ( active ) {
				active.focus();
			}
			$( window ).scrollTop( scrollTop );
		}

		tabs.on( 'set', onTabPanelSet );

		/**
		 * @ignore
		 * @param {string} name The name of a tab
		 * @param {boolean} [noHash] A hash will be set according to the current
		 *  open section. Use this flag to suppress this.
		 */
		function switchCreateWikiTab( name, noHash ) {
			if ( noHash ) {
				switchingNoHash = true;
			}
			tabs.setTabPanel( name );
			enhancePanel( tabs.getCurrentTabPanel() );
			if ( noHash ) {
				switchingNoHash = false;
			}
		}

		// Jump to correct section as indicated by the hash.
		// This function is called onload and onhashchange.
		function detectHash() {
			var hash = location.hash,
				matchedElement, $parentSection;
			if ( hash.match( /^#mw-section-[\w-]+$/ ) ) {
				mw.storage.session.remove( 'createwiki-prevTab' );
				switchCreateWikiTab( hash.slice( 1 ) );
			} else if ( hash.match( /^#mw-[\w-]+$/ ) ) {
				matchedElement = document.getElementById( hash.slice( 1 ) );
				$parentSection = $( matchedElement ).closest( '.createwiki-section-fieldset' );
				if ( $parentSection.length ) {
					mw.storage.session.remove( 'createwiki-prevTab' );
					// Switch to proper tab and scroll to selected item.
					switchCreateWikiTab( $parentSection.attr( 'id' ), true );
					matchedElement.scrollIntoView();
				}
			}
		}

		$( window ).on( 'hashchange', function () {
			var hash = location.hash;
			if ( hash.match( /^#mw-[\w-]+/ ) ) {
				detectHash();
			} else if ( hash === '' ) {
				switchCreateWikiTab( $( '[id*=mw-section-]' ).attr( 'id' ), true );
			}
		} )
			// Run the function immediately to select the proper tab on startup.
			.trigger( 'hashchange' );

		// Restore the active tab after saving
		previousTab = mw.storage.session.get( 'createwiki-prevTab' );
		if ( previousTab ) {
			switchCreateWikiTab( previousTab, true );
			// Deleting the key, the tab states should be reset until we press Save
			mw.storage.session.remove( 'createwiki-prevTab' );
		}

		$( '#createwiki-form' ).on( 'submit', function () {
			var value = tabs.getCurrentTabPanelName();
			mw.storage.session.set( 'createwiki-prevTab', value );
		} );
	} );
}() );
