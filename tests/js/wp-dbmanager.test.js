/**
 * The admin script, loaded into jsdom the way the browser loads it.
 *
 * js/wp-dbmanager.js is an IIFE with no exports -- it ships to users exactly as
 * it is, with no build step -- so it is evaluated once here and then driven
 * through the DOM, which is also the only way to prove the delegated listeners
 * on `document` are wired up at all.
 *
 * The behaviours worth pinning are the ones that are silent when they break:
 * a confirmation that never appears before an irreversible restore, and the
 * \n sequences that used to render literally in the dialog.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const here = dirname( fileURLToPath( import.meta.url ) );
const source = readFileSync( join( here, '..', '..', 'js', 'wp-dbmanager.js' ), 'utf8' );

/**
 * Build the markup the Manage Backup DB screen renders around its list table.
 *
 * @param {Object} confirmations Bulk action to confirmation message.
 * @return {HTMLFormElement} The form, already in the document.
 */
function renderBulkForm( confirmations ) {
	document.body.innerHTML = `
		<form data-dbmanager-confirm-actions='${ JSON.stringify( confirmations ) }'>
			<select name="action">
				<option value="-1">Bulk actions</option>
				<option value="restore">Restore</option>
				<option value="delete">Delete</option>
			</select>
			<select name="action2">
				<option value="-1">Bulk actions</option>
				<option value="restore">Restore</option>
				<option value="delete">Delete</option>
			</select>
		</form>
	`;

	return document.querySelector( 'form' );
}

/**
 * Fire a cancellable submit event the way a browser would.
 *
 * jsdom does not implement form submission, so the event is dispatched by hand.
 * Its return value is false when a listener called preventDefault().
 *
 * @param {HTMLFormElement} form Form to submit.
 * @return {boolean} Whether the submit survived.
 */
function submit( form ) {
	return form.dispatchEvent(
		new window.Event( 'submit', { bubbles: true, cancelable: true } ),
	);
}

/**
 * Fire a cancellable click the way a browser would.
 *
 * @param {HTMLElement} element Element to click.
 * @return {boolean} Whether the click survived.
 */
function click( element ) {
	return element.dispatchEvent(
		new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ),
	);
}

