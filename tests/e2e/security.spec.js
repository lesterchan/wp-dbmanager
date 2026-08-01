/**
 * Who may reach these screens, and what they render.
 *
 * The capability is the important half. Every screen this plugin owns can
 * destroy the site: the Manage screen restores a database over the live one, the
 * Empty/Drop screen drops tables, and the console runs SQL. So the gate is
 * install_plugins rather than the manage_options a settings-only plugin would
 * take, and it is checked in both directions -- an editor shut out, and an
 * administrator let in, in the same test, because "the editor sees nothing"
 * passes with the plugin deactivated.
 *
 * The rendering half is smaller than it is elsewhere in the collection, and that
 * is a finding rather than an omission: this plugin stores no user-supplied text
 * at all. What it renders comes from SHOW TABLES, from the file names in the
 * backup folder and from three settings a site owner typed. Those are the
 * surfaces tested here -- a table name and a backup file name are attacker
 * controlled on a site where anything else has already gone wrong, and the
 * e-mail subject is typed straight into the settings screen.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	VISIBLE_ERROR_NOTICE,
	BACKUP_URL,
	EMPTY_URL,
	MANAGER_URL,
	MANAGE_URL,
	OPTIMIZE_URL,
	OPTIONS_URL,
	REPAIR_URL,
	RUN_URL,
	clearBackups,
	dropScratchTable,
	logInAs,
	resetPlugin,
	wpEval,
} = require( './helpers.js' );

const SCRIPT_PAYLOAD = '<script>window.__pwned = 1;</script>';
const ATTR_PAYLOAD = '" onmouseover="window.__pwned = 1';
const IMG_PAYLOAD = '<img src=x onerror="window.__pwned = 1">';

/**
 * A payload as a base64 literal, ready to drop into a PHP snippet.
 *
 * Encoded rather than interpolated. These payloads are quotes and angle
 * brackets by design, and a fixture that arrives at the far end with one of
 * them rewritten is not the payload -- it is a different string that happens to
 * look like one, and it proves nothing about escaping the real thing.
 *
 * @param {string} payload The payload.
 * @return {string} A PHP expression that evaluates to it.
 */
function php( payload ) {
	return `base64_decode( '${ Buffer.from( payload, 'utf8' ).toString( 'base64' ) }' )`;
}

/**
 * Whether any payload managed to run.
 *
 * @param {import('@playwright/test').Page} page Page to ask.
 * @return {Promise<boolean>} True if the sentinel was set.
 */
function pwned( page ) {
	return page.evaluate( () => window.__pwned === 1 );
}

/** Every screen the plugin registers, so the gate can be asked about each. */
const SCREENS = [
	MANAGER_URL,
	BACKUP_URL,
	MANAGE_URL,
	OPTIMIZE_URL,
	REPAIR_URL,
	EMPTY_URL,
	RUN_URL,
	OPTIONS_URL,
];

