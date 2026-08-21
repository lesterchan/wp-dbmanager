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

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$_tests_dir}." . PHP_EOL;
	echo 'Run the suite through bin/test.sh, or set WP_TESTS_DIR.' . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';
require_once __DIR__ . '/helper-source.php';

/**
 * Load the plugin.
 *
 * @return void
 */
function _wp_dbmanager_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-dbmanager.php';
}

tests_add_filter( 'muplugins_loaded', '_wp_dbmanager_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

// After the WordPress bootstrap, because the base case extends WP_UnitTestCase.
// Loading it here rather than from each test file keeps a bare statement from
// sitting between those files' doc comment and their class, which is what makes
// PHPCS attribute the file comment to the statement and report it missing.
require_once __DIR__ . '/helper-testcase.php';

// The WP-CLI stand-ins, in dependency order: the base class the command file
// extends, then the formatter it prints through, then the facade -- which ends
// by requiring the command itself, so nothing else has to know that the plugin
// only loads that file when WP_CLI is defined.
require_once __DIR__ . '/helper-wp-cli-command.php';
require_once __DIR__ . '/helper-wp-cli-utils.php';
require_once __DIR__ . '/helper-wp-cli.php';

// The shared metadata contract, a byte-identical copy of
// _standards/templates/helper-metadata-testcase.php. It extends Plugin_TestCase
// because the nineteen copies have to be identical; the alias is the one line
// per plugin the mechanism needs.
class_alias( 'WP_DBManager_TestCase', 'Plugin_TestCase' );
require_once __DIR__ . '/helper-metadata-testcase.php';
