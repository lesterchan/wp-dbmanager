/**
 * Shared steps for the WP-DBManager end-to-end suite.
 *
 * This plugin restores, empties and drops tables, so the first question a suite
 * for it has to answer is what it is allowed to touch. The answer here is: only
 * things it made. Every destructive test operates on a scratch table this file
 * creates and drops, never on a WordPress table -- emptying wp_options in the
 * environment PHPUnit shares would not fail loudly, it would leave the next
 * suite mysteriously broken. The Run SQL Query console is driven with statements
 * against that same scratch table, and the one statement class that could not be
 * contained -- restore, which overwrites the entire database from a dump -- is
 * covered up to the point where the shell command is built and no further; see
 * backups.spec.js.
 *
 * Backups are real: mysqldump is present in the wp-env container, the backup
 * folder is the site's own, and every file the suite writes there is removed
 * afterwards. A fake backup is not a backup, and the one thing worth knowing
 * about this plugin is whether the dump it produced can be read back.
 */

const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** The plugin root, which is where wp-env reads .wp-env.json from. */
const PLUGIN_ROOT = path.join( __dirname, '../..' );

const MANAGER_URL = '/wp-admin/admin.php?page=wp-dbmanager';
const BACKUP_URL = '/wp-admin/admin.php?page=wp-dbmanager-backup';
const MANAGE_URL = '/wp-admin/admin.php?page=wp-dbmanager-manage';
const OPTIMIZE_URL = '/wp-admin/admin.php?page=wp-dbmanager-optimize';
const REPAIR_URL = '/wp-admin/admin.php?page=wp-dbmanager-repair';
const EMPTY_URL = '/wp-admin/admin.php?page=wp-dbmanager-empty';
const RUN_URL = '/wp-admin/admin.php?page=wp-dbmanager-run';
const OPTIONS_URL = '/wp-admin/admin.php?page=wp-dbmanager-options';

/**
 * The error notices an administrator can actually see.
 *
 * The Dashboard carries one of core's own: the community-events widget prints
 * a `.notice.notice-error.community-events-errors` up front and leaves it
 * `aria-hidden="true"` until its script has something to put in it. A bare
 * `.notice-error` therefore matches two elements on wp-admin/index.php and
 * every assertion against it dies of Playwright strict mode rather than of
 * anything being wrong with this plugin's warning.
 *
 * Excluded by class rather than by whether it is hidden, because hiding is only
 * its resting state: the widget asks api.wordpress.org where the reader is, and
 * when that request fails the script *reveals* the notice. On a developer's
 * machine it succeeds and the notice stays hidden; on a CI runner with no
 * outbound network it fails and the notice appears. Anything keyed on its
 * hidden-ness therefore stops excluding it exactly in CI, which is the worst
 * place to debug a strict-mode violation.
 *
 * `:visible` covers the rest of the screen. Core also prints an empty
 * `.notice-error.notice-alt.inline.hidden` for its own scripts to fill in, on
 * the Dashboard and elsewhere, and that one is display:none rather than
 * aria-hidden -- so an aria-hidden exclusion never caught it. Asking for the
 * notices that are on screen says what these assertions mean anyway: an
 * administrator can see this warning.
 */
const VISIBLE_ERROR_NOTICE =
	'#wpbody-content .notice-error:not(.community-events-errors):visible';

/**
 * The scratch table every destructive test works on.
 *
 * Prefixed like a site table so it turns up in SHOW TABLES -- which is the list
 * the plugin validates a submitted table name against, and therefore the only
 * way a table can be selected on any of these screens at all.
 */
const SCRATCH_TABLE_SUFFIX = 'dbmanager_e2e_scratch';

/**
 * Run PHP inside the tests environment and hand back what it printed.
 *
 * The code is base64'd rather than passed as itself: a SQL statement holding
 * quotes and angle brackets is exactly the sort of string that arrives at the
 * other end subtly different, and a fixture that is not the payload byte for
 * byte proves nothing.
 *
 * @param {string} code PHP to evaluate, without an opening tag.
 * @return {string} Whatever the code echoed between its markers.
 */
