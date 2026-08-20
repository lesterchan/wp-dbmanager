/**
 * The four screens that act on tables, and the SQL console.
 *
 * Every destructive test here works on one scratch table this suite creates and
 * drops. Nothing empties or drops a WordPress table: the tests environment is
 * shared with PHPUnit, and a truncated wp_options would not fail loudly, it
 * would leave whatever ran next mysteriously broken. Optimize and Repair are
 * safe on any table and are still pointed at the scratch one, so a failure names
 * something this file made.
 *
 * The console is driven the same way. Its allow list is the interesting part --
 * INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER run; SELECT, DROP, SHOW and
 * GRANT do not; LOAD_FILE is refused outright -- and every statement used to
 * exercise it names the scratch table.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	EMPTY_URL,
	MANAGER_URL,
	OPTIMIZE_URL,
	REPAIR_URL,
	RUN_URL,
	bulkAction,
	createScratchTable,
	dropScratchTable,
	listRow,
	resetPlugin,
	scratchState,
	wpEval,
} = require( './helpers.js' );

/**
 * Type a query into the console and run it.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          query One or more statements, newline separated.
 * @return {Promise<void>} Resolves once the screen has come back.
 */
async function runQuery( page, query ) {
	await page.goto( RUN_URL );

	await page.locator( '#sql_query' ).fill( query );
	await page.getByRole( 'button', { name: 'Run', exact: true } ).click();

	await expect( page.getByRole( 'heading', { name: 'Run SQL Query' } ) ).toBeVisible();
}