test.describe( 'The screens are gated', () => {
	test.beforeEach( async () => {
		resetPlugin();
	} );

	test( 'an administrator reaches every screen', async ( { page } ) => {
		// The precondition for the refusals below, and half of every capability
		// test in this file: a screen that is broken for everybody would pass
		// "the editor cannot reach it" without the gate doing anything.
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'WP-DBManager' );

		for ( const url of SCREENS ) {
			await page.goto( url );
			await expect( page.locator( 'body' ), url ).not.toContainText( 'Access Denied' );
			await expect( page.locator( '#wpbody-content h1' ).first(), url ).toBeVisible();
		}
	} );

	test( 'an editor reaches none of them, by menu or by URL', async ( { page, requestUtils } ) => {
		const editor = await logInAs( page, requestUtils, 'dbm_editor', 'editor' );

		await editor.page.goto( '/wp-admin/index.php' );
		await expect( editor.page.locator( '#adminmenu' ).getByText( 'WP-DBManager' ) ).toHaveCount(
			0,
		);

		for ( const url of SCREENS ) {
			await editor.page.goto( url );
			await expect( editor.page.locator( 'body' ), url ).toContainText(
				/Access Denied|not allowed to access this page/,
			);
		}

		await editor.context.close();
	} );

	test( 'a subscriber cannot run a query or drop a table by posting to the screen', async ( {
		page,
		requestUtils,
	} ) => {
		// The screens refuse to render, but the check that matters is the one on
		// the write: a POST straight at the page slug never renders anything, so
		// the capability check has to happen before the work rather than as part
		// of drawing the form.
		const subscriber = await logInAs( page, requestUtils, 'dbm_subscriber', 'subscriber' );

		const before = wpEval(
			`global $wpdb;
			echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) . '>>>';`,
		);

		const outcome = await subscriber.page.evaluate( async ( url ) => {
			const body = new URLSearchParams( {
				do: 'Run',
				_wpnonce: 'not-a-real-nonce',
				sql_query: 'DELETE FROM wp_options WHERE option_id > 0',
			} );

			const response = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return { code: response.status, text: await response.text() };
		}, RUN_URL );

		expect( outcome.text ).toMatch( /Access Denied|not allowed to access this page|expired/ );

		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) . '>>>';`,
			),
		).toBe( before );

		await subscriber.context.close();
	} );

	test( 'a stale nonce stops an administrator running a query too', async ( { page } ) => {
		// The capability says who may be here; the nonce says this request came
		// from the screen rather than from somewhere else on the web. Both, not
		// either.
		const before = wpEval(
			`global $wpdb;
			echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) . '>>>';`,
		);

		await page.goto( RUN_URL );

		const text = await page.evaluate( async () => {
			const body = new URLSearchParams( {
				do: 'Run',
				_wpnonce: 'not-a-real-nonce',
				sql_query: 'DELETE FROM wp_options WHERE option_id > 0',
			} );

			const response = await fetch( window.location.href, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return response.text();
		} );

		expect( text ).toContain( 'link you followed has expired' );
		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) . '>>>';`,
			),
		).toBe( before );
	} );
} );

