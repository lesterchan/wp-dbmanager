/**
 * The pre-4.0.0 migration, run the way a real site runs it.
 *
 * Activation does not fire when a plugin is merely updated -- a site that
 * updates from the Plugins screen never calls activate() -- so maybe_upgrade()
 * runs from add_hooks() on every request instead. Loading a page in a browser
 * is the only way to reach it, and it is the path every real update takes.
 *
 * What that path carries which WP-CLI does not:
 *
 *   * Cron. The three events were renamed, an event cannot be renamed in place,
 *     and a site that came through the upgrade with three orphaned events is a
 *     site with no scheduled backups at all. WP_DBManager_Cron::init() has to
 *     have registered the recurrences before the migration reschedules against
 *     them, and that ordering only exists in a real request.
 *   * The settings screen on the far side, reading the row the migration wrote.
 *
 * Every row here is read *raw*. WP_DBManager_Options::get() merges over the
 * defaults, so it answers identically for a row holding the defaults and for no
 * row at all -- and that is precisely the state §7.6.1 describes, a row read,
 * deleted and never written. Asking the plugin what it sees is how that hides;
 * asking the database is how it does not.
 *
 * The fixture is therefore the shipped settings rather than a customised row.
 * A customised row's migrated result differs from the defaults, so its write
 * lands whatever the read before it did -- which is exactly why the migration
 * test that existed before tests/test-options.php gained its stock fixture
 * passed throughout the defect.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	DASHBOARD_URL,
	LEGACY_OPTION,
	OPTIONS_URL,
	defaultOptions,
	ensurePluginActive,
	installLegacyRow,
	legacyRow,
	rawOptions,
	reactivatePlugin,
	resetPlugin,
	runningVersions,
	scheduledCron,
	setVersionRow,
	versionRow,
	wpEval,
} = require( './helpers.js' );

/** The three events under their current names. */
const JOBS = [ 'wp_dbmanager_cron_backup', 'wp_dbmanager_cron_optimize', 'wp_dbmanager_cron_repair' ];

/** The same three as every release up to 3.0.0 scheduled them. */
const LEGACY_JOBS = [ 'dbmanager_cron_backup', 'dbmanager_cron_optimize', 'dbmanager_cron_repair' ];

