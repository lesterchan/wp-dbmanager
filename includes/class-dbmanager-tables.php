<?php
/**
 * Operations against the site's tables.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optimize, repair, empty, drop, and the raw query console.
 *
 * Table names cannot be bound as query parameters, so every one of them is
 * checked against SHOW TABLES before it is allowed anywhere near a statement.
 * That check lives here, once, rather than being repeated - slightly
 * differently each time - on four separate admin screens.
 *
 * @since 3.0.0
 */
class DBManager_Tables {

	/**
	 * Statements the query console will run.
	 */
	const ALLOWED_STATEMENTS = 'insert|update|replace|delete|create|alter';

	/**
	 * Statements the query console refuses.
	 */
	const REJECTED_STATEMENTS = 'select|drop|show|grant';

	/**
	 * Every table in the database.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;

		return $wpdb->get_col( 'SHOW TABLES' );
	}

	/**
	 * Status rows for every table.
	 *
	 * @return array
	 */
	public static function status() {
		global $wpdb;

		return $wpdb->get_results( 'SHOW TABLE STATUS' );
	}

	/**
	 * The MySQL server version.
	 *
	 * @return string
	 */
	public static function version() {
		global $wpdb;

		return $wpdb->get_var( 'SELECT VERSION() AS version' );
	}

	/**
	 * Keep only the submitted names that are real tables.
	 *
	 * The names arrive as request *keys*, which is the awkward part: they cannot
	 * be sanitized into safety, they can only be matched against the real list.
	 *
	 * @param array  $submitted Submitted table name => value pairs.
	 * @param string $wanted    Only keep entries whose value is this.
	 * @return array Table names that exist.
	 */
	public static function filter( $submitted, $wanted ) {
		if ( ! is_array( $submitted ) || empty( $submitted ) ) {
			return array();
		}

		$valid    = self::all();
		$selected = array();

		foreach ( $submitted as $name => $value ) {
			if ( $wanted === $value && in_array( $name, $valid, true ) ) {
				$selected[] = $name;
			}
		}

		return $selected;
	}

	/**
	 * Run OPTIMIZE TABLE against a validated list.
	 *
	 * @param array $tables Table names, already filtered.
	 * @return bool
	 */
	public static function optimize( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return false;
		}

		return (bool) $wpdb->query( 'OPTIMIZE TABLE `' . implode( '`, `', $tables ) . '`' );
	}

	/**
	 * Run REPAIR TABLE against a validated list.
	 *
	 * @param array $tables Table names, already filtered.
	 * @return bool
	 */
	public static function repair( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return false;
		}

		return (bool) $wpdb->query( 'REPAIR TABLE `' . implode( '`, `', $tables ) . '`' );
	}

	/**
	 * Empty a single table.
	 *
	 * @param string $table Table name, already validated.
	 * @return bool
	 */
	public static function truncate( $table ) {
		global $wpdb;

		return (bool) $wpdb->query( "TRUNCATE `$table`" );
	}

	/**
	 * Drop a validated list of tables.
	 *
	 * @param array $tables Table names, already filtered.
	 * @return bool
	 */
	public static function drop( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return false;
		}

		return (bool) $wpdb->query( 'DROP TABLE `' . implode( '`, `', $tables ) . '`' );
	}

	/**
	 * Split a submitted console blob into individual statements.
	 *
	 * @param string $blob Raw textarea contents.
	 * @return array
	 */
	public static function split_queries( $blob ) {
		$queries = array();

		foreach ( explode( "\n", $blob ) as $line ) {
			$line = preg_replace( "/[\r\n]+/", '', trim( $line ) );

			if ( ! empty( $line ) ) {
				$queries[] = $line;
			}
		}

		return $queries;
	}

	/**
	 * Run one console statement.
	 *
	 * @param string $query Statement to consider.
	 * @return string One of rejected, failed, ok, or ignored when it is neither
	 *                allowed nor explicitly refused.
	 */
	public static function run_query( $query ) {
		global $wpdb;

		// LOAD_FILE reads any file the database user can reach, which turns the
		// console into an arbitrary file read.
		if ( preg_match( '/LOAD_FILE/i', $query ) ) {
			return 'rejected';
		}

		if ( preg_match( '/^\s*(' . self::REJECTED_STATEMENTS . ') /i', $query ) ) {
			return 'rejected';
		}

		if ( preg_match( '/^\s*(' . self::ALLOWED_STATEMENTS . ') /i', $query ) ) {
			return $wpdb->query( $query ) ? 'ok' : 'failed';
		}

		return 'ignored';
	}
}
