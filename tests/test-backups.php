<?php
/**
 * The backup files on disk.
 *
 * @package WP-DBManager
 */

/**
 * Listing, parsing, pruning and resolving backup files.
 */
class Test_DBManager_Backups extends DBManager_TestCase {

	/**
	 * Drop a fake backup into the scratch folder.
	 *
	 * @param string $name  File name.
	 * @param int    $mtime Modification time.
	 * @param string $body  Contents.
	 * @return string Full path.
	 */
	protected function make_backup( $name, $mtime = null, $body = 'dump' ) {
		$path = $this->backup_dir . '/' . $name;
		file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( null !== $mtime ) {
			touch( $path, $mtime );
		}

		return $path;
	}

	/**
	 * Sizes are reported in the unit they are labelled with.
	 *
	 * The pre-3.0.0 version divided by MB_IN_BYTES inside the GiB branch, so a
	 * 2 GiB backup was reported as "2048.0 GiB".
	 */
	public function test_format_size_uses_the_right_divisor() {
		$this->assertStringContainsString( 'bytes', DBManager_Backups::format_size( 512 ) );
		$this->assertStringContainsString( 'KiB', DBManager_Backups::format_size( 2 * KB_IN_BYTES ) );
		$this->assertStringContainsString( 'MiB', DBManager_Backups::format_size( 2 * MB_IN_BYTES ) );

		$two_gib = DBManager_Backups::format_size( 2 * GB_IN_BYTES );

		$this->assertStringContainsString( 'GiB', $two_gib );
		$this->assertStringContainsString( '2.0', $two_gib );
		$this->assertStringNotContainsString( '2,048', $two_gib );
	}

	/**
	 * A checksummed name is split into its three parts.
	 */
	public function test_parse_filename_reads_a_checksummed_name() {
		$file = DBManager_Backups::parse_filename( str_repeat( 'a', 32 ) . '_-_1700000000_-_sitedb.sql' );

		$this->assertSame( str_repeat( 'a', 32 ), $file['checksum'] );
		$this->assertSame( '1700000000', $file['timestamp'] );
		$this->assertSame( 'sitedb.sql', $file['database'] );
		$this->assertNotSame( '-', $file['formatted_date'] );
	}

	/**
	 * A name from before checksums were added still parses.
	 */
	public function test_parse_filename_reads_a_legacy_name() {
		$file = DBManager_Backups::parse_filename( '1700000000_-_sitedb.sql' );

		$this->assertSame( '-', $file['checksum'] );
		$this->assertSame( '1700000000', $file['timestamp'] );
		$this->assertSame( 'sitedb.sql', $file['database'] );
	}

	/**
	 * A name that is not a backup at all does not blow up.
	 */
	public function test_parse_filename_survives_an_unexpected_name() {
		$file = DBManager_Backups::parse_filename( 'notabackup.sql' );

		$this->assertSame( '-', $file['formatted_date'] );
		$this->assertSame( 'notabackup.sql', $file['database'] );
	}

	/**
	 * Only .sql and .gz files are listed, and .htaccess never is.
	 */
	public function test_all_lists_only_backups() {
		$this->make_backup( 'a_-_1700000000_-_db.sql' );
		$this->make_backup( 'b_-_1700000001_-_db.sql.gz' );
		$this->make_backup( '.htaccess' );
		$this->make_backup( 'index.php' );
		$this->make_backup( 'notes.txt' );

		$names = wp_list_pluck( DBManager_Backups::all( $this->backup_dir ), 'name' );

		$this->assertContains( 'a_-_1700000000_-_db.sql', $names );
		$this->assertContains( 'b_-_1700000001_-_db.sql.gz', $names );
		$this->assertNotContains( '.htaccess', $names );
		$this->assertNotContains( 'index.php', $names );
		$this->assertNotContains( 'notes.txt', $names );
	}

