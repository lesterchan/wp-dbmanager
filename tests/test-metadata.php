<?php
/**
 * What is true of WP-DBManager and of no other plugin.
 *
 * The twenty-three assertions §7.2 asks of all nineteen live in
 * Plugin_Metadata_TestCase, a byte-identical copy of
 * _standards/templates/helper-metadata-testcase.php. What is left here is the
 * three declarations that class cannot derive, the hooks it reaches back
 * through, and the handful of checks that are genuinely this plugin's: the
 * renumbering past the released 3.0.0, and the two files that keep the backup
 * folder off the public web.
 *
 * @package WP-DBManager
 */

/**
 * WP-DBManager against §7.2.
 *
 * @coversNothing
 */
class WP_DBManager_Metadata_Test extends Plugin_Metadata_TestCase {

	/**
	 * The version this release ships.
	 *
	 * @return string
	 */
	protected function expected_version() {
		return '4.0.0';
	}

	/**
	 * The prefix every class the plugin declares carries.
	 *
	 * No casing rule produces this from the slug, which is why §2.4 writes it
	 * out: wp-dbmanager is WP_DBManager, not WP_Dbmanager.
	 *
	 * @return string
	 */
	protected function class_prefix() {
		return 'WP_DBManager';
	}

	/**
	 * Every break a site owner updating from the released 3.0.0 would notice.
	 *
	 * The renumbering itself, the settings row rename, thirteen class renames,
	 * and the six scheduled-job identifiers - three hooks and three recurrences
	 * - that a wp_schedule_event() call or a WP-CLI cron entry of their own
	 * would still be naming.
	 *
	 * @return string[]
	 */
	protected function upgrade_notice_subjects() {
		return array(
			'6.8',
			'8.2',
			// §14: this release is 4.0.0 because 3.0.0 already shipped.
			'3.0.0',
			// The rows that moved.
			'dbmanager_options',
			'wp_dbmanager_options',
			'wp_dbmanager_version',
			// The class rename.
			'WP_DBManager_',
			// The three cron hooks, old name and new.
			'dbmanager_cron_backup',
			'dbmanager_cron_optimize',
			'dbmanager_cron_repair',
			'wp_dbmanager_cron_backup',
			'wp_dbmanager_cron_optimize',
			'wp_dbmanager_cron_repair',
			// And the three recurrences behind them.
			'wp_dbmanager_backup',
			'wp_dbmanager_optimize',
			'wp_dbmanager_repair',
			// The filters, one unchanged and one new, and the capability the
			// screens have taken since 2.80.7.
			'wp_dbmanager_before_escapeshellcmd',
			'wp_dbmanager_capability',
			'install_plugins',
		);
	}

	/**
	 * The settings row and the marker row.
	 *
	 * @return void
	 */
	protected function seed_option_rows() {
		WP_DBManager_Options::update( WP_DBManager_Options::defaults() );
		$this->write_version_row();
	}

	/**
	 * Write the marker row through the plugin's own upgrade routine.
	 *
	 * @return void
	 */
	protected function write_version_row() {
		WP_DBManager_Options::maybe_upgrade();
	}

	/**
	 * Round-trip the settings sanitiser.
	 *
	 * @param array $input What the settings form is pretending to have posted.
	 * @return array
	 */
	protected function sanitize_settings( array $input ) {
		return (array) WP_DBManager_Settings::sanitize( $input );
	}

	/**
	 * A real settings key, so the sanitiser has something of its own to do.
	 *
	 * @return array
	 */
	protected function settings_fixture() {
		return array( 'max_backup' => '4' );
	}

	/**
	 * Register the one script and the one stylesheet the plugin registers.
	 *
	 * Both are admin-only and keyed off the screen id, so neither appears
	 * without being asked for.
	 *
	 * @return void
	 */
	protected function register_plugin_assets() {
		WP_DBManager_Admin::enqueue( 'database_page_wp-dbmanager' );
	}

	/**
	 * This release is past the one already on WordPress.org.
	 *
	 * §14 renumbered wp-dbmanager: 3.0.0 had already shipped, so this work is
	 * 4.0.0 rather than a second 3.0.0. Reusing or going below a released
	 * version rewrites history that is already in people's update screens.
	 */
	public function test_the_version_is_past_the_one_already_released() {
		$this->assertTrue(
			version_compare( $this->expected_version(), '3.0.0', '>' ),
			'3.0.0 shipped to WordPress.org; reusing or going below it rewrites released history.'
		);
	}

	/**
	 * The two files that keep the backup folder off the public web still ship.
	 *
	 * §1's layout list does not mention them, but they are payloads the plugin
	 * copies into the backup folder rather than stray config, and a backup
	 * folder served over HTTP hands out the users table. Losing them to a
	 * tidy-up is a security regression, so it is asserted rather than trusted.
	 */
	public function test_the_backup_folder_protection_files_still_ship() {
		$root = $this->metadata_root();

		$this->assertFileExists( $root . '/htaccess.txt', 'Apache sites copy this in as .htaccess.' );
		$this->assertFileExists( $root . '/Web.config.txt', 'IIS sites copy this in as Web.config.' );

		$this->assertStringContainsString( 'deny from all', wp_dbmanager_test_read( 'htaccess.txt' ), 'The Apache protection file still ships.' );
		$this->assertStringContainsString( 'requestFiltering', wp_dbmanager_test_read( 'Web.config.txt' ), 'And the IIS one.' );
	}

