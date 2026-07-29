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
	 * install_plugins rather than the manage_options of a settings-only plugin,
	 * and deliberately so: these screens restore, empty and drop tables, so
	 * reaching them is as consequential as installing code. Section 2.7 keeps a
	 * plugin's existing custom capability for its data screens, and this one is
	 * the reason that clause exists.
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
		$cap   = self::CAPABILITY;

		add_menu_page(
			__( 'Database', 'wp-dbmanager' ),
			__( 'Database', 'wp-dbmanager' ),
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
			__( 'DB Options', 'wp-dbmanager' ),
			__( 'DB Options', 'wp-dbmanager' ),
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
	 * Render the result messages for an admin screen.
	 *
	 * Messages are collected as plain text and escaped here, at the point of
	 * output, rather than being assembled into HTML by each caller.
	 *
	 * @param array $messages List of arrays with a type of success, info or error, and a text key.
	 * @return void
	 */
	public static function render_messages( $messages ) {
		if ( empty( $messages ) ) {
			return;
		}

		$colors = array(
			'success' => 'green',
			'info'    => 'blue',
			'error'   => 'red',
		);

		echo '<div id="message" class="updated fade">';

		foreach ( $messages as $message ) {
			$type  = isset( $message['type'] ) ? $message['type'] : 'error';
			$color = isset( $colors[ $type ] ) ? $colors[ $type ] : 'red';

			printf( '<p style="color: %1$s;">%2$s</p>', esc_attr( $color ), esc_html( $message['text'] ) );
		}

		echo '</div>';
	}

	/**
	 * Stop a screen dead unless the current user may be there.
	 *
	 * @return void
	 */
	public static function check_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
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
		if ( ! current_user_can( self::CAPABILITY ) ) {
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

		echo '<div class="error">';

		if ( ! $writable ) {
			echo '<p style="font-weight: bold;">' . esc_html__( 'Your backup folder is NOT writable', 'wp-dbmanager' ) . '</p>';
			echo '<p>';
			printf(
				/* translators: %s: backup folder path. */
				esc_html__( 'To correct this issue, make the folder %s writable.', 'wp-dbmanager' ),
				'<strong>' . esc_html( $options['path'] ) . '</strong>'
			);
			echo '</p>';
		}

		if ( true === $is_public ) {
			echo '<p style="font-weight: bold;">' . esc_html__( 'Your backup folder is visible to the public', 'wp-dbmanager' ) . '</p>';
			echo '<p>' . esc_html__( 'Anyone who guesses a backup file name can download your entire database. Move the backup folder outside your web root under DB Options.', 'wp-dbmanager' ) . '</p>';
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