test.describe( 'Acting on tables', () => {
	let table;

	test.beforeEach( async () => {
		resetPlugin();
		table = createScratchTable( 3 );
	} );

	test.afterEach( async () => {
		dropScratchTable();
	} );

	test( 'the fixture really is a table with rows in it that every screen lists', async ( {
		page,
	} ) => {
		// The precondition the rest of this file leans on. The plugin validates a
		// submitted table name against SHOW TABLES, so a scratch table that was
		// not really created would make every bulk action below report "No Tables
		// Selected" -- which is a pass for the wrong reason on half of them.
		const state = scratchState();

		expect( state.exists ).toBe( true );
		expect( state.rows ).toBe( 3 );

		for ( const url of [ MANAGER_URL, OPTIMIZE_URL, REPAIR_URL, EMPTY_URL ] ) {
			await page.goto( url );
			await expect( listRow( page, table ), url ).toHaveCount( 1 );
		}
	} );

	test( 'the information screen reports the tables and adds them up', async ( { page } ) => {
		await page.goto( MANAGER_URL );

		await expect( page.getByRole( 'heading', { name: 'Database Information' } ) ).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Database Version' );

		// The row count comes from SHOW TABLE STATUS, and the totals row under the
		// table is the whole reason this screen exists rather than a plain list.
		await expect( listRow( page, table ) ).toContainText( '3' );
		// The table as a whole. The comma selector was meant to say "in the body
		// or the foot, wherever the total is drawn", but Playwright reads it as
		// one locator matching two elements and refuses it under strict mode.
		await expect( page.locator( '.wp-list-table' ) ).toContainText( /\d+ Tables/ );

		// A screen that only reports has nothing to select, so it offers no
		// checkboxes at all.
		await expect( page.locator( '.wp-list-table input[name="tables[]"]' ) ).toHaveCount( 0 );
	} );

	test( 'optimizing the chosen table reports it and leaves the rows alone', async ( { page } ) => {
		await page.goto( OPTIMIZE_URL );

		await bulkAction( page, `input[value="${ table }"]`, 'optimize' );

		await expect( page.locator( '.notice-success' ) ).toContainText( `'${ table }' Optimized` );

		// The far end is the data: OPTIMIZE reclaims space and must not touch a
		// single row.
		expect( scratchState().rows ).toBe( 3 );
	} );

	test( 'repairing the chosen table reports it and leaves the rows alone', async ( { page } ) => {
		await page.goto( REPAIR_URL );

		await bulkAction( page, `input[value="${ table }"]`, 'repair' );

		await expect( page.locator( '.notice-success' ) ).toContainText( `'${ table }' Repaired` );
		expect( scratchState().rows ).toBe( 3 );
	} );

	test( 'a bulk action with no table ticked says so and changes nothing', async ( { page } ) => {
		await page.goto( OPTIMIZE_URL );

		// Posted without any tables[], which is the request that arrives when
		// core's own list-table script is not in the way. The plugin's guard is
		// what has to answer it.
		const answer = await page.evaluate( async () => {
			const form = document.querySelector( '#wpbody-content form' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'optimize' );

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

		expect( answer ).toContain( 'No Tables Selected' );
		expect( scratchState().rows ).toBe( 3 );
	} );

	test( 'a table name that is not a real table is dropped before any query is built', async ( {
		page,
	} ) => {
		// The names arrive as checkbox values, so they are whatever the request
		// says. They cannot be sanitized into safety -- only matched against the
		// real list -- and this is that check, asked with a name designed to break
		// out of the backticks the query builds.
		await page.goto( EMPTY_URL );

		const answer = await page.evaluate( async () => {
			const form = document.querySelector( '#wpbody-content form' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'drop' );
			body.append( 'tables[]', '`; DROP TABLE wp_posts; --' );
			body.append( 'tables[]', 'wp_not_a_real_table' );

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

		expect( answer ).toContain( 'No Tables Selected' );

		// And the table the injection named is still there.
		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) ) ? 'present' : 'gone' ) . '>>>';`,
			),
		).toBe( 'present' );
	} );

	test( 'emptying a table takes its rows and leaves the table, once confirmed', async ( {
		page,
	} ) => {
		await page.goto( EMPTY_URL );

		// Dismissed first. Emptying cannot be undone, and a confirm that emptied
		// anyway would be worse than no confirm at all.
		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await bulkAction( page, `input[value="${ table }"]`, 'empty' );
		expect( scratchState().rows ).toBe( 3 );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await bulkAction( page, `input[value="${ table }"]`, 'empty' );

		await expect( page.locator( '.notice-success' ) ).toContainText( `'${ table }' Emptied` );

		const state = scratchState();

		expect( state.exists ).toBe( true );
		expect( state.rows ).toBe( 0 );
	} );

	test( 'dropping a table takes the table itself, once confirmed', async ( { page } ) => {
		await page.goto( EMPTY_URL );

		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await bulkAction( page, `input[value="${ table }"]`, 'drop' );
		expect( scratchState().exists ).toBe( true );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await bulkAction( page, `input[value="${ table }"]`, 'drop' );

		await expect( page.locator( '.notice-success' ) ).toContainText( `'${ table }' Dropped` );
		expect( scratchState().exists ).toBe( false );
	} );

	test( 'the tables list sorts by the column headed', async ( { page } ) => {
		await page.goto( MANAGER_URL );

		// The name column, which is the one sort every screen shares. Ascending
		// first, then the link flips -- and the two ends of the list swap.
		// The whole column both ways round, not just its first cell, and no
		// assumption about which direction the first click gives. The screen
		// already arrives sorted by name, so core's column header offers the
		// *opposite* order first -- the old assertion read the first click as
		// ascending, got descending, and failed on a screen that was sorting
		// correctly.
		//
		// One order being the exact reverse of the other is the stronger claim
		// anyway: comparing two single cells passes for a list that is merely
		// shuffled.
		// Match the class on whichever element carries it, rather than on a td.
		// The name column is this screen's primary column, and WordPress 7.1 moved
		// the primary column out of a td and into a th scope="row", so that a
		// screen reader announces the row by its name rather than by "Select All".
		// A td selector finds nothing from 7.1 onwards; a th selector would find
		// nothing before it.
		const names = async () =>
			( await page.locator( '.wp-list-table tbody tr .column-name' ).allInnerTexts() ).map(
				( text ) => text.trim(),
			);

		await page.locator( 'thead #name a' ).click();
		const oneWay = await names();

		await page.locator( 'thead #name a' ).click();
		const theOther = await names();

		expect( oneWay.length ).toBeGreaterThan( 1 );
		expect( oneWay ).not.toEqual( theOther );
		expect( theOther ).toEqual( [ ...oneWay ].reverse() );

		// And it really is sorted, rather than two stable-but-arbitrary orders.
		const sorted = [ ...oneWay ].sort();
		expect( oneWay[ 0 ] === sorted[ 0 ] || theOther[ 0 ] === sorted[ 0 ] ).toBe( true );
	} );
} );

test.describe( 'The SQL console', () => {
	let table;

	test.beforeEach( async () => {
		resetPlugin();
		table = createScratchTable( 2 );
	} );

	test.afterEach( async () => {
		dropScratchTable();
	} );

	test( 'the fixture really is a console that runs what it is given', async ( { page } ) => {
		// The precondition: the console reaches the database at all. Without it
		// every "this statement was refused" test below passes for the wrong
		// reason, because nothing would have run either way.
		await runQuery( page, `INSERT INTO \`${ table }\` ( label ) VALUES ( 'from the console' )` );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'INSERT INTO' );
		await expect( page.locator( '.notice-info' ) ).toContainText( '1/1' );
		expect( scratchState().rows ).toBe( 3 );
	} );

	test( 'several statements on separate lines are counted one by one', async ( { page } ) => {
		await runQuery(
			page,
			`INSERT INTO \`${ table }\` ( label ) VALUES ( 'one' )\n` +
				`INSERT INTO \`${ table }\` ( label ) VALUES ( 'two' )\n` +
				`DELETE FROM \`${ table }\` WHERE label = 'one'`,
		);

		await expect( page.locator( '.notice-info' ) ).toContainText( '3/3' );
		expect( scratchState().rows ).toBe( 3 );
	} );

	test( 'an empty console says so rather than reporting a run', async ( { page } ) => {
		await runQuery( page, '   \n  \n' );

		await expect( page.locator( '.notice-error' ) ).toContainText( 'Empty Query' );
	} );

	test( 'the statements the console refuses are refused, and nothing runs', async ( { page } ) => {
		// Five statements, each a full round trip through the screen, which is more
		// than the default budget allows for one test. Keeping them together is
		// what lets the last assertion speak for all five at once.
		test.slow();

		// One case per refused class. SELECT and SHOW return rows the console
		// cannot display, DROP and GRANT are what the other screens are for, and
		// LOAD_FILE would turn the console into an arbitrary file read.
		const refused = [
			`SELECT * FROM \`${ table }\``,
			`DROP TABLE \`${ table }\``,
			'SHOW TABLES',
			"GRANT ALL ON *.* TO 'nobody'@'localhost'",
			`INSERT INTO \`${ table }\` ( label ) VALUES ( LOAD_FILE('/etc/passwd') )`,
		];

		for ( const query of refused ) {
			await runQuery( page, query );

			// Reported as a failure rather than silently ignored, so somebody who
			// typed it finds out.
			await expect( page.locator( '.notice-error' ), query ).toContainText(
				query.slice( 0, 20 ),
			);
			await expect( page.locator( '.notice-info' ), query ).toContainText( '0/1' );
		}

		// The far end: the table is still there and still holds exactly what it
		// held before any of that was typed.
		const state = scratchState();

		expect( state.exists ).toBe( true );
		expect( state.rows ).toBe( 2 );
	} );

	test( 'a statement the console does not recognise is ignored rather than run', async ( {
		page,
	} ) => {
		await runQuery( page, 'TRUNCATE TABLE wp_options' );

		// Neither allowed nor explicitly refused, so it is skipped and never
		// counted -- which is what "0/0" says. The alternative, running anything
		// unrecognised, is how a console becomes a way to do the one thing it was
		// meant to keep behind a screen with a confirmation on it.
		await expect( page.locator( '.notice-info' ) ).toContainText( '0/0' );

		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) . '>>>';`,
			),
		).not.toBe( '0' );
	} );

	test( 'an allowed statement that fails is reported as a failure', async ( { page } ) => {
		await runQuery( page, 'INSERT INTO wp_not_a_real_table ( x ) VALUES ( 1 )' );

		await expect( page.locator( '.notice-error' ) ).toContainText( 'INSERT INTO' );
		await expect( page.locator( '.notice-info' ) ).toContainText( '0/1' );
	} );
} );