	/**
	 * The floors are written into the tooling as well, and all of it agrees.
	 *
	 * The mechanical checker covers composer.json, .wp-env.json, phpcs.xml and
	 * the 8.5 row; the three below are the ones it does not, and the CI matrix
	 * is where a raised floor is most often left half-applied.
	 *
	 * Asserted on the quoted versions rather than on the matrix keys around
	 * them: those keys are lowercase YAML, and CapitalPDangit would rewrite any
	 * needle spelling one out into something the file does not contain.
	 */
	public function test_the_floors_agree_across_the_ci_matrix() {
		$ci = wp_dbmanager_test_read( '.github/workflows/ci.yml' );

		$this->assertStringContainsString( "'6.8'", $ci, 'The CI matrix must carry a floor row.' );
		$this->assertStringContainsString( "'8.2'", $ci, 'The CI matrix must test the PHP floor.' );
		$this->assertStringNotContainsString( "'7.4'", $ci, 'The old PHP floor must be gone from CI.' );
	}

	/**
	 * Five tags, which is the most wordpress.org indexes.
	 */
	public function test_the_readme_lists_exactly_five_tags() {
		preg_match( '/^Tags:\s*(.+?)\s*$/m', $this->readme(), $matches );

		$this->assertNotEmpty( $matches, 'The readme must carry a Tags line.' );
		$this->assertCount( 5, explode( ',', $matches[1] ), 'wordpress.org reads at most five tags, so a sixth is silently dropped.' );
	}

	/**
	 * No insecure or dead links remain.
	 *
	 * The old forums.lesterchan.net is gone, and the rest of these had drifted
	 * to http over twenty years. Code spans are exempt: they document input.
	 */
	public function test_no_insecure_or_dead_links_remain() {
		$readme = preg_replace( '/`[^`]*`/', '', $this->readme() );

		$this->assertSame( 0, preg_match( '#http://#', $readme ), 'Every readme link must use https.' );
		$this->assertSame( 0, preg_match( '#http://#', $this->plugin_file() ), 'The plugin file has no insecure links left.' );
		$this->assertStringNotContainsString( 'forums.lesterchan.net', $readme, 'And the readme does not point at the forum that closed.' );
	}

	/**
	 * The copyright block agrees with the header two lines above it.
	 *
	 * A version-2-only block would contradict the License: GPLv2 or later two
	 * lines above it and the GPL-2.0-or-later in composer.json, which is a
	 * self-contradicting licence statement rather than a stylistic choice.
	 */
	public function test_the_licence_block_is_the_or_later_variant() {
		$this->assertStringContainsString( 'either version 2 of the License, or', $this->plugin_file(), 'The licence block is the or-later variant.' );
		$this->assertStringContainsString( '(at your option) any later version.', $this->plugin_file(), 'Which is what the second half of the sentence says.' );
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ), 'The header says the same.' );
		$this->assertStringContainsString( '"license": "GPL-2.0-or-later"', wp_dbmanager_test_read( 'composer.json' ), 'And so does composer.json, so the three cannot drift.' );
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
			$description,
			'The donations section carries the shared wording.'
		);
		$this->assertStringNotContainsString( 'as my school allowance', $description, 'And not the wording it replaced.' );
		$this->assertStringNotContainsString(
			'* I spent most of my free time',
			$description,
			'Donations is a plain paragraph, not a bullet.'
		);
	}

	/**
	 * Development and Credits live in the repo, not the wordpress.org readme.
	 */
	public function test_the_retired_readme_sections_are_gone() {
		$this->assertSame( 0, preg_match( '/^### Development/m', $this->readme() ), 'The Development section is gone.' );
		$this->assertSame( 0, preg_match( '/^### Credits/m', $this->readme() ), 'And the Credits section.' );
	}

	/**
	 * Saving the settings cannot disturb the markers row.
	 *
	 * The shared test proves the sanitiser stores no marker. This one carries
	 * it through to the write: WP_DBManager_Options::update() is the only
	 * caller that could put the settings array where the markers live, and the
	 * wp-useronline bug this shape exists to prevent was exactly that.
	 */
	public function test_saving_the_settings_leaves_the_markers_row_alone() {
		$stored = WP_DBManager_Settings::sanitize(
			array(
				'max_backup' => '4',
				'version'    => '9.9.9',
				'db_version' => '99',
				'versions'   => array( 'plugin' => '9.9.9' ),
			)
		);

		$this->assertArrayNotHasKey( 'plugin', $stored, 'A marker key must not survive the sanitiser under any spelling.' );

		WP_DBManager_Options::update( $stored );

		$this->assertSame(
			WP_DBMANAGER_VERSION,
			WP_DBManager_Options::markers()['plugin'],
			'Saving the settings must not be able to disturb the markers row.'
		);
	}
}
