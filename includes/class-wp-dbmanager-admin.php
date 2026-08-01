<?php
/**
 * The Database menu and the wiring behind it.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu, its assets and the backup folder notice.
 *
 * @since 3.0.0
 */
class WP_DBManager_Admin {

	/**
	 * Top-level menu and page slug.
	 */
	const PAGE = 'wp-dbmanager';

	/**
	 * Capability required for every screen this plugin owns.
	 *
	 * Deliberately install_plugins rather than the manage_options a settings-only
	 * plugin would use: these screens restore, empty and drop tables, so reaching
	 * them is as consequential as installing code. Section 2.7 keeps a plugin's
	 * existing custom capability for its data screens, and this one is the reason
	 * that clause exists.
	 */
	const CAPABILITY = 'install_plugins';

	/**
	 * The admin pages this plugin owns, as key => menu slug.
	 *
	 * Before 3.0.0 these were the plugin's own PHP files, passed to
	 * add_menu_page() in the legacy "plugin file as menu slug" form. That put the
	 * directory name into every admin URL and into the hook suffix handed back to
	 * admin_enqueue_scripts, so installing the plugin under any other folder name
	 * broke the menu. They are ordinary slugs now, and they live in one method so
	 * the menu and the asset loader cannot drift apart.
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			'manager'  => self::PAGE,
			'backup'   => self::PAGE . '-backup',
			'manage'   => self::PAGE . '-manage',
			'optimize' => self::PAGE . '-optimize',
			'repair'   => self::PAGE . '-repair',
			'empty'    => self::PAGE . '-empty',
			'run'      => self::PAGE . '-run',
			'options'  => self::PAGE . '-options',
		);
	}

	/**
	 * The admin URL of one of the plugin's pages.
	 *
	 * @param string $key   Page key from pages().
	 * @param array  $extra Extra query arguments.
	 * @return string
	 */
	public static function page_url( $key, $extra = array() ) {
		$pages = self::pages();
		$slug  = isset( $pages[ $key ] ) ? $pages[ $key ] : $pages['manager'];

		return add_query_arg(
			array_merge( array( 'page' => $slug ), $extra ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the Database menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$pages = self::pages();
		$cap   = self::capability( 'menu' );

		add_menu_page(
			__( 'Database', 'wp-dbmanager' ),
			__( 'WP-DBManager', 'wp-dbmanager' ),
			$cap,
			$pages['manager'],
			array( 'WP_DBManager_Screens', 'manager' ),
			'dashicons-archive'
		);

		// The method name is carried explicitly rather than reused from the key:
		// the Empty/Drop screen cannot be WP_DBManager_Screens::empty(), because
		// empty is a language construct rather than an ordinary function name.
		$submenus = array(
			'backup'   => array( __( 'Backup DB', 'wp-dbmanager' ), 'backup' ),
			'manage'   => array( __( 'Manage Backup DB', 'wp-dbmanager' ), 'manage' ),
			'optimize' => array( __( 'Optimize DB', 'wp-dbmanager' ), 'optimize' ),
			'repair'   => array( __( 'Repair DB', 'wp-dbmanager' ), 'repair' ),
			'empty'    => array( __( 'Empty/Drop Tables', 'wp-dbmanager' ), 'empty_tables' ),
			'run'      => array( __( 'Run SQL Query', 'wp-dbmanager' ), 'run' ),
		);

		foreach ( $submenus as $key => $submenu ) {
			list( $label, $method ) = $submenu;

			add_submenu_page(
				$pages['manager'],
				$label,
				$label,
				$cap,
				$pages[ $key ],
				array( 'WP_DBManager_Screens', $method )
			);
		}

		add_submenu_page(
			$pages['manager'],
			__( 'Settings', 'wp-dbmanager' ),
			__( 'Settings', 'wp-dbmanager' ),
			$cap,
			$pages['options'],
			array( 'WP_DBManager_Settings', 'render' )
		);
	}

	/**
	 * Load the plugin's script on the screens that use it.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		$pages = self::pages();
		$mine  = false;

		foreach ( $pages as $slug ) {
			if ( false !== strpos( $hook_suffix, $slug ) ) {
				$mine = true;
				break;
			}
		}

		if ( ! $mine ) {
			return;
		}

		wp_enqueue_script(
			'wp-dbmanager',
			WP_DBMANAGER_URL . 'js/wp-dbmanager.js',
			array(),
			WP_DBMANAGER_VERSION,
			true
		);

		// Only the options screen needs the detected paths, and detection shells
		// out, so it is not done for the other seven.
		if ( false !== strpos( $hook_suffix, $pages['options'] ) ) {
			wp_localize_script( 'wp-dbmanager', 'wpDBManagerL10n', WP_DBManager_Database::detect_binaries() );
		}
	}

	/**
	 * A sorting argument from the query string.
	 *
	 * Both list tables need this and both used to read $_GET for themselves,
	 * which meant the same "a sort is not a state change" justification written
	 * out twice, in two files. It lives here once instead. The value is only
	 * ever a column name, and each caller checks it against its own
	 * get_sortable_columns() before anything is sorted by it.
	 *
	 * @param string $key      Query argument, one of orderby or order.
	 * @param string $fallback Value to use when the argument is absent.
	 * @return string
	 */
	public static function sort_arg( $key, $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The sniff asks for a nonce before request data is read, which is about state-changing requests. This chooses which column an already-rendered admin screen is sorted by, writes nothing, and is validated against that table's sortable columns by the caller.
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';

		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Render one line of status as a core notice.
	 *
	 * The screens used to colour these by hand with inline style attributes,
	 * which meant green and red and nothing else - no icon, no dark mode, and
	 * nothing a screen reader could make anything of. Core's notice classes
	 * carry the same meaning and are the only ones section 4.4 allows.
	 *
	 * @param string $type One of success, error, warning or info.
	 * @param string $text Plain text, escaped here at the point of output.
	 * @return void
	 */
	public static function render_status( $type, $text ) {
		$allowed = array( 'success', 'error', 'warning', 'info' );

		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( in_array( $type, $allowed, true ) ? $type : 'error' ),
			esc_html( $text )
		);
	}

	/**
	 * Render the result messages for an admin screen.
	 *
	 * Messages are collected as plain text and escaped at the point of output,
	 * rather than being assembled into HTML by each caller.
	 *
	 * @param array $messages List of arrays with a type of success, info or error, and a text key.
	 * @return void
	 */
	public static function render_messages( $messages ) {
		foreach ( (array) $messages as $message ) {
			self::render_status(
				isset( $message['type'] ) ? $message['type'] : 'error',
				isset( $message['text'] ) ? $message['text'] : ''
			);
		}
	}

	/**
	 * The capability required for one of the plugin's actions.
	 *
	 * Every check in the plugin goes through here, so a site that wants to hand
	 * the Database screens to somebody who is not an administrator has one place
	 * to say so, and cannot accidentally loosen one entry point while leaving
	 * another shut.
	 *
	 * @param string $context What is being gated: a page key from pages(), or
	 *                        one of 'notice', 'download' or 'fix'.
	 * @return string
	 */
	public static function capability( $context = '' ) {
		/**
		 * Filters the capability required to reach WP-DBManager.
		 *
		 * The default is install_plugins rather than manage_options because
		 * these screens restore, empty and drop tables.
		 *
		 * @since 4.0.0
		 *
		 * @param string $capability Capability required.
		 * @param string $context    What is being gated.
		 */
		return apply_filters( 'wp_dbmanager_capability', self::CAPABILITY, $context );
	}

	/**
	 * Whether the current user may carry out one of the plugin's actions.
	 *
	 * @param string $context What is being gated.
	 * @return bool
	 */
	public static function current_user_can( $context = '' ) {
		return current_user_can( self::capability( $context ) );
	}

	/**
	 * Stop a screen dead unless the current user may be there.
	 *
	 * @param string $context Page key from pages().
	 * @return void
	 */
	public static function check_capability( $context = '' ) {
		if ( ! self::current_user_can( $context ) ) {
			wp_die( esc_html__( 'Access Denied', 'wp-dbmanager' ) );
		}
	}

	/**
	 * Warn about a backup folder that is not writable or is publicly reachable.
	 *
	 * @return void
	 */
	public static function notices() {
		// The notice names the backup folder and links to a screen only these
		// users can reach.
		if ( ! self::current_user_can( 'notice' ) ) {
			return;
		}

		$options = WP_DBManager_Options::get();

		if ( empty( $options['path'] ) ) {
			return;
		}

		if ( 0 !== (int) $options['hide_admin_notices'] ) {
			return;
		}

		$writable = is_dir( $options['path'] ) && wp_is_writable( $options['path'] );
		$indexed  = file_exists( $options['path'] . '/index.php' );

		// Read the cached answer only. The Backup screen makes the actual request;
		// there is no reason to hold up an unrelated admin page for it.
		$is_public = WP_DBManager_Folder::is_public( false );

		if ( $writable && $indexed && true !== $is_public ) {
			return;
		}

		echo '<div class="notice notice-error">';

		if ( ! $writable ) {
			echo '<p><strong>' . esc_html__( 'Your backup folder is NOT writable', 'wp-dbmanager' ) . '</strong></p>';
			echo '<p>';
			printf(
				/* translators: %s: backup folder path. */
				esc_html__( 'To correct this issue, make the folder %s writable.', 'wp-dbmanager' ),
				'<strong>' . esc_html( $options['path'] ) . '</strong>'
			);
			echo '</p>';
		}

		if ( true === $is_public ) {
			echo '<p><strong>' . esc_html__( 'Your backup folder is visible to the public', 'wp-dbmanager' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Anyone who guesses a backup file name can download your entire database. Move the backup folder outside your web root under Database -> Settings.', 'wp-dbmanager' ) . '</p>';
		}

		if ( ! $indexed ) {
			echo '<p>';
			printf(
				/* translators: 1: source file path, 2: destination file path. */
				esc_html__( 'To correct this issue, move the file from %1$s to %2$s', 'wp-dbmanager' ),
				'<strong>' . esc_html( WP_DBMANAGER_DIR . 'index.php' ) . '</strong>',
				'<strong>' . esc_html( $options['path'] . '/index.php' ) . '</strong>'
			);
			echo '</p>';
		}

		$fix_url = wp_nonce_url( self::page_url( 'backup', array( 'try_fix' => 1 ) ), 'wp-dbmanager_fix' );

		echo '<p>';
		printf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag. */
			esc_html__( '%1$sClick here%2$s to let WP-DBManager try to fix it', 'wp-dbmanager' ),
			'<a href="' . esc_url( $fix_url ) . '">',
			'</a>'
		);
		echo '</p>';

		echo '</div>';
	}
}