describe( 'wp-dbmanager admin script', () => {
	beforeAll( () => {
		// One evaluation for the whole file: the listeners are delegated onto
		// document, so loading it again per test would stack duplicates and
		// every confirm() assertion would count twice.
		new Function( source )();
	} );

	beforeEach( () => {
		document.body.innerHTML = '';
		delete window.wpDBManagerL10n;
	} );

	describe( 'auto detect', () => {
		it( 'fills the mysqldump field from the localised paths', () => {
			window.wpDBManagerL10n = {
				mysql: '/usr/bin/mysql',
				mysqldump: '/usr/bin/mysqldump',
			};

			document.body.innerHTML = `
				<input id="db_mysqldumppath" value="" />
				<button data-dbmanager-detect="mysqldump"></button>
			`;

			click( document.querySelector( '[data-dbmanager-detect]' ) );

			expect( document.getElementById( 'db_mysqldumppath' ).value ).toBe(
				'/usr/bin/mysqldump',
			);
		} );

		it( 'fills the mysql field from the localised paths', () => {
			window.wpDBManagerL10n = {
				mysql: '/usr/bin/mysql',
				mysqldump: '/usr/bin/mysqldump',
			};

			document.body.innerHTML = `
				<input id="db_mysqlpath" value="" />
				<button data-dbmanager-detect="mysql"></button>
			`;

			click( document.querySelector( '[data-dbmanager-detect]' ) );

			expect( document.getElementById( 'db_mysqlpath' ).value ).toBe(
				'/usr/bin/mysql',
			);
		} );

		it( 'blanks the field when nothing was localised rather than writing undefined', () => {
			document.body.innerHTML = `
				<input id="db_mysqlpath" value="stale" />
				<button data-dbmanager-detect="mysql"></button>
			`;

			click( document.querySelector( '[data-dbmanager-detect]' ) );

			expect( document.getElementById( 'db_mysqlpath' ).value ).toBe( '' );
		} );

		it( 'does not throw when the field it would fill is not on the screen', () => {
			document.body.innerHTML =
				'<button data-dbmanager-detect="mysqldump"></button>';

			expect( () =>
				click( document.querySelector( '[data-dbmanager-detect]' ) ),
			).not.toThrow();
		} );
	} );

	describe( 'the cancel button', () => {
		it( 'goes back one entry instead of following a link', () => {
			const go = vi
				.spyOn( window.history, 'go' )
				.mockImplementation( () => {} );

			document.body.innerHTML =
				'<a href="/somewhere" data-dbmanager-back="1">Cancel</a>';

			const survived = click( document.querySelector( '[data-dbmanager-back]' ) );

			expect( go ).toHaveBeenCalledWith( -1 );
			expect( survived ).toBe( false );
		} );
	} );

	describe( 'bulk action confirmations', () => {
		it( 'confirms the action chosen in the top dropdown', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			const form = renderBulkForm( { delete: 'Delete them?' } );
			form.querySelector( '[name="action"]' ).value = 'delete';

			expect( submit( form ) ).toBe( true );
			expect( confirm ).toHaveBeenCalledWith( 'Delete them?' );
		} );

		it( 'confirms the action chosen in the bottom dropdown', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			const form = renderBulkForm( { restore: 'Restore it?' } );
			form.querySelector( '[name="action2"]' ).value = 'restore';

			submit( form );

			expect( confirm ).toHaveBeenCalledWith( 'Restore it?' );
		} );

		it( 'stops the submit when the confirmation is declined', () => {
			vi.spyOn( window, 'confirm' ).mockImplementation( () => false );

			const form = renderBulkForm( { delete: 'Delete them?' } );
			form.querySelector( '[name="action"]' ).value = 'delete';

			expect( submit( form ) ).toBe( false );
		} );

		it( 'turns the literal backslash-n of the translated string into real line breaks', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			const form = renderBulkForm( {
				restore: 'You Are About To Restore A Database.\\nThis Action Is Not Reversible.',
			} );
			form.querySelector( '[name="action"]' ).value = 'restore';

			submit( form );

			expect( confirm ).toHaveBeenCalledWith(
				'You Are About To Restore A Database.\nThis Action Is Not Reversible.',
			);
		} );

		it( 'asks nothing when both dropdowns are still on the placeholder', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			const form = renderBulkForm( { delete: 'Delete them?' } );

			expect( submit( form ) ).toBe( true );
			expect( confirm ).not.toHaveBeenCalled();
		} );

		it( 'asks nothing for an action with no confirmation of its own', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			const form = renderBulkForm( { delete: 'Delete them?' } );
			form.querySelector( '[name="action"]' ).value = 'restore';

			expect( submit( form ) ).toBe( true );
			expect( confirm ).not.toHaveBeenCalled();
		} );

		it( 'lets a form without the attribute submit untouched', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			document.body.innerHTML = '<form><input name="do" /></form>';

			expect( submit( document.querySelector( 'form' ) ) ).toBe( true );
			expect( confirm ).not.toHaveBeenCalled();
		} );

		it( 'survives an attribute that is not valid JSON', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			document.body.innerHTML =
				'<form data-dbmanager-confirm-actions="{not json"><select name="action"><option value="delete" selected></option></select></form>';

			expect( () => submit( document.querySelector( 'form' ) ) ).not.toThrow();
			expect( confirm ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'single element confirmations', () => {
		it( 'confirms before the click and lets it through when accepted', () => {
			const confirm = vi
				.spyOn( window, 'confirm' )
				.mockImplementation( () => true );

			document.body.innerHTML =
				'<button data-dbmanager-confirm="Drop it?\\nReally?"></button>';

			const survived = click(
				document.querySelector( '[data-dbmanager-confirm]' ),
			);

			expect( confirm ).toHaveBeenCalledWith( 'Drop it?\nReally?' );
			expect( survived ).toBe( true );
		} );

		it( 'cancels the click when declined', () => {
			vi.spyOn( window, 'confirm' ).mockImplementation( () => false );

			document.body.innerHTML =
				'<button data-dbmanager-confirm="Drop it?"></button>';

			expect(
				click( document.querySelector( '[data-dbmanager-confirm]' ) ),
			).toBe( false );
		} );
	} );

	describe( 'the shipped source', () => {
		it( 'uses no jQuery', () => {
			expect( source ).not.toMatch( /jQuery|\$\(/ );
		} );

		it( 'keeps everything inside one IIFE rather than on the global object', () => {
			expect( source ).toMatch( /^\(\s*function\(\)\s*\{/m );
			expect( source ).toMatch( /}\(\s*\)\s*\);\s*$/ );
		} );
	} );
} );
