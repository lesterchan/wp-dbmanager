/**
 * The Settings screen, and what each option changes.
 *
 * Every setting on this screen decides something about a backup: where it is
 * written, how many are kept, whether it is compressed, when it happens on its
 * own and who is told about it. So each test drives the form and then checks the
 * far end -- the stored row for the values the code reads, the scheduled event
 * for the three timers, and the file on disk for the ones a backup meets.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	VISIBLE_ERROR_NOTICE,
	BACKUP_URL,
	OPTIONS_URL,
	backupFiles,
	clearBackups,
	installMailInterceptor,
	lastMail,
	logInAs,
	resetPlugin,
	setting,
	wpEval,
} = require( './helpers.js' );

/**
 * Open the Settings screen.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once the screen is up.
 */
async function openSettings( page ) {
	await page.goto( OPTIONS_URL );

	await expect( page.getByRole( 'heading', { name: 'Database Settings' } ) ).toBeVisible();
}

/**
 * Save the settings form and wait for the confirmation.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once "Settings saved." is on screen.
 */
async function saveSettings( page ) {
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();

	// The notice rather than the redirect: options.php sends the browser back
	// whether or not anything was written, so arriving here again says nothing.
	// It is also the specific guard the screen's own comment describes -- passing
	// the option name to settings_errors() would show the validation errors and
	// swallow this confirmation, leaving a screen that saves in silence.
	await expect( page.locator( '.settings-error, .notice-success' ).first() ).toContainText(
		'Settings saved.',
	);
}

/**
 * When one of the plugin's three jobs is next due, as a Unix timestamp.
 *
 * @param {string} hook Cron hook name.
 * @return {number} Timestamp, or 0 when nothing is scheduled.
 */
function nextRun( hook ) {
	return parseInt(
		wpEval( `echo '<<<' . (int) wp_next_scheduled( '${ hook }' ) . '>>>';` ),
		10,
	);
}

/**
 * The interval, in seconds, of the schedule one job is attached to.
 *
 * @param {string} hook Cron hook name.
 * @return {number} Seconds, or 0 when nothing is scheduled.
 */
function scheduleInterval( hook ) {
	return parseInt(
		wpEval(
			`$event = wp_get_scheduled_event( '${ hook }' );
			$schedules = wp_get_schedules();
			echo '<<<' . ( $event && isset( $schedules[ $event->schedule ] ) ? (int) $schedules[ $event->schedule ]['interval'] : 0 ) . '>>>';`,
		),
		10,
	);
}

