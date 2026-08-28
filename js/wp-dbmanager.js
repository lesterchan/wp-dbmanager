/**
 * WP-DBManager admin behaviour.
 *
 * Confirmation text arrives in data attributes, escaped as ordinary HTML rather
 * than through esc_js(), which used to eat the \n and print "Database.nThis
 * Action Is Not Reversible." One delegated listener on document, so a screen
 * rendering rows in a loop attaches no handler per row.
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

	/**
	 * Confirm destructive bulk actions before the form goes anywhere.
	 *
	 * The confirmation belongs to the chosen action, not the button: which of
	 * Empty, Drop, Restore or Delete depends on the dropdown beside it.
	 *
	 * @param {Event} event Submit event.
	 * @return {void}
	 */
	function confirmBulkAction( event ) {
		const form = event.target;

		if ( ! form.matches || ! form.matches( '[data-dbmanager-confirm-actions]' ) ) {
			return;
		}

		let messages;

		try {
			messages = JSON.parse( form.getAttribute( 'data-dbmanager-confirm-actions' ) );
		} catch {
			return;
		}

		// Whichever dropdown was used, core leaves the unused one on "-1".
		const chosen = [ 'action', 'action2' ]
			.map( ( name ) => form.querySelector( '[name="' + name + '"]' ) )
			.filter( ( select ) => select && '-1' !== select.value )
			.map( ( select ) => select.value )[ 0 ];

		if ( ! chosen || ! messages[ chosen ] ) {
			return;
		}

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( withLineBreaks( messages[ chosen ] ) ) ) {
			event.preventDefault();
		}
	}

	document.addEventListener( 'submit', confirmBulkAction );

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

			// Blocking confirm on purpose: these destroy the site's data.
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( message ) ) {
				// Returning false from an inline handler used to cancel the
				// submit. preventDefault() is what does that for a delegated one.
				event.preventDefault();
			}
		}
	} );
}() );