test.describe( 'The pre-4.0.0 upgrade', () => {
	test.afterEach( async () => {
		// Back to a fresh install's settings, with the detected binary paths --
		// the state every other spec in this suite starts from, and which this
		// one deliberately takes apart.
		wpEval( `delete_option( '${ LEGACY_OPTION }' ); echo '<<<done>>>';` );
		setVersionRow( runningVersions() );
		resetPlugin();

		// This is the only file that ever switches the plugin off, and only for
		// as long as it takes to prove the activation hook runs. A failure part
		// way through would otherwise hand every later test a site with no
		// plugin, and the run would report a screenful of symptoms of one cause.
		ensurePluginActive();
	} );

	test( 'a stock 3.0.0 row is folded in, written, deleted and stamped', async ( { page } ) => {
		const defaults = defaultOptions();

		// The commonest install of all: never changed a setting. Its migrated
		// result is byte for byte what the defaults would have answered anyway,
		// which is the one shape where a skipped write leaves no trace.
		//
		// The fixture is asserted from what the seeding call itself saw, not
		// from a second one. maybe_upgrade() runs from add_hooks(), so a WP-CLI
		// request performs the upgrade too -- ask again through another
		// `wp eval` and the rows have already moved, the browser request below
		// has nothing left to do, and the test quietly becomes a test of WP-CLI.
		const before = installLegacyRow( defaults );

		expect( before.legacy ).not.toBe( false );
		expect( before.options ).toBe( false );
		expect( before.version ).toBe( false );

		await page.goto( DASHBOARD_URL );

		// The old row is gone rather than left to rot, so re-running finds
		// nothing to do and a later release has one row to think about.
		expect( legacyRow() ).toBe( false );

		// And the row it was folded into is genuinely on disk, values and all.
		const stored = rawOptions();

		expect( stored ).not.toBe( false );
		expect( stored.max_backup ).toBe( defaults.max_backup );
		expect( stored.backup_period ).toBe( defaults.backup_period );

		// One write, both markers, matching the code that is running.
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( "a customised row keeps this site's settings, and they reach the screen", async ( {
		page,
	} ) => {
		installLegacyRow( {
			...defaultOptions(),
			max_backup: 4,
			backup_email: 'backups@example.com',
			backup_period: 86400,
		} );

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		expect( stored.max_backup ).toBe( 4 );
		expect( stored.backup_email ).toBe( 'backups@example.com' );

		// Present is not alive. The migrated settings have to be the ones the
		// screen shows and the plugin acts on -- a row in the right place that
		// nothing reads is a migration that passed and a plugin that broke.
		await page.goto( OPTIONS_URL );

		await expect(
			page.locator( 'input[name="wp_dbmanager_options[max_backup]"]' ),
		).toHaveValue( '4' );
		await expect(
			page.locator( 'input[name="wp_dbmanager_options[backup_email]"]' ),
		).toHaveValue( 'backups@example.com' );
	} );

	test( 'the three renamed cron events are cleared and rescheduled', async ( { page } ) => {
		// Rows and events together, in one WP-CLI call, because that call is
		// itself an upgrade request: maybe_upgrade() runs from add_hooks(), so
		// anything left for a second `wp eval` to set up would be set up on the
		// far side of a migration that had already happened.
		//
		// Three events under the names 3.0.0 used, which nothing in 4.0.0
		// listens to. Left alone they would fire forever into a hook with no
		// callback while the site had no scheduled backup at all.
		const before = installLegacyRow( defaultOptions(), { legacyCron: true } );

		for ( const hook of LEGACY_JOBS ) {
			expect( before.cron[ hook ] ).toBe( true );
		}
		for ( const hook of JOBS ) {
			expect( before.cron[ hook ] ).toBe( false );
		}

		await page.goto( DASHBOARD_URL );

		const after = scheduledCron();

		for ( const hook of LEGACY_JOBS ) {
			expect( after[ hook ] ).toBe( false );
		}

		// Rescheduled rather than merely cleared, and against a recurrence that
		// exists: wp_schedule_event() refuses an interval nothing registered, so
		// this is also the assertion that Cron::init() ran before the migration
		// did. The default settings enable all three.
		for ( const hook of JOBS ) {
			expect( after[ hook ] ).toBe( true );
		}
	} );

	test( 'a settings row already in the new name is never overwritten', async ( { page } ) => {
		// The shape an install lands in when it saved something through the new
		// screen and only then met the migration -- a partial restore, a
		// downgrade and re-upgrade. The newer row is the one the owner has
		// actually seen, so the older one is folded away, not in.
		//
		// Both rows in one call, for the reason the stock test gives: a second
		// `wp eval` would have migrated them before writing anything.
		const before = installLegacyRow(
			{ ...defaultOptions(), max_backup: 4 },
			{ current: { ...defaultOptions(), max_backup: 9 } },
		);

		expect( before.legacy.max_backup ).toBe( 4 );
		expect( before.options.max_backup ).toBe( 9 );
		expect( before.version ).toBe( false );

		await page.goto( DASHBOARD_URL );

		expect( legacyRow() ).toBe( false );
		expect( rawOptions().max_backup ).toBe( 9 );
	} );

	test( 'a second admin load, and a reactivation after it, change nothing', async ( { page } ) => {
		installLegacyRow( { ...defaultOptions(), max_backup: 6 } );

		await page.goto( DASHBOARD_URL );

		const once = { options: rawOptions(), versions: versionRow() };

		expect( once.options ).not.toBe( false );
		expect( once.options.max_backup ).toBe( 6 );
		expect( once.versions ).toEqual( runningVersions() );

		// Every request after the first has to be a bystander -- the rows it
		// finds are the rows it leaves -- which for this plugin is the whole
		// reason the markers are checked before anything else happens: without
		// that gate, maybe_upgrade() runs on *every* page load of the site.
		await page.goto( DASHBOARD_URL );

		expect( rawOptions() ).toEqual( once.options );
		expect( versionRow() ).toEqual( once.versions );

		// And so does the other entry point. Reactivating cannot be isolated
		// here the way it can in a plugin that migrates on admin_init -- WP-CLI
		// boots the plugin before it runs anything, so the upgrade has already
		// happened by the time activate_plugin() is called -- so what this
		// asserts is the half that is testable: activation over an
		// already-migrated install leaves it exactly as it was.
		reactivatePlugin();

		expect( rawOptions() ).toEqual( once.options );
		expect( versionRow() ).toEqual( once.versions );
	} );

	test( 'an install already on this version is left alone', async ( { page } ) => {
		// A legacy row that should never be read, alongside markers saying the
		// upgrade has already happened. maybe_upgrade() returning early is what
		// keeps every request from being an option write, and the proof it
		// returned early is that this row survives untouched.
		const data = Buffer.from(
			JSON.stringify( { ...defaultOptions(), max_backup: 3 } ),
			'utf8',
		).toString( 'base64' );

		wpEval(
			`update_option( '${ LEGACY_OPTION }', json_decode( base64_decode( '${ data }' ), true ) );
			echo '<<<done>>>';`,
		);

		setVersionRow( runningVersions() );

		await page.goto( DASHBOARD_URL );

		const legacy = legacyRow();

		expect( legacy ).not.toBe( false );
		expect( legacy.max_backup ).toBe( 3 );
	} );
} );
