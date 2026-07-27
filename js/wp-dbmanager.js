/**
 * WP-DBManager admin behaviour.
 *
 * Replaces the inline onclick= attributes the screens carried before 3.0.0.
 * Those built JavaScript string literals out of translated text, which meant
 * every confirmation message went through esc_js( __( ... ) ) and the auto
 * detect buttons interpolated a shell-derived path straight into a <script>
 * block. The data attributes below carry the same values as ordinary escaped
 * HTML attributes instead.
 *
 * One delegated listener on document, so screens that render their table rows
 * in a loop do not each attach a handler per row.
 */
( function () {
	'use strict';

	/**
	 * The \n sequences live in translated strings as two literal characters,
	 * because they used to be interpolated into a JS string literal. Turn them
	 * into real newlines so confirm() still shows the message on several lines.
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
		var l10n = window.wpDBManagerL10n || {};
		var field = document.getElementById(
			'mysqldump' === which ? 'db_mysqldumppath' : 'db_mysqlpath'
		);

		if ( ! field ) {
			return;
		}

		field.value = l10n[ which ] || '';
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var back = target.closest( '[data-dbmanager-back]' );

		if ( back ) {
			event.preventDefault();
			window.history.go( -1 );
			return;
		}

		var detect = target.closest( '[data-dbmanager-detect]' );

		if ( detect ) {
			event.preventDefault();
			autoDetect( detect.getAttribute( 'data-dbmanager-detect' ) );
			return;
		}

		var confirmable = target.closest( '[data-dbmanager-confirm]' );

		if ( confirmable ) {
			// Returning false from an inline handler used to cancel the submit.
			// preventDefault() is what does that for a delegated listener.
			if ( ! window.confirm( withLineBreaks( confirmable.getAttribute( 'data-dbmanager-confirm' ) ) ) ) {
				event.preventDefault();
			}
		}
	} );
}() );