test.describe( 'Stored names stay inert', () => {
	test.beforeEach( async () => {
		resetPlugin();
		clearBackups();
	} );

	test.afterEach( async () => {
		clearBackups();
		dropScratchTable();
	} );

	test( 'a table whose own name is an attack is rendered as text on every screen', async ( {
		page,
	} ) => {
		// A table name is not something a visitor types, but MySQL will accept
		// almost anything inside backticks, the name comes back out of SHOW TABLES
		// rather than out of anything the plugin sanitised, and it is printed on
		// four screens and into a checkbox value on three of them. That is the
		// shape §7.2.4 is about.
		const hostile = wpEval(
			`global $wpdb;
			$name = $wpdb->prefix . 'dbm_e2e_' . ${ php( IMG_PAYLOAD ) };
			$wpdb->query( "DROP TABLE IF EXISTS \`{$name}\`" );
			$wpdb->query( "CREATE TABLE \`{$name}\` ( id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) )" );
			echo '<<<' . ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ? $name : 'not created' ) . '>>>';`,
		);

		// The precondition, said out loud: if MySQL had refused the name there
		// would be nothing hostile on any of these screens and every assertion
		// below would pass while testing nothing.
		expect( hostile ).toContain( 'onerror' );

		for ( const url of [ MANAGER_URL, OPTIMIZE_URL, EMPTY_URL ] ) {
			await page.goto( url );

			expect( await pwned( page ), url ).toBe( false );

			// As text, not merely absent: an admin about to drop a table has to be
			// able to read which one it is.
			await expect( page.locator( '.wp-list-table' ), url ).toContainText( 'onerror' );
			await expect( page.locator( '#wpbody-content img[onerror]' ), url ).toHaveCount( 0 );
		}

		wpEval(
			`global $wpdb;
			$name = $wpdb->prefix . 'dbm_e2e_' . ${ php( IMG_PAYLOAD ) };
			$wpdb->query( "DROP TABLE IF EXISTS \`{$name}\`" );
			echo '<<<done>>>';`,
		);
	} );

	test( 'a backup file name carrying markup is rendered as text', async ( { page } ) => {
		// The file name is read off disk and split on its own separator, so the
		// "database" part of it is whatever the file is called. sanitize_file_name()
		// is what the plugin writes with; a file copied in by hand is not.
		const name = wpEval(
			`$path = WP_DBManager_Options::backup_path();
			$name = time() . '_-_hostile' . ${ php( ATTR_PAYLOAD ) } . '.sql';
			file_put_contents( $path . '/' . $name, '-- backup' );
			echo '<<<' . $name . '>>>';`,
		);

		expect( name ).toContain( 'onmouseover' );

		await page.goto( MANAGE_URL );

		expect( await pwned( page ) ).toBe( false );

		// As text, not merely absent: an admin has to be able to tell which file
		// they are about to restore.
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'onmouseover' );
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table script' ) ).toHaveCount( 0 );
	} );

	test( 'the settings a site owner typed come back as text', async ( { page } ) => {
		// Three free-text settings are echoed back into value attributes on the
		// screen that wrote them, and the subject one is also interpolated into a
		// mail header. An attribute break is enough on any of them.
		wpEval(
			`$all = WP_DBManager_Options::get();
			$all['backup_email_subject']   = 'Backup ' . ${ php( ATTR_PAYLOAD ) };
			$all['backup_email_from_name'] = 'Robot ' . ${ php( SCRIPT_PAYLOAD ) };
			WP_DBManager_Options::update( $all );
			echo '<<<done>>>';`,
		);

		await page.goto( OPTIONS_URL );

		expect( await pwned( page ) ).toBe( false );

		await expect(
			page.locator( 'input[name="wp_dbmanager_options[backup_email_subject]"]' ),
		).toHaveValue( /onmouseover/ );
		await expect(
			page.locator( 'input[name="wp_dbmanager_options[backup_email_from_name]"]' ),
		).toHaveValue( /window\.__pwned/ );
		await expect( page.locator( '#wpbody-content script[src=""]' ) ).toHaveCount( 0 );
	} );

	test( 'the console echoes the statement it ran back as text', async ( { page } ) => {
		// Every statement is repeated in a notice, success or failure, so the
		// console is a reflected surface for whatever was typed into it.
		await page.goto( RUN_URL );

		await page
			.locator( '#sql_query' )
			.fill( `INSERT INTO wp_not_a_real_table ( x ) VALUES ( '${ SCRIPT_PAYLOAD }' )` );
		await page.getByRole( 'button', { name: 'Run', exact: true } ).click();

		expect( await pwned( page ) ).toBe( false );
		await expect( page.locator( '.notice-error' ) ).toContainText( 'window.__pwned' );
		await expect( page.locator( '#wpbody-content script[src=""]' ) ).toHaveCount( 0 );
	} );

	test( 'the backup folder warning names the folder without letting it run', async ( {
		page,
	} ) => {
		wpEval(
			`$all = WP_DBManager_Options::get();
			$all['path'] = '/nowhere/' . ${ php( ATTR_PAYLOAD ) };
			WP_DBManager_Options::update( $all );
			echo '<<<done>>>';`,
		);

		await page.goto( '/wp-admin/index.php' );

		expect( await pwned( page ) ).toBe( false );
		// See VISIBLE_ERROR_NOTICE: the Dashboard also carries core's
		// community-events error notice, which is aria-hidden until its script
		// reveals it, so the bare selector matches two elements.
		await expect( page.locator( VISIBLE_ERROR_NOTICE ) ).toContainText(
			'onmouseover',
		);
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
	} );
} );