	/**
	 * Two backups written in the same second are both visible.
	 *
	 * They used to be keyed by mtime, so one of the pair vanished from both the
	 * manage screen and the pruning pass.
	 */
	public function test_backups_sharing_an_mtime_are_both_listed() {
		$this->make_backup( 'a_-_1700000000_-_db.sql', 1700000000 );
		$this->make_backup( 'b_-_1700000000_-_db.sql', 1700000000 );

		$this->assertCount( 2, DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * Listing is oldest first.
	 */
	public function test_all_sorts_oldest_first() {
		$this->make_backup( 'newer_-_1700000100_-_db.sql', 1700000100 );
		$this->make_backup( 'older_-_1700000000_-_db.sql', 1700000000 );

		$names = wp_list_pluck( DBManager_Backups::all( $this->backup_dir ), 'name' );

		$this->assertSame( 'older_-_1700000000_-_db.sql', $names[0] );
	}

	/**
	 * Pruning keeps going until the limit is met.
	 *
	 * The old loop deleted exactly one file per call, so a folder that was well
	 * over the maximum stayed over it.
	 */
	public function test_prune_makes_room_for_the_next_backup() {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->make_backup( 'f' . $i . '_-_17000000' . sprintf( '%02d', $i ) . '_-_db.sql', 1700000000 + $i );
		}

		$options               = DBManager_Options::get();
		$options['max_backup'] = 3;
		update_option( DBManager_Options::OPTION, $options );

		DBManager_Backups::prune();

		// Two left, so the third slot is free for the backup about to be taken.
		$this->assertCount( 2, DBManager_Backups::all( $this->backup_dir ) );
	}

	/**
	 * Pruning removes the oldest first.
	 */
	public function test_prune_removes_the_oldest() {
		$this->make_backup( 'old_-_1700000000_-_db.sql', 1700000000 );
		$this->make_backup( 'new_-_1700000900_-_db.sql', 1700000900 );

		$options               = DBManager_Options::get();
		$options['max_backup'] = 2;
		update_option( DBManager_Options::OPTION, $options );

		DBManager_Backups::prune();

		$names = wp_list_pluck( DBManager_Backups::all( $this->backup_dir ), 'name' );

		$this->assertSame( array( 'new_-_1700000900_-_db.sql' ), $names );
	}

	/**
	 * A maximum below one means no limit, not "delete everything".
	 */
	public function test_prune_treats_a_maximum_below_one_as_no_limit() {
		$this->make_backup( 'a_-_1700000000_-_db.sql' );
		$this->make_backup( 'b_-_1700000001_-_db.sql' );

		foreach ( array( 0, -1 ) as $max ) {
			$options               = DBManager_Options::get();
			$options['max_backup'] = $max;
			update_option( DBManager_Options::OPTION, $options );

			DBManager_Backups::prune();

			$this->assertCount( 2, DBManager_Backups::all( $this->backup_dir ), 'max_backup ' . $max );
		}
	}

	/**
	 * A real backup file resolves to its path.
	 */
	public function test_resolve_accepts_a_real_backup() {
		$path = $this->make_backup( 'a_-_1700000000_-_db.sql' );

		$this->assertSame( realpath( $path ), DBManager_Backups::resolve( 'a_-_1700000000_-_db.sql' ) );
	}

	/**
	 * Anything that is not a backup inside the folder is refused.
	 *
	 * @dataProvider data_bad_names
	 *
	 * @param string $name Submitted file name.
	 */
	public function test_resolve_refuses( $name ) {
		$this->make_backup( 'a_-_1700000000_-_db.sql' );

		$this->assertFalse( DBManager_Backups::resolve( $name ) );
	}

	/**
	 * File names that must never resolve.
	 *
	 * @return array
	 */
	public function data_bad_names() {
		return array(
			'climbing out'          => array( '../../wp-config.php' ),
			'absolute path'         => array( '/etc/passwd' ),
			'wrong extension'       => array( 'index.php' ),
			'no extension'          => array( 'a_-_1700000000_-_db' ),
			'does not exist'        => array( 'nothing_-_1700000000_-_db.sql' ),
			'empty'                 => array( '' ),
			'traversal with suffix' => array( '../../../etc/passwd.sql' ),
		);
	}

	/**
	 * An empty backup folder is still a valid one.
	 *
	 * The old check tried to reject it, which would have stopped scheduled
	 * backups on every site that had not taken one yet.
	 */
	public function test_an_empty_folder_is_valid() {
		$this->assertTrue( DBManager_Backups::is_folder_valid( $this->backup_dir ) );
	}

	/**
	 * A folder that is not there is not valid.
	 */
	public function test_a_missing_folder_is_not_valid() {
		$this->assertFalse( DBManager_Backups::is_folder_valid( $this->backup_dir . '/nope' ) );
		$this->assertFalse( DBManager_Backups::is_folder_valid( '' ) );
	}
}