test.describe( 'Database options', () => {
	test.beforeAll( async () => {
		installMailInterceptor();
	} );

	test.beforeEach( async () => {
		resetPlugin();
		clearBackups();
	} );

	test.afterAll( async () => {
		clearBackups();
		resetPlugin();
	} );

	test( 'the fixture really is one settings screen holding every section', async ( { page } ) => {
		// The precondition: four sections registered against one page, which is
		// what "one settings page per plugin" looks like when it is working.
		await openSettings( page );

		for ( const heading of [
			'Paths',
			'Automatic Scheduling',
			'Backup Email Options',
			'Miscellaneous Options',
		] ) {
			await expect( page.getByRole( 'heading', { name: heading } ), heading ).toHaveCount( 1 );
		}

		await expect( page.locator( '#db_mysqldumppath' ) ).toBeVisible();
		await expect( page.locator( '#db_backup' ) ).toBeVisible();
	} );

	test( 'the two binary paths save, and Auto Detect fills them in', async ( { page } ) => {
		await openSettings( page );

		await page.locator( '#db_mysqldumppath' ).fill( '/usr/bin/mysqldump' );
		await page.locator( '#db_mysqlpath' ).fill( '/usr/bin/mysql' );
		await saveSettings( page );

		expect( setting( 'mysqldumppath' ) ).toBe( '/usr/bin/mysqldump' );
		expect( setting( 'mysqlpath' ) ).toBe( '/usr/bin/mysql' );

		// Auto Detect is client-side: the server shells out to `which` once, when
		// the screen is built, and localises the answer. Pressing the button fills
		// the field from that and saves nothing, which is both halves of what it
		// has to do.
		//
		// Asserted against the localised answer rather than against a literal
		// path. What `which` finds is a fact about the host -- wp-env's WordPress
		// image has no mysql client at all -- and the button's job is to put the
		// server's answer in the box, whatever that answer is.
		const detected = await page.evaluate( () => window.wpDBManagerL10n.mysqldump );

		await page.locator( '#db_mysqldumppath' ).fill( 'nonsense' );
		await page.locator( '[data-dbmanager-detect="mysqldump"]' ).click();

		await expect( page.locator( '#db_mysqldumppath' ) ).toHaveValue( detected );
		expect( setting( 'mysqldumppath' ) ).toBe( '/usr/bin/mysqldump' );
	} );

	test( 'a backup path that does not exist is refused, complained about and reverted', async ( {
		page,
	} ) => {
		const before = setting( 'path' );

		await openSettings( page );

		await page.locator( 'input[name="wp_dbmanager_options[path]"]' ).fill( '/nowhere/at/all' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		// The notice matters as much as the revert: without it the screen would
		// silently put a deliberate setting back and give no reason.
		await expect( page.locator( '.settings-error, .notice-error' ).first() ).toContainText(
			'is not a valid backup path',
		);
		expect( setting( 'path' ) ).toBe( before );
	} );

	test( 'the backup folder decides where a backup is written', async ( { page } ) => {
		const moved = wpEval(
			`$path = WP_CONTENT_DIR . '/dbmanager-e2e-backups';
			wp_mkdir_p( $path );
			echo '<<<' . $path . '>>>';`,
		);

		await openSettings( page );
		await page.locator( 'input[name="wp_dbmanager_options[path]"]' ).fill( moved );
		await saveSettings( page );

		expect( setting( 'path' ) ).toBe( moved );

		// Taken over WP-CLI rather than from the screen: wp-env's WordPress image
		// carries no mysql client, so a dump only runs in the process that has
		// one. It is the plugin's own backup() either way -- see backups.spec.js.
		expect(
			JSON.parse( wpEval( `echo '<<<' . wp_json_encode( WP_DBManager_Database::backup( false ) ) . '>>>';` ) )
				.success,
		).toBe( true );

		// The far end is a file in the new folder rather than in the old one.
		expect( backupFiles() ).toHaveLength( 1 );

		clearBackups();
		wpEval( `rmdir( '${ moved }' ); echo '<<<done>>>';` );
	} );

	test( 'each schedule saves and is what the cron event is actually set to', async ( {
		page,
	} ) => {
		await openSettings( page );

		await page.locator( '#db_backup' ).fill( '2' );
		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '86400' );
		await page.locator( '#db_optimize' ).fill( '3' );
		await page.locator( 'select[name="wp_dbmanager_options[optimize_period]"]' ).selectOption( '3600' );
		await page.locator( '#db_repair' ).fill( '1' );
		await page.locator( 'select[name="wp_dbmanager_options[repair_period]"]' ).selectOption( '604800' );
		await saveSettings( page );

		expect( setting( 'backup_period' ) ).toBe( '86400' );

		// The far end is the scheduled event, not the option. The schedule follows
		// the option rather than the form, so that WP-CLI and any other writer get
		// the same treatment -- and an interval that saved without rescheduling is
		// a backup that never happens.
		expect( scheduleInterval( 'wp_dbmanager_cron_backup' ) ).toBe( 2 * 86400 );
		expect( scheduleInterval( 'wp_dbmanager_cron_optimize' ) ).toBe( 3 * 3600 );
		expect( scheduleInterval( 'wp_dbmanager_cron_repair' ) ).toBe( 1 * 604800 );

		await openSettings( page );
		await expect( page.locator( '#wpbody-content' ) ).not.toContainText( 'Next run: N/A' );
	} );

	test( 'setting a period to Disable takes the event off the schedule', async ( { page } ) => {
		await openSettings( page );
		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '86400' );
		await saveSettings( page );

		expect( nextRun( 'wp_dbmanager_cron_backup' ) ).toBeGreaterThan( 0 );

		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '0' );
		await saveSettings( page );

		// Both directions in one test: "nothing is scheduled" passes on a site
		// where scheduling never worked at all.
		expect( nextRun( 'wp_dbmanager_cron_backup' ) ).toBe( 0 );
	} );

	test( 'the scheduled backup writes a file and e-mails it where it is told', async ( {
		page,
	} ) => {
		await openSettings( page );

		await page.locator( '#db_backup' ).fill( '1' );
		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '86400' );
		await page.locator( '#db_backup_gzip' ).selectOption( '0' );
		await page.locator( 'input[name="wp_dbmanager_options[backup_email]"]' ).fill( 'nightly@example.com' );
		await page.locator( '#db_backup_email_attach-no' ).check();
		await saveSettings( page );

		// Running the job rather than waiting a day for cron. The job is what the
		// schedule fires, so this is the same code path an unattended backup takes.
		wpEval( `WP_DBManager_Cron::backup(); echo '<<<done>>>';` );

		expect( backupFiles() ).toHaveLength( 1 );

		// lastMail(), which reads the option the interceptor this file already
		// installs actually writes. These two tests hand-rolled a read of
		// `wp_dbmanager_e2e_last_mail`, a row nothing in the suite has ever
		// written -- installMailInterceptor() stores to `e2e_intercepted_mail` --
		// so `mail` was always null and both died on the first property access.
		const mail = lastMail();

		expect( mail.to ).toBe( 'nightly@example.com' );
		expect( mail.message ).toContain( 'Backup File MD5 Checksum:' );

		// "No" means the details without the dump, which is the whole point of the
		// setting: the body alone is enough to confirm the backup ran.
		expect( mail.attachments ).toEqual( [] );
	} );

	test( 'the subject template is what the backup e-mail is sent with', async ( { page } ) => {
		await openSettings( page );

		await page
			.locator( 'input[name="wp_dbmanager_options[backup_email_subject]"]' )
			.fill( 'Nightly dump of %SITE_NAME% on %POST_DATE%' );
		await page.locator( 'input[name="wp_dbmanager_options[backup_email]"]' ).fill( 'nightly@example.com' );
		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '86400' );
		await saveSettings( page );

		wpEval( `WP_DBManager_Cron::backup(); echo '<<<done>>>';` );

		// lastMail(), which reads the option the interceptor this file already
		// installs actually writes. These two tests hand-rolled a read of
		// `wp_dbmanager_e2e_last_mail`, a row nothing in the suite has ever
		// written -- installMailInterceptor() stores to `e2e_intercepted_mail` --
		// so `mail` was always null and both died on the first property access.
		const mail = lastMail();

		expect( mail.subject ).toContain( 'Nightly dump of Test Blog on' );
		expect( mail.subject ).not.toContain( '%SITE_NAME%' );
		expect( mail.subject ).not.toContain( '%POST_DATE%' );
	} );

	test( 'the gzip default is what an unattended backup uses', async ( { page } ) => {
		await openSettings( page );
		await page.locator( '#db_backup_gzip' ).selectOption( '1' );
		await page.locator( 'select[name="wp_dbmanager_options[backup_period]"]' ).selectOption( '86400' );
		await saveSettings( page );

		expect( setting( 'backup_gzip' ) ).toBe( '1' );

		// The scheduled job, run on demand rather than waited a day for. It is
		// what the schedule fires, so this is the code path an unattended backup
		// takes -- including reading the gzip choice out of the settings.
		wpEval( `WP_DBManager_Cron::backup(); echo '<<<done>>>';` );

		expect( backupFiles()[ 0 ] ).toMatch( /\.sql\.gz$/ );

		// And it is what the Backup screen's radio starts on, so the two agree.
		await page.goto( BACKUP_URL );
		await expect( page.locator( '#gzip-yes' ) ).toBeChecked();
	} );

	test( 'hiding the admin notices takes the warning off every screen', async ( { page } ) => {
		// Take the index.php out of the backup folder, which is one of the two
		// things the notice exists to complain about. Done this way rather than by
		// pointing the plugin somewhere unwritable, because the settings screen
		// refuses an invalid path on save and the test would then be about that.
		wpEval(
			`wp_delete_file( WP_DBManager_Options::backup_path() . '/index.php' );
			echo '<<<done>>>';`,
		);

		await page.goto( '/wp-admin/index.php' );
		// The error notices an administrator can actually see. The Dashboard
		// also carries core's community-events widget, whose
		// .notice.notice-error.community-events-errors is markup its script
		// reveals on failure and is aria-hidden until then -- so the bare
		// selector matched two elements and died of strict mode.
		await expect( page.locator( VISIBLE_ERROR_NOTICE ) ).toContainText(
			'To correct this issue, move the file',
		);

		await openSettings( page );
		await page.locator( '#db_hide_admin_notices-yes' ).check();
		await saveSettings( page );

		expect( setting( 'hide_admin_notices' ) ).toBe( '1' );

		await page.goto( '/wp-admin/index.php' );
		await expect(
			page.locator( '#wpbody-content' ).getByText( 'To correct this issue, move the file' ),
		).toHaveCount( 0 );

		// Put the folder back the way it was, notice and all: the next test in
		// this file starts from a folder the plugin is happy with.
		wpEval( `WP_DBManager_Folder::create(); echo '<<<done>>>';` );
	} );

	test( 'the notice offers a fix, and the fix puts the guard files back', async ( { page } ) => {
		// The link is nonced and does real work -- it recreates the folder and
		// copies the guards in -- so it is the one thing on the dashboard that has
		// to be followed rather than read.
		wpEval(
			`$path = WP_DBManager_Options::backup_path();
			wp_delete_file( $path . '/index.php' );
			wp_delete_file( $path . '/.htaccess' );
			WP_DBManager_Folder::flush();
			echo '<<<' . ( is_file( $path . '/index.php' ) ? 'still there' : 'gone' ) . '>>>';`,
		);

		await page.goto( '/wp-admin/index.php' );

		const fix = page.locator( '#wpbody-content .notice-error' ).getByRole( 'link', {
			name: 'Click here',
		} );

		await expect( fix ).toHaveCount( 1 );

		await fix.click();

		// The far end is the filesystem, not the screen it lands on.
		expect(
			wpEval(
				`$path = WP_DBManager_Options::backup_path();
				echo '<<<' . ( is_file( $path . '/index.php' ) ? 'restored' : 'missing' ) . '>>>';`,
			),
		).toBe( 'restored' );

		await page.goto( '/wp-admin/index.php' );
		await expect(
			page.locator( '#wpbody-content' ).getByText( 'To correct this issue, move the file' ),
		).toHaveCount( 0 );
	} );

	test( 'the Settings screen is shut to an editor and open to an admin', async ( {
		page,
		requestUtils,
	} ) => {
		// install_plugins rather than manage_options, because these screens
		// restore, empty and drop tables -- so an editor, who may edit anything on
		// the site, must still be nowhere near them.
		const editor = await logInAs( page, requestUtils, 'dbm_editor', 'editor' );

		await editor.page.goto( '/wp-admin/index.php' );
		await expect( editor.page.locator( '#adminmenu' ).getByText( 'WP-DBManager' ) ).toHaveCount(
			0,
		);

		await editor.page.goto( OPTIONS_URL );
		await expect( editor.page.locator( 'body' ) ).toContainText(
			/not allowed to access this page|Access Denied/,
		);

		await page.goto( OPTIONS_URL );
		await expect( page.getByRole( 'heading', { name: 'Database Settings' } ) ).toBeVisible();

		await editor.context.close();
	} );
} );
