/**
 * Taking a backup, and doing something with the ones already there.
 *
 * The backups here are real dumps: mysqldump is run, and the file it wrote is
 * read back to see whether it holds the tables it claims to. A fake backup would
 * prove nothing -- the one question this plugin exists to answer is whether the
 * file it just wrote could be restored, and the plugin has been wrong about that
 * before, when a failed dump piped through gzip produced a valid twenty-byte
 * file and an exit status of zero.
 *
 * Where they are taken from needs saying. wp-env's WordPress image carries no
 * mysql client at all, while its WP-CLI image does, so the dump is produced
 * through WP_DBManager_Database::backup() over WP-CLI -- the plugin's own code,
 * in a process that has the binary -- and the browser drives everything the
 * screens are for: the status checks, the listing, the download, the e-mail and
 * the deletion. The Backup screen's own button is still pressed, and what is
 * asserted there is the property that matters in both kinds of environment: the
 * screen never reports a backup it did not take.
 *
 * Restoring is the one operation not run at all. It overwrites the entire
 * database from a dump, in the environment PHPUnit shares, and a restore that
 * half worked would not fail loudly here -- it would leave the next suite
 * mysteriously broken. What can be checked without running it is: the command
 * the plugin builds, and its refusal to restore more than one file at a time.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	BACKUP_URL,
	MANAGE_URL,
	backupFiles,
	bulkAction,
	clearBackups,
	installMailInterceptor,
	lastMail,
	listRow,
	resetMail,
	resetPlugin,
	setSetting,
	wpEval,
	writeFakeBackup,
} = require( './helpers.js' );

/**
 * Take a backup through the plugin's own code, over WP-CLI.
 *
 * @param {boolean} gzip Whether to compress it.
 * @return {Object} The result array WP_DBManager_Database::backup() returns.
 */
function takeBackup( gzip ) {
	return JSON.parse(
		wpEval(
			`$result = WP_DBManager_Database::backup( ${ gzip ? 'true' : 'false' } );
			echo '<<<' . wp_json_encode( $result ) . '>>>';`,
		),
	);
}

