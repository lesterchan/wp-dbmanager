<?php
/**
 * Tests that only mean anything on a network.
 *
 * They skip themselves on a single site run, so bin/test-multisite.sh is the
 * only way they execute.
 *
 * @package WP-DBManager
 */

/**
 * Network activation: the per-site seeding has to reach every site.
 *
 * @group ms-required
 *
 * @covers WP_DBManager::activate
 */
class WP_DBManager_Multisite_Test extends WP_DBManager_TestCase {

	/**
	 * Skip the whole class unless this is a network.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only against a multisite network. Run bin/test-multisite.sh.' );
		}

		parent::set_up();
	}

	/**
	 * Create extra sites with the plugin's artifacts torn down.
	 *
	 * Torn down so activation has something to do: a leftover option row would
	 * let a loop that never reaches the site pass anyway.
	 *
	 * @param int $count How many sites to create.
	 * @return int[] Blog ids.
	 */
	private function seed_network( $count = 2 ) {
		$site_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$site_ids[] = self::factory()->blog->create();
		}

		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			delete_option( WP_DBManager_Options::OPTION );
			delete_option( WP_DBManager_Options::VERSION );
			restore_current_blog();
		}

		return $site_ids;
	}

	/**
	 * Network activation seeds the option row on every site, not just this one.
	 *
	 * The backup folder is left out of the assertion on purpose: its location
	 * depends on binaries detected by shelling out, which the container cannot
	 * promise, and the option row is what the loop exists to place per site.
	 *
	 * @return void
	 */
	public function test_network_activation_installs_on_every_site() {
		$site_ids = $this->seed_network( 2 );

		WP_DBManager::get_instance()->activate( true );

		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$options = get_option( WP_DBManager_Options::OPTION );
			restore_current_blog();

			$this->assertIsArray( $options, "Site {$blog_id} did not get its options row." );
			$this->assertArrayHasKey( 'path', $options, "Site {$blog_id} got an options row without the defaults." );
		}
	}

	/**
	 * Activating for one site only touches that site.
	 *
	 * @return void
	 */
	public function test_single_site_activation_leaves_other_sites_alone() {
		$site_ids = $this->seed_network( 1 );
		$other    = $site_ids[0];

		WP_DBManager::get_instance()->activate( false );

		switch_to_blog( $other );
		$options = get_option( WP_DBManager_Options::OPTION );
		restore_current_blog();

		$this->assertFalse( $options, 'A single site activation seeded options across the network.' );
	}

	/**
	 * The site query is uncapped and asks only for IDs.
	 *
	 * Asserted by reading the arguments the query was given rather than by
	 * building a 101 site fixture: get_sites() defaults to 100, so a larger
	 * network would silently skip every site past the hundredth.
	 *
	 * @return void
	 */
	public function test_network_activation_queries_sites_without_a_cap() {
		$this->seed_network( 2 );

		$captured = array();
		add_action(
			'pre_get_sites',
			function ( $query ) use ( &$captured ) {
				$captured[] = $query->query_vars;
			}
		);

		WP_DBManager::get_instance()->activate( true );

		$this->assertNotEmpty( $captured, 'Activation never queried the site list.' );
		$this->assertSame( 0, (int) $captured[0]['number'], 'get_sites() was left at its default cap of 100 sites.' );
		$this->assertSame( 'ids', $captured[0]['fields'], 'Only the site IDs are needed.' );
	}

	/**
	 * The blog stack is left unwound and the original site is current.
	 *
	 * Calling switch_to_blog() pushes onto a stack. Restoring once after the loop
	 * rather than once per iteration leaves the stack short, so whatever runs next
	 * operates against the last site visited instead of the one it thinks it is on.
	 *
	 * @return void
	 */
	public function test_network_activation_unwinds_the_blog_stack() {
		$original = get_current_blog_id();
		$this->seed_network( 2 );

		WP_DBManager::get_instance()->activate( true );

		$this->assertFalse( ms_is_switched(), 'The blog stack was left switched.' );
		$this->assertSame( $original, get_current_blog_id(), 'The original site is no longer current.' );
	}
}