function wpEval( code ) {
	const encoded = Buffer.from( code, 'utf8' ).toString( 'base64' );

	const output = execFileSync(
		'npx',
		[
			'--yes',
			'@wordpress/env',
			'run',
			'tests-cli',
			'wp',
			'eval',
			`eval( base64_decode( '${ encoded }' ) );`,
		],
		{ cwd: PLUGIN_ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	// wp-env prints its own progress around the command's output, so the code
	// wraps what it wants to return in markers rather than the caller trying to
	// tell the two apart by position.
	const matched = output.match( /<<<([\s\S]*)>>>/ );

	return matched ? matched[ 1 ] : '';
}

/**
 * The full name of the scratch table, prefix and all.
 *
 * @return {string} Table name.
 */
function scratchTable() {
	return wpEval(
		`global $wpdb; echo '<<<' . $wpdb->prefix . '${ SCRATCH_TABLE_SUFFIX }' . '>>>';`,
	);
}

/**
 * Create the scratch table and fill it with a known number of rows.
 *
 * MyISAM on purpose, and not because the plugin cares which engine a site uses.
 * SHOW TABLE STATUS reports an *estimate* for an InnoDB table -- routinely zero
 * for one this small -- so the Records column could not be asserted on at all;
 * and REPAIR TABLE is a no-op on InnoDB, which would leave the Repair screen
 * with nothing to actually do. MyISAM gives both an exact row count and a real
 * repair.
 *
 * @param {number} [rows] How many rows to put in it.
 * @return {string} The table's full name.
 */
function createScratchTable( rows = 3 ) {
	return wpEval(
		`global $wpdb;
		$table = $wpdb->prefix . '${ SCRATCH_TABLE_SUFFIX }';
		$wpdb->query( "DROP TABLE IF EXISTS \`{$table}\`" );
		$wpdb->query( "CREATE TABLE \`{$table}\` ( id INT NOT NULL AUTO_INCREMENT, label VARCHAR(64) NOT NULL DEFAULT '', PRIMARY KEY (id) ) ENGINE=MyISAM" );
		for ( $i = 1; $i <= ${ rows }; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO \`{$table}\` ( label ) VALUES ( %s )", 'row ' . $i ) );
		}
		echo '<<<' . $table . '>>>';`,
	);
}

/**
 * Drop the scratch table if it is still there.
 *
 * @return {void}
 */
function dropScratchTable() {
	wpEval(
		`global $wpdb;
		$table = $wpdb->prefix . '${ SCRATCH_TABLE_SUFFIX }';
		$wpdb->query( "DROP TABLE IF EXISTS \`{$table}\`" );
		echo '<<<done>>>';`,
	);
}

/**
 * Whether the scratch table exists, and how many rows it holds.
 *
 * @return {Object} Keys 'exists' and 'rows'.
 */
function scratchState() {
	return JSON.parse(
		wpEval(
			`global $wpdb;
			$table  = $wpdb->prefix . '${ SCRATCH_TABLE_SUFFIX }';
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$rows   = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM \`{$table}\`" ) : 0;
			echo '<<<' . wp_json_encode( array( 'exists' => $exists, 'rows' => $rows ) ) . '>>>';`,
		),
	);
}

/**
 * Put every setting back to a fresh install's, with the detected binary paths.
 *
 * The two paths are detected rather than defaulted, because the Backup screen
 * refuses to run without them and the whole of backups.spec.js depends on a
 * backup actually being taken.
 *
 * @return {void}
 */
function resetPlugin() {
	wpEval(
		`$defaults  = WP_DBManager_Options::defaults();
		$binaries  = WP_DBManager_Database::detect_binaries();
		$defaults['mysqldumppath'] = $binaries['mysqldump'];
		$defaults['mysqlpath']     = $binaries['mysql'];
		WP_DBManager_Options::update( $defaults );
		WP_DBManager_Folder::create();
		WP_DBManager_Folder::flush();
		echo '<<<' . $defaults['mysqldumppath'] . '>>>';`,
	);
}

/**
 * One stored setting.
 *
 * @param {string} key Setting name.
 * @return {string} The stored value, as a string.
 */
function setting( key ) {
	return wpEval( `echo '<<<' . WP_DBManager_Options::get( '${ key }' ) . '>>>';` );
}

/**
 * Write one setting straight into the option row.
 *
 * For the preconditions a test needs but is not itself testing. Settings whose
 * saving is under test go through the form instead.
 *
 * @param {string} key   Setting name.
 * @param {string} value Value to store.
 * @return {void}
 */
function setSetting( key, value ) {
	const encoded = Buffer.from( String( value ), 'utf8' ).toString( 'base64' );

	wpEval(
		`$all = WP_DBManager_Options::get();
		$all['${ key }'] = base64_decode( '${ encoded }' );
		WP_DBManager_Options::update( $all );
		echo '<<<done>>>';`,
	);
}

/**
 * The backup files currently in the backup folder, oldest first.
 *
 * @return {string[]} File names.
 */
function backupFiles() {
	return JSON.parse(
		wpEval(
			`$files = array_map(
				static function ( $file ) {
					return $file['name'];
				},
				WP_DBManager_Backups::all()
			);
			echo '<<<' . wp_json_encode( array_values( $files ) ) . '>>>';`,
		),
	);
}

/**
 * Delete every file in the backup folder.
 *
 * The folder is the site's own, so this is scoped to the .sql and .sql.gz files
 * the plugin itself lists rather than emptying the directory -- the .htaccess
 * and index.php that keep it off the public web have to stay.
 *
 * @return {void}
 */
function clearBackups() {
	wpEval(
		`$path = WP_DBManager_Options::backup_path();
		foreach ( WP_DBManager_Backups::all( $path ) as $file ) {
			wp_delete_file( $path . '/' . $file['name'] );
		}
		echo '<<<' . count( WP_DBManager_Backups::all( $path ) ) . '>>>';`,
	);
}

/**
 * Write a file into the backup folder that looks like a backup.
 *
 * For the tests about listing, sorting, pruning and deleting, where taking a
 * real dump per fixture would cost seconds each and prove nothing extra. The
 * tests that are about the dump itself take a real one.
 *
 * @param {number} timestamp Unix time to name it after.
 * @param {string} [body]    What to put in the file.
 * @return {string} The file name.
 */
function writeFakeBackup( timestamp, body = '-- a backup\n' ) {
	const encoded = Buffer.from( body, 'utf8' ).toString( 'base64' );

	return wpEval(
		`$path = WP_DBManager_Options::backup_path();
		$name = '${ timestamp }_-_' . DB_NAME . '.sql';
		file_put_contents( $path . '/' . $name, base64_decode( '${ encoded }' ) );
		$full = md5_file( $path . '/' . $name ) . '_-_' . $name;
		rename( $path . '/' . $name, $path . '/' . $full );
		touch( $path . '/' . $full, ${ timestamp } );
		echo '<<<' . $full . '>>>';`,
	);
}

/**
 * The mu-plugin that keeps the backup e-mail off the wire.
 *
 * The E-Mail action on the Manage screen hands a real message to wp_mail(), and
 * a suite that let that through would post a copy of the database to whatever
 * address the fixture used. pre_wp_mail is the documented short circuit;
 * recording $atts first is what lets a test assert on the recipient and the
 * attachment rather than on a notice.
 *
 * @return {void}
 */
function installMailInterceptor() {
	const php = `<?php
/**
 * Plugin Name: WP-DBManager E2E mail interceptor
 * Description: Records wp_mail() calls and sends nothing. Installed by the Playwright suite.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		update_option( 'e2e_intercepted_mail', $atts, false );

		return true;
	},
	10,
	2
);
`;

	const encoded = Buffer.from( php, 'utf8' ).toString( 'base64' );

	wpEval(
		`$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/wp-dbmanager-e2e-mail.php', base64_decode( '${ encoded }' ) );
		echo '<<<' . ( is_file( $dir . '/wp-dbmanager-e2e-mail.php' ) ? 'installed' : 'missing' ) . '>>>';`,
	);
}

/**
 * The message the plugin last handed to wp_mail().
 *
 * @return {Object|null} Keys 'to', 'subject', 'message' and 'attachments'.
 */
function lastMail() {
	const raw = wpEval(
		`$mail = get_option( 'e2e_intercepted_mail', null );
		echo '<<<' . wp_json_encode( null === $mail ? null : $mail ) . '>>>';`,
	);

	return raw ? JSON.parse( raw ) : null;
}

/**
 * Forget the last intercepted message.
 *
 * @return {void}
 */
function resetMail() {
	wpEval( `delete_option( 'e2e_intercepted_mail' ); echo '<<<done>>>';` );
}

/**
 * Run a bulk action against one row of a list table.
 *
 * Every destructive operation on these screens is a bulk action on a real form
 * post rather than a row-action link, on purpose: a link is a GET, and a browser
 * or a link checker that prefetched one would restore a database.
 *
 * @param {import('@playwright/test').Page} page     Page under test.
 * @param {string}                          checkbox Selector for the row's checkbox.
 * @param {string}                          action   Value from the bulk dropdown.
 * @return {Promise<void>} Resolves once the form has been submitted.
 */
async function bulkAction( page, checkbox, action ) {
	await page.locator( checkbox ).check();
	await page.locator( '#bulk-action-selector-top' ).selectOption( action );
	await page.locator( '#doaction' ).click();
}

/**
 * The row of a list table whose first cell holds this text.
 *
 * @param {import('@playwright/test').Page} page Page showing the table.
 * @param {string}                          text Text to look for.
 * @return {import('@playwright/test').Locator} That row.
 */
function listRow( page, text ) {
	return page.locator( '.wp-list-table tbody tr', { hasText: text } );
}

/**
 * Create a user, if they are not there already, and log a fresh browser in.
 *
 * @param {import('@playwright/test').Page} page         Page under test, for its browser.
 * @param {Object}                          requestUtils The e2e-test-utils request helper.
 * @param {string}                          username     Login name.
 * @param {string}                          role         Role to give them.
 * @return {Promise<Object>} Keys 'context' and 'page'.
 */
async function logInAs( page, requestUtils, username, role ) {
	await requestUtils
		.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username,
				email: `${ username }@example.com`,
				password: 'correct-horse-battery-staple',
				roles: [ role ],
			},
		} )
		.catch( () => {} ); // Already there from an earlier run.

	const context = await page.context().browser().newContext( { storageState: undefined } );
	const other = await context.newPage();

	await other.goto( '/wp-login.php' );

	// wp-login.php focuses and selects #user_login on a 200ms timer, so that a
	// visitor can start typing. Filling across that moment puts the password into
	// the username box: Playwright focuses #user_pass, the timer takes focus back
	// and selects what is there, and the typed text replaces the selection.
	// Waiting for the timer's own effect is the signal that it has already fired.
	await expect( other.locator( '#user_login' ) ).toBeFocused();

	await other.locator( '#user_login' ).fill( username );
	await other.locator( '#user_pass' ).fill( 'correct-horse-battery-staple' );
	await other.locator( '#wp-submit' ).click();
	await expect( other.locator( '#wpadminbar' ) ).toBeVisible();

	return { context, page: other };
}

module.exports = {
	VISIBLE_ERROR_NOTICE,
	BACKUP_URL,
	EMPTY_URL,
	MANAGER_URL,
	MANAGE_URL,
	OPTIMIZE_URL,
	OPTIONS_URL,
	REPAIR_URL,
	RUN_URL,
	backupFiles,
	bulkAction,
	clearBackups,
	createScratchTable,
	dropScratchTable,
	installMailInterceptor,
	lastMail,
	listRow,
	logInAs,
	resetMail,
	resetPlugin,
	scratchState,
	scratchTable,
	setSetting,
	setting,
	wpEval,
	writeFakeBackup,
};
