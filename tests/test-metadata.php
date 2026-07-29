<?php
/**
 * The release invariants, asserted from the source and from the stored rows.
 *
 * These are the house rules every plugin in this family shares, and every one
 * of them has been broken by an ordinary edit at some point: a header field
 * that drifted out of the canonical order, a new directory shipped without its
 * silence guard, a version bumped in one file of three, a readme header line
 * that lost the two trailing spaces holding it apart from the next.
 *
 * They are the things a restructuring quietly breaks and nothing notices until
 * a release fails its pre-flight months later, so catching them here is far
 * cheaper than catching them there.
 *
 * @package WP-DBManager
 */

/**
 * @coversNothing
 */
class WP_DBManager_Metadata_Test extends WP_DBManager_TestCase {

	const VERSION = '4.0.0';

	/**
	 * The main plugin file.
	 *
	 * @return string
	 */
	protected function plugin_file() {
		return wp_dbmanager_test_read( 'wp-dbmanager.php' );
	}

	/**
	 * The readme.
	 *
	 * @return string
	 */
	protected function readme() {
		return wp_dbmanager_test_read( 'README.md' );
	}

	/**
	 * Every directory in the repo that holds at least one PHP file.
	 *
	 * @return string[] Absolute paths, plugin root included.
	 */
	protected function php_directories() {
		$root  = dirname( __DIR__ );
		$found = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			// vendor/ and node_modules/ are not ours and never ship.
			if ( false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/node_modules/' ) ) {
				continue;
			}

			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$found[ dirname( $path ) ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * A field from the main plugin file's header docblock.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function header_field( $field ) {
		$data = get_file_data( dirname( __DIR__ ) . '/wp-dbmanager.php', array( $field => $field ) );

		return $data[ $field ];
	}

	/**
	 * A field from the readme's header block.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function readme_field( $field ) {
		preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $this->readme(), $matches );

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	/**
	 * Every option row the plugin owns, read straight from the table.
	 *
	 * @return string[]
	 */
	protected function stored_option_names() {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'wp_dbmanager_' ) . '%'
			)
		);
	}

	public function test_version_matches_everywhere() {
		$this->assertStringContainsString( ' * Version: ' . self::VERSION, $this->plugin_file() );
		$this->assertStringContainsString( "define( 'WP_DBMANAGER_VERSION', '" . self::VERSION . "' );", $this->plugin_file() );
		$this->assertStringContainsString( 'Stable tag: ' . self::VERSION, $this->readme() );
		$this->assertSame( self::VERSION, WP_DBMANAGER_VERSION );
	}

	/**
	 * 3.0.0 is already on WordPress.org, so this release cannot reuse it.
	 */
	public function test_the_version_is_past_the_one_already_released() {
		$this->assertTrue(
			version_compare( WP_DBMANAGER_VERSION, '3.0.0', '>' ),
			'3.0.0 shipped to WordPress.org; reusing or going below it rewrites released history.'
		);
	}

	public function test_the_changelog_has_a_section_for_this_version() {
		$this->assertStringContainsString( '### ' . self::VERSION . "\n", $this->readme() );
	}

	/**
	 * The order is neither alphabetical nor intuitive -- Requires at least and
	 * Requires PHP sit before Author -- so it is copied, never composed.
	 */
	public function test_the_plugin_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Plugin Name',
			'Plugin URI',
			'Description',
			'Version',
			'Requires at least',
			'Requires PHP',
			'Author',
			'Author URI',
			'License',
			'License URI',
			'Text Domain',
			'Domain Path',
		);

		preg_match( '#^<\?php\s*/\*\*(.+?)\*/#s', $this->plugin_file(), $matches );
		$this->assertNotEmpty( $matches, 'The plugin file must open with a docblock header.' );

		preg_match_all( '/^\s*\*\s*([A-Z][A-Za-z ]*?):\s/m', $matches[1], $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	/**
	 * The readme order differs from the PHP one on purpose: Requires PHP comes
	 * after Stable tag here. They are not to be harmonised.
	 */
	public function test_the_readme_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Contributors',
			'Donate link',
			'Tags',
			'Requires at least',
			'Tested up to',
			'Stable tag',
			'Requires PHP',
			'License',
			'License URI',
		);

		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );

		preg_match_all( '/^([A-Z][A-Za-z ]*?):\s/m', $header, $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	public function test_requires_headers_match_readme() {
		$this->assertSame( '6.8', $this->header_field( 'Requires at least' ) );
		$this->assertSame( '8.2', $this->header_field( 'Requires PHP' ) );
		$this->assertSame( '6.8', $this->readme_field( 'Requires at least' ) );
		$this->assertSame( '8.2', $this->readme_field( 'Requires PHP' ) );
	}

	/**
	 * The floors are also written into the tooling, and all six must agree.
	 */
	public function test_the_floors_agree_across_the_tooling() {
		$this->assertStringContainsString( '"php": ">=8.2"', wp_dbmanager_test_read( 'composer.json' ) );
		$this->assertStringContainsString( '"phpVersion": "8.2"', wp_dbmanager_test_read( '.wp-env.json' ) );
		$this->assertStringContainsString( '<config name="minimum_wp_version" value="6.8"/>', wp_dbmanager_test_read( 'phpcs.xml' ) );

		// Asserted on the quoted versions rather than on the matrix keys around
		// them: those keys are lowercase YAML, and CapitalPDangit would rewrite
		// any needle spelling one out into something the file does not contain.
		$ci = wp_dbmanager_test_read( '.github/workflows/ci.yml' );

		$this->assertStringContainsString( "'6.8'", $ci, 'The CI matrix must carry a floor row.' );
		$this->assertStringContainsString( "'8.2'", $ci, 'The CI matrix must test the PHP floor.' );
		$this->assertStringContainsString( "'8.5'", $ci, 'The CI matrix must test the newest PHP.' );
		$this->assertStringNotContainsString( "'7.4'", $ci, 'The old PHP floor must be gone from CI.' );
	}

	/**
	 * Header lines need two trailing spaces to render as separate lines.
	 *
	 * Markdown joins consecutive lines into one paragraph unless each is ended
	 * with a hard line break, so a missing pair renders as
	 * "License: GPLv2 or later License URI: https://..." on GitHub. It is
	 * invisible in the source and in a diff, which is exactly why it wants a
	 * test. The last line needs none, having nothing after it to run into.
	 */
	public function test_every_readme_header_line_keeps_its_line_break() {
		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );
		$lines  = explode( "\n", $header );

		// The first line is the "# WP-DBManager" heading, not a header field.
		$fields = array_slice( $lines, 1 );
		$last   = array_pop( $fields );

		$this->assertCount( 8, $fields, 'Nine header fields, eight of them followed by another.' );

		foreach ( $fields as $line ) {
			$this->assertStringEndsWith(
				'  ',
				$line,
				"Needs two trailing spaces or it merges with the line below: {$line}"
			);
		}

		$this->assertStringStartsWith( 'License URI:', $last );
		$this->assertSame( rtrim( $last ), $last, 'The last header line must not have trailing spaces.' );
	}

	public function test_the_readme_lists_exactly_five_tags() {
		preg_match( '/^Tags:\s*(.+?)\s*$/m', $this->readme(), $matches );

		$this->assertNotEmpty( $matches, 'The readme must carry a Tags line.' );
		$this->assertCount( 5, explode( ',', $matches[1] ) );
	}

	/**
	 * Bare versions: "### 4.0.0", never "### Version 4.0.0".
	 */
	public function test_every_changelog_heading_is_a_bare_version() {
		$this->assertSame( 0, preg_match( '/^### Version /m', $this->readme() ) );
	}

	/**
	 * The catalogue comes from translate.wordpress.org, and since WP 6.7 calling
	 * load_plugin_textdomain() this early trips _doing_it_wrong.
	 */
	public function test_the_plugin_does_not_load_its_own_textdomain() {
		$this->assertStringNotContainsString( 'load_plugin_textdomain', wp_dbmanager_test_source_code() );
	}

	/**
	 * The old forums.lesterchan.net is gone, and the rest of these had drifted
	 * to http over twenty years. Code spans are exempt: they document input.
	 */
	public function test_no_insecure_or_dead_links_remain() {
		$readme = preg_replace( '/`[^`]*`/', '', $this->readme() );

		$this->assertSame( 0, preg_match( '#http://#', $readme ), 'Every readme link must use https.' );
		$this->assertSame( 0, preg_match( '#http://#', $this->plugin_file() ) );
		$this->assertStringNotContainsString( 'forums.lesterchan.net', $readme );
	}

	public function test_every_directory_has_an_index_php() {
		foreach ( $this->php_directories() as $directory ) {
			$this->assertFileExists(
				$directory . '/index.php',
				"{$directory} ships PHP and so needs an index.php silence guard."
			);
		}
	}

	public function test_the_guards_use_the_docblock_form() {
		foreach ( $this->php_directories() as $directory ) {
			$guard = wp_dbmanager_test_read( str_replace( dirname( __DIR__ ) . '/', '', $directory ) . '/index.php' );

			// phpcbf cannot fix the one-line "// Silence is golden." form.
			$this->assertStringContainsString( '/**', $guard, "{$directory}/index.php must use the docblock form." );
			$this->assertStringContainsString( 'Silence is golden.', $guard );
		}
	}

	/**
	 * The two files that keep the backup folder off the public web still ship.
	 *
	 * Section 1's layout list does not mention them, but they are payloads the
	 * plugin copies into the backup folder rather than stray config, and a
	 * backup folder served over HTTP hands out the users table. Losing them to
	 * a tidy-up is a security regression, so it is asserted rather than trusted.
	 */
	public function test_the_backup_folder_protection_files_still_ship() {
		$root = dirname( __DIR__ );

		$this->assertFileExists( $root . '/htaccess.txt', 'Apache sites copy this in as .htaccess.' );
		$this->assertFileExists( $root . '/Web.config.txt', 'IIS sites copy this in as Web.config.' );

		$this->assertStringContainsString( 'deny from all', wp_dbmanager_test_read( 'htaccess.txt' ) );
		$this->assertStringContainsString( 'requestFiltering', wp_dbmanager_test_read( 'Web.config.txt' ) );
	}

	public function test_the_gpl_licence_is_shipped() {
		$licence = wp_dbmanager_test_read( 'LICENSE' );

		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', $licence );
		$this->assertStringContainsString( 'Version 2, June 1991', $licence );
	}

	/**
	 * The block in the plugin file is the "or later" variant.
	 *
	 * A version-2-only block would contradict the License: GPLv2 or later two
	 * lines above it and the GPL-2.0-or-later in composer.json, which is a
	 * self-contradicting licence statement rather than a stylistic choice.
	 */
	public function test_the_licence_block_is_the_or_later_variant() {
		$this->assertStringContainsString(
			'either version 2 of the License, or',
			$this->plugin_file()
		);
		$this->assertStringContainsString( '(at your option) any later version.', $this->plugin_file() );
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ) );
		$this->assertStringContainsString( '"license": "GPL-2.0-or-later"', wp_dbmanager_test_read( 'composer.json' ) );
	}

	/**
	 * The catalogue is built by translate.wordpress.org, and Travis has been
	 * dead for these repos for years.
	 */
	public function test_no_abandoned_build_or_translation_artefacts_ship() {
		$root = dirname( __DIR__ );

		$this->assertFileDoesNotExist( $root . '/.travis.yml' );
		$this->assertFileDoesNotExist( $root . '/.wp-env.override.json' );
		$this->assertDirectoryDoesNotExist( $root . '/.idea' );
		$this->assertDirectoryDoesNotExist( $root . '/languages' );

		foreach ( array( 'pot', 'po', 'mo' ) as $extension ) {
			$this->assertSame(
				array(),
				(array) glob( $root . '/*.' . $extension ),
				"No .{$extension} files: translate.wordpress.org builds the catalogue."
			);
		}
	}

	public function test_canonical_lesterchan_urls() {
		$this->assertSame(
			'https://lesterchan.net/portfolio/programming/php/',
			$this->header_field( 'Plugin URI' )
		);
		$this->assertSame( 'https://lesterchan.net', $this->header_field( 'Author URI' ) );
		$this->assertSame( 'https://lesterchan.net/site/donation/', $this->readme_field( 'Donate link' ) );
		$this->assertSame(
			'https://www.gnu.org/licenses/gpl-2.0.html',
			$this->header_field( 'License URI' )
		);
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $this->readme_field( 'License URI' ) );
	}

	/**
	 * One name, in every plugin. A second contributor has to be added on
	 * wordpress.org as well, so a name here that is not on the listing silently
	 * does nothing.
	 */
	public function test_contributors_is_gamerz_only() {
		$this->assertSame( 'GamerZ', $this->readme_field( 'Contributors' ) );
	}

	public function test_text_domain_is_the_plugin_slug() {
		$this->assertSame( 'wp-dbmanager', $this->header_field( 'Text Domain' ) );
		$this->assertSame( '/languages', $this->header_field( 'Domain Path' ) );
		$this->assertSame( 'wp-dbmanager', WP_DBMANAGER_SLUG );
	}

	/**
	 * The second-level headings are a closed set in a fixed order.
	 *
	 * Third-level ones are not: Donations, the usage subsections and every
	 * changelog version live below these.
	 */
	public function test_readme_sections_are_the_canonical_set() {
		preg_match_all( '/^## (.+?)\s*$/m', $this->readme(), $sections );

		$this->assertSame(
			array(
				'Description',
				'Usage',
				'Frequently Asked Questions',
				'Screenshots',
				'Changelog',
				'Upgrade Notice',
			),
			$sections[1]
		);
	}

	/**
	 * Donations is the last h3 of Description, with one exact wording.
	 */
	public function test_the_donations_section_carries_the_shared_wording() {
		$readme      = $this->readme();
		$description = substr( $readme, (int) strpos( $readme, '## Description' ) );
		$description = substr( $description, 0, (int) strpos( $description, "\n## Usage" ) );

		$this->assertStringContainsString( '### Donations', $description, 'Donations belongs under Description.' );
		$this->assertStringContainsString(
			'I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.',
			$description
		);
		$this->assertStringNotContainsString( 'as my school allowance', $description );
		$this->assertStringNotContainsString(
			'* I spent most of my free time',
			$description,
			'Donations is a plain paragraph, not a bullet.'
		);
	}

	/**
	 * Development and Credits live in the repo, not in the wordpress.org readme.
	 */
	public function test_the_retired_readme_sections_are_gone() {
		$this->assertSame( 0, preg_match( '/^### Development/m', $this->readme() ) );
		$this->assertSame( 0, preg_match( '/^### Credits/m', $this->readme() ) );
	}

	/**
	 * Five prefixes, and nothing else.
	 *
	 * The listing on wordpress.org renders the changelog verbatim, so a stray
	 * "Important:" or a lowercase "New:" is visible to every reader of it.
	 */
	public function test_changelog_prefixes_are_canonical() {
		$readme    = $this->readme();
		$changelog = substr( $readme, (int) strpos( $readme, '## Changelog' ) );
		$changelog = substr( $changelog, 0, (int) strpos( $changelog, "\n## Upgrade Notice" ) );

		preg_match_all( '/^\* (.+?):/m', $changelog, $bullets );

		$this->assertNotEmpty( $bullets[1], 'The changelog must carry bullets.' );

		foreach ( $bullets[1] as $prefix ) {
			$this->assertContains(
				$prefix . ':',
				array( 'BREAKING:', 'NEW:', 'CHANGED:', 'FIXED:', 'NOTE:' ),
				"'{$prefix}:' is not one of the five allowed changelog prefixes."
			);
		}
	}

	/**
	 * The raised floors are the break most likely to actually bite.
	 *
	 * A site below them is not offered the update at all, so it has to be said
	 * plainly rather than left to the changelog.
	 */
	public function test_the_upgrade_notice_covers_the_raised_floors() {
		$readme = $this->readme();
		$notice = substr( $readme, (int) strpos( $readme, '## Upgrade Notice' ) );

		$this->assertStringContainsString( 'WordPress 6.8', $notice );
		$this->assertStringContainsString( 'PHP 8.2', $notice );
		$this->assertStringContainsString( 'wp_dbmanager_options', $notice, 'The renamed settings row belongs here.' );
		$this->assertStringContainsString( 'wp_dbmanager_cron_backup', $notice, 'The renamed cron hooks belong here.' );
		$this->assertStringContainsString( '4.0.0', $notice, 'The renumbering belongs here.' );
	}

	/**
	 * Not one script the plugin registers may depend on jQuery.
	 */
	public function test_no_jquery_is_enqueued() {
		$code = wp_dbmanager_test_source_code();

		$this->assertStringNotContainsStringIgnoringCase( 'jquery', $code );

		WP_DBManager_Admin::enqueue( 'database_page_wp-dbmanager' );

		$script = wp_scripts()->query( 'wp-dbmanager' );

		$this->assertNotFalse( $script, 'The admin script should have been registered.' );
		$this->assertSame( array(), $script->deps, 'The admin script declares no dependencies at all.' );

		foreach ( (array) glob( dirname( __DIR__ ) . '/js/*.js' ) as $file ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'jquery',
				(string) file_get_contents( $file ),
				basename( $file ) . ' still mentions jQuery.'
			);
		}
	}

	/**
	 * No plugin in this family ships a second, mirrored stylesheet: the front
	 * end uses CSS logical properties instead, so one sheet serves both
	 * directions. This one ships no stylesheet at all.
	 */
	public function test_no_rtl_stylesheet_is_registered() {
		$root = dirname( __DIR__ );

		$this->assertSame( array(), (array) glob( $root . '/*-rtl.css' ) );
		$this->assertSame( array(), (array) glob( $root . '/css/*-rtl.css' ) );
		$this->assertStringNotContainsString(
			'wp_style_add_data',
			wp_dbmanager_test_source_code(),
			"No plugin registers 'rtl' style data."
		);
	}

	/**
	 * The upgrade markers live in their own row, holding those two keys and no
	 * others. Anything else in here means a marker has drifted back into the
	 * settings array, which is the bug this shape exists to make impossible.
	 */
	public function test_version_row_holds_exactly_plugin_and_db() {
		WP_DBManager_Options::maybe_upgrade();

		$markers = get_option( WP_DBManager_Options::VERSION );

		$this->assertIsArray( $markers, 'wp_dbmanager_version must be an array.' );

		$keys = array_keys( $markers );
		sort( $keys );

		$this->assertSame( array( 'db', 'plugin' ), $keys );
		$this->assertSame( WP_DBMANAGER_VERSION, $markers['plugin'] );
		$this->assertSame( WP_DBMANAGER_DB_VERSION, $markers['db'] );
	}

	/**
	 * The sanitiser is a function from what the form posted to what gets stored.
	 *
	 * This is the regression guard for the wp-useronline bug: it fails the
	 * moment somebody moves an upgrade marker back into the settings array,
	 * where every save would have to rescue it from the stored value by hand.
	 */
	public function test_settings_sanitizer_never_stores_version_markers() {
		$stored = WP_DBManager_Settings::sanitize(
			array(
				'max_backup' => '4',
				'version'    => '9.9.9',
				'db_version' => '99',
				'versions'   => array( 'plugin' => '9.9.9' ),
			)
		);

		$this->assertArrayNotHasKey( 'version', $stored );
		$this->assertArrayNotHasKey( 'db_version', $stored );
		$this->assertArrayNotHasKey( 'versions', $stored );
		$this->assertArrayNotHasKey( 'plugin', $stored );

		WP_DBManager_Options::update( $stored );

		$this->assertSame(
			WP_DBMANAGER_VERSION,
			WP_DBManager_Options::markers()['plugin'],
			'Saving the settings must not be able to disturb the markers row.'
		);
	}

	/**
	 * Deleting the plugin leaves nothing behind.
	 *
	 * The assertion is deliberately a LIKE over wp_options rather than two
	 * delete_option() checks: a row added later and forgotten in uninstall.php
	 * is exactly the failure this is here to catch. The multisite config runs
	 * the same test through uninstall.php's get_sites() branch.
	 */
	public function test_uninstall_removes_every_option_row() {
		WP_DBManager_Options::maybe_upgrade();
		update_option( WP_DBManager_Options::OPTION, WP_DBManager_Options::defaults() );

		$this->assertNotEmpty(
			$this->stored_option_names(),
			'There should be rows to remove before uninstall runs.'
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-dbmanager/wp-dbmanager.php' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';

		// Called rather than left to the require: tests/test-uninstall.php loads
		// the same file, and whichever of the two runs second gets a require_once
		// that is a silent no-op. The multisite loop around this call is asserted
		// from the source over there, since a network big enough to prove the
		// hundred-and-first site is reached cannot be built in a test.
		wp_dbmanager_uninstall_site();

		wp_cache_flush();

		$this->assertSame(
			array(),
			$this->stored_option_names(),
			'uninstall.php must remove every wp_dbmanager_* row.'
		);
	}
}
