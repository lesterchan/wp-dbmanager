/**
 * WP-DBManager admin behaviour.
 *
 * Replaces the inline onclick= attributes the screens carried before 3.0.0.
 * Those built JavaScript string literals out of translated text, which meant
 * every confirmation message went through esc_js( __( ... ) ) - and the \n
 * sequences did not survive the trip, so the dialogs read "Database.nThis
 * Action Is Not Reversible." The data attributes below carry the same values
 * as ordinary escaped HTML attributes instead.
 *
 * One delegated listener on document, so screens that render their table rows
 * in a loop do not each attach a handler per row.
 */
( function() {
	'use strict';

	/**
	 * Turn the literal \n sequences from the translated string into real ones.
	 *
	 * @param {string} text Message as it arrives from the data attribute.
	 * @return {string} Message with real line breaks.
	 */
	function withLineBreaks( text ) {
		return text.replace( /\\n/g, '\n' );
	}

	/**
	 * Fill one of the path fields with the detected binary location.
	 *
	 * @param {string} which Either mysql or mysqldump.
	 * @return {void}
	 */
	function autoDetect( which ) {
		const l10n = window.wpDBManagerL10n || {};
		const field = document.getElementById(
			'mysqldump' === which ? 'db_mysqldumppath' : 'db_mysqlpath',
		);

		if ( ! field ) {
			return;
		}

		field.value = l10n[ which ] || '';
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		const back = target.closest( '[data-dbmanager-back]' );

		if ( back ) {
			event.preventDefault();
			window.history.go( -1 );
			return;
		}

		const detect = target.closest( '[data-dbmanager-detect]' );

		if ( detect ) {
			event.preventDefault();
			autoDetect( detect.getAttribute( 'data-dbmanager-detect' ) );
			return;
		}

		const confirmable = target.closest( '[data-dbmanager-confirm]' );

		if ( confirmable ) {
			const message = withLineBreaks(
				confirmable.getAttribute( 'data-dbmanager-confirm' ),
			);

			// A blocking confirm is the existing, deliberate behaviour here:
			// restoring, dropping and emptying are irreversible and destroy the
			// site's data, so the interruption is the point.
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( message ) ) {
				// Returning false from an inline handler used to cancel the
				// submit. preventDefault() is what does that for a delegated one.
				event.preventDefault();
			}
		}
	} );
}() );