test.describe( 'Backing the database up', () => {
	test.beforeAll( async () => {
		installMailInterceptor();
	} );

	test.beforeEach( async () => {
		resetPlugin();
		clearBackups();
		resetMail();
	} );

	test.afterAll( async () => {
		clearBackups();
		resetPlugin();
	} );

	test( 'the fixture really is a writable backup folder the plugin is happy with', async ( {
		page,
	} ) => {
		// The precondition every test in this file leans on, and a test of the
		// status checks themselves: the Backup screen refuses to do anything
		// useful without a writable folder, and it says so on screen rather than
		// failing silently.
		expect( backupFiles() ).toHaveLength( 0 );

		await page.goto( BACKUP_URL );

		await expect( page.getByRole( 'heading', { name: 'Backup Database' } ).first() ).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Backup folder exists' );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Backup folder is writable' );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'index.php is present in' );

		// The three shell functions the whole plugin rests on. A host that has
		// disabled them gets told outright rather than discovering it when a
		// scheduled backup quietly stops happening.
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'passthru() enabled.' );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'system() enabled.' );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'exec() enabled.' );
	} );

	test( 'a backup is a real dump of this database, and its name carries its own md5', async () => {
		const result = takeBackup( false );

		expect( result.success ).toBe( true );

		const files = backupFiles();

		expect( files ).toHaveLength( 1 );

		// The md5 is prepended after the dump is written, so the file name cannot
		// be guessed by anyone who knows the date -- which is the whole reason the
		// rename exists.
		expect( files[ 0 ] ).toMatch( /^[0-9a-f]{32}_-_\d+_-_.+\.sql$/ );

		// And the contents are the far end. An empty dump looks like a backup
		// right up until somebody needs it.
		const head = wpEval(
			`$path = WP_DBManager_Options::backup_path() . '/${ files[ 0 ] }';
			echo '<<<' . ( is_file( $path ) ? substr( file_get_contents( $path ), 0, 4000 ) : 'missing' ) . '>>>';`,
		);

		expect( head ).toContain( 'CREATE TABLE' );
		expect( head ).toContain( 'DROP TABLE IF EXISTS' );
	} );

	test( 'a gzipped backup is compressed and still readable', async () => {
		const result = takeBackup( true );

		expect( result.success ).toBe( true );

		const [ zipped ] = backupFiles();

		expect( zipped ).toMatch( /\.sql\.gz$/ );

		// Read back through gzip rather than trusted by its extension. A dump that
		// failed and was piped into gzip produces a perfectly valid empty stream,
		// and the pipeline's exit status belongs to gzip rather than to mysqldump
		// -- which is exactly how this plugin used to e-mail out backups of
		// nothing at all.
		const unzipped = wpEval(
			`$path = WP_DBManager_Options::backup_path() . '/${ zipped }';
			$handle = gzopen( $path, 'rb' );
			echo '<<<' . ( false === $handle ? 'unreadable' : substr( (string) gzread( $handle, 4000 ), 0, 4000 ) ) . '>>>';`,
		);

		expect( unzipped ).toContain( 'CREATE TABLE' );
	} );

	test( 'the Backup screen never reports a backup it did not take', async ( { page } ) => {
		await page.goto( BACKUP_URL );

		// Which outcome is right depends on the host, and the screen's own status
		// block is what says which: wp-env's WordPress image ships without a mysql
		// client, so there the dump cannot run at all. Both outcomes are asserted,
		// because the property under test is that the two agree -- a screen that
		// says "Backed Up Successfully" over an empty folder is the failure this
		// plugin has actually shipped.
		const ready = ( await page.locator( '#wpbody-content' ).innerText() ).includes(
			'Excellent. You Are Good To Go.',
		);

		await page.locator( '#gzip-no' ).check();
		await page.getByRole( 'button', { name: 'Backup', exact: true } ).click();

		// Scoped rather than bare: the status block above the form is a column of
		// inline notices of its own, so '.notice-error' matches several of them.
		if ( ready ) {
			await expect(
				page.locator( '.notice-success' ).filter( { hasText: 'Database Backed Up' } ),
			).toHaveCount( 1 );
			expect( backupFiles() ).toHaveLength( 1 );
		} else {
			await expect(
				page.locator( '.notice-error' ).filter( { hasText: 'Database Failed To Backup' } ),
			).toHaveCount( 1 );

			// And nothing was left behind pretending to be one.
			expect( backupFiles() ).toHaveLength( 0 );
		}
	} );

	test( 'the maximum keeps the newest backups and drops the oldest', async () => {
		// Written directly rather than dumped, because this is about the pruning
		// arithmetic and three real dumps would cost seconds each to prove the
		// same thing. The mtimes are explicit: two files written in the same
		// second break the "oldest first" sort on their names instead.
		const now = Math.floor( Date.now() / 1000 );
		const oldest = writeFakeBackup( now - 3000 );
		const middle = writeFakeBackup( now - 2000 );

		writeFakeBackup( now - 1000 );

		setSetting( 'max_backup', '3' );

		expect( backupFiles() ).toHaveLength( 3 );

		expect( takeBackup( false ).success ).toBe( true );

		const files = backupFiles();

		// Three, not four: pruning leaves room for the one about to be written,
		// which is why the comparison is >= rather than >.
		expect( files ).toHaveLength( 3 );
		expect( files ).not.toContain( oldest );
		expect( files ).toContain( middle );
	} );

	test( 'a maximum below one is read as no limit at all', async () => {
		// Deleting every backup a site has is never what somebody meant by
		// "maximum 0", and the guard for that is one comparison in prune().
		writeFakeBackup( Math.floor( Date.now() / 1000 ) - 100 );
		setSetting( 'max_backup', '0' );

		expect( takeBackup( false ).success ).toBe( true );
		expect( backupFiles() ).toHaveLength( 2 );
	} );

	test( 'the Manage screen lists what is on disk with its size and checksum', async ( {
		page,
	} ) => {
		const name = writeFakeBackup( Math.floor( Date.now() / 1000 ), '-- twenty eight bytes here\n' );

		await page.goto( MANAGE_URL );

		await expect( page.getByRole( 'heading', { name: 'Manage Backup Database' } ) ).toBeVisible();

		const [ checksum, , database ] = name.split( '_-_' );
		const row = listRow( page, database );

		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( checksum );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( '1 backup file,' );
	} );

	test( 'an empty backup folder says so rather than showing an empty table', async ( {
		page,
	} ) => {
		await page.goto( MANAGE_URL );

		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			'There Are No Database Backup Files Available.',
		);
		await expect( page.locator( '#wpbody-content' ) ).toContainText( '0 backup files,' );
	} );

	test( 'deleting a backup takes the file, and only once the confirm is accepted', async ( {
		page,
	} ) => {
		const name = writeFakeBackup( Math.floor( Date.now() / 1000 ) );

		await page.goto( MANAGE_URL );

		// Dismissed first: deleting a backup cannot be undone, and a confirm that
		// deleted anyway would be worse than no confirm at all.
		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await bulkAction( page, `input[value="${ name }"]`, 'delete' );
		expect( backupFiles() ).toContain( name );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await bulkAction( page, `input[value="${ name }"]`, 'delete' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Deleted Successfully' );
		expect( backupFiles() ).not.toContain( name );
	} );

	test( 'downloading a backup sends the file itself', async ( { page } ) => {
		const name = writeFakeBackup( Math.floor( Date.now() / 1000 ), '-- the download body\n' );

		await page.goto( MANAGE_URL );

		await page.locator( `input[value="${ name }"]` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'download' );

		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			page.locator( '#doaction' ).click(),
		] );

		// The far end is the bytes, not the header: the endpoint resolves the
		// submitted name inside the backup folder and streams the file, and a
		// download that sent the wrong file would still be a download.
		expect( download.suggestedFilename() ).toBe( name );

		const stream = await download.createReadStream();
		const chunks = [];

		for await ( const chunk of stream ) {
			chunks.push( chunk );
		}

		expect( Buffer.concat( chunks ).toString() ).toContain( '-- the download body' );
	} );

	test( 'e-mailing a backup sends it to the address on the screen', async ( { page } ) => {
		const name = writeFakeBackup( Math.floor( Date.now() / 1000 ) );

		await page.goto( MANAGE_URL );

		await page.locator( '#email_to' ).fill( 'archive@example.com' );
		await bulkAction( page, `input[value="${ name }"]`, 'email' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Successfully E-Mailed To' );

		// Intercepted at pre_wp_mail, so nothing leaves the machine. The far end
		// is the message the plugin built: the right recipient, and the dump
		// itself attached.
		const mail = lastMail();

		expect( mail.to ).toBe( 'archive@example.com' );
		expect( mail.subject ).toContain( 'Database Backup File For' );
		expect( mail.message ).toContain( name );
		expect( JSON.stringify( mail.attachments ) ).toContain( name );
	} );

	test( 'restore and download refuse a selection of more than one', async ( { page } ) => {
		// Restoring overwrites the whole database and downloading sends one file,
		// so neither has a sensible meaning for a multiple selection. Refusing is
		// better than silently picking one of them -- and refusing is also what
		// keeps this test from restoring anything.
		const now = Math.floor( Date.now() / 1000 );
		const first = writeFakeBackup( now - 100 );
		const second = writeFakeBackup( now );

		await page.goto( MANAGE_URL );

		await page.locator( `input[value="${ first }"]` ).check();
		await page.locator( `input[value="${ second }"]` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'restore' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.locator( '#doaction' ).click();

		await expect( page.locator( '.notice-error' ) ).toContainText(
			'Please Select Only One Backup Database File',
		);
		expect( backupFiles() ).toHaveLength( 2 );
	} );

	test( 'the restore command feeds the chosen dump to the mysql client', async () => {
		// Built and inspected rather than run. Running it would overwrite the
		// database this suite and PHPUnit share, and a restore that half worked
		// would not fail here -- it would break whatever ran next. What can be
		// checked is that the command names the right client and the right file,
		// and that a gzipped dump is piped through gunzip rather than handed to
		// mysql as it stands.
		const plain = wpEval(
			`echo '<<<' . WP_DBManager_Database::restore_command( '/tmp/example.sql', false ) . '>>>';`,
		);

		expect( plain ).toContain( 'mysql' );
		expect( plain ).toContain( "< '/tmp/example.sql'" );
		expect( plain ).not.toContain( 'gunzip' );

		const zipped = wpEval(
			`echo '<<<' . WP_DBManager_Database::restore_command( '/tmp/example.sql.gz', false ) . '>>>';`,
		);

		expect( zipped ).toContain( "gunzip < '/tmp/example.sql.gz' |" );

		// And the password never reaches the command line, where `ps` would show
		// it to every other user on the host.
		expect( plain ).not.toContain( '--password=' );
		expect( plain ).toContain( '--defaults-extra-file=' );
	} );

	test( 'a backup file name that climbs out of the folder is refused', async ( { page } ) => {
		// The name arrives from a checkbox value, so it is whatever the request
		// says it is. resolve() is what stands between that and readfile().
		writeFakeBackup( Math.floor( Date.now() / 1000 ) );

		await page.goto( MANAGE_URL );

		const answer = await page.evaluate( async () => {
			const form = document.querySelector( 'form[data-dbmanager-confirm-actions]' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'download' );
			body.append( 'backups[]', '../../../wp-config.php' );

			// getAttribute(), not form.action: the bulk dropdown is named
			// "action", and a control's name shadows the property of the same
			// name on the form -- so form.action is that <select> rather than the
			// URL, and fetch() would post to a stringified DOM node.
			const response = await fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return response.text();
		} );

		expect( answer ).not.toContain( 'DB_PASSWORD' );
		expect( answer ).toContain( 'Invalid Database Backup File' );
	} );
} );
