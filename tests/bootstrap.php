<?php
/**
 * PHPUnit bootstrap.
 *
 * @package WP-DBManager
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Where wp-env mounts the WordPress test library.
	$_tests_dir = '/wordpress-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';
require_once __DIR__ . '/helper-source.php';

/**
 * Load the plugin.
 *
 * @return void
 */
function _manually_load_wp_dbmanager() {
	require dirname( __DIR__ ) . '/wp-dbmanager.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_wp_dbmanager' );

require $_tests_dir . '/includes/bootstrap.php';

// After the WordPress bootstrap, because the base case extends WP_UnitTestCase.
// Loading it here rather than from each test file keeps a bare statement from
// sitting between those files' doc comment and their class, which is what makes
// PHPCS attribute the file comment to the statement and report it missing.
require_once __DIR__ . '/helper-testcase.php';

// The shared metadata contract, a byte-identical copy of
// _standards/templates/helper-metadata-testcase.php. It extends Plugin_TestCase
// because the nineteen copies have to be identical; the alias is the one line
// per plugin the mechanism needs.
class_alias( 'WP_DBManager_TestCase', 'Plugin_TestCase' );
require_once __DIR__ . '/helper-metadata-testcase.php';
