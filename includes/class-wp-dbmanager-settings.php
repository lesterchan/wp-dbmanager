<?php
/**
 * The Settings screen.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the settings screen.
 *
 * Before 3.0.0 this was a hand-rolled <form> that read twenty $_POST keys,
 * wrote the option, and then cleared and re-created the three cron events
 * inline. The rescheduling has moved onto the option itself - see
 * WP_DBManager_Cron - so it now happens however the settings change, including
 * from WP-CLI, rather than only when somebody submits this particular form.
 *
 * 3.0.0 registered the setting but still printed its own
 * <table class="form-table"> rows. From 4.0.0 the whole screen is sections and
 * fields, so do_settings_sections() emits the table and a new setting is one
 * add_settings_field() call rather than a block of markup pasted from the row
 * above it (section 4.2).
 *
 * @since 3.0.0
 */
class WP_DBManager_Settings {

	/**
	 * Settings group, which is the settings row name (section 2.2).
	 */
	const GROUP = 'wp_dbmanager_options';

	/**
	 * The mysqldump, mysql and backup folder paths.
	 */
	const SECTION_PATHS = 'wp_dbmanager_paths';

	/**
	 * The three automatic schedules.
	 */
	const SECTION_SCHEDULE = 'wp_dbmanager_schedule';

	/**
	 * The scheduled backup e-mail.
	 */
	const SECTION_EMAIL = 'wp_dbmanager_email';

	/**
	 * Everything else.
	 */
	const SECTION_MISC = 'wp_dbmanager_misc';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * The settings page slug, which is the Settings submenu.
	 *
	 * @return string
	 */
	public static function page() {
		$pages = WP_DBManager_Admin::pages();

		return $pages['options'];
	}

	/**
	 * Register the option, its sections and its fields.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			WP_DBManager_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => WP_DBManager_Options::defaults(),
			)
		);

		$page = self::page();

		$sections = array(
			self::SECTION_PATHS    => array( __( 'Paths', 'wp-dbmanager' ), 'section_paths' ),
			self::SECTION_SCHEDULE => array( __( 'Automatic Scheduling', 'wp-dbmanager' ), '' ),
			self::SECTION_EMAIL    => array( __( 'Backup Email Options', 'wp-dbmanager' ), '' ),
			self::SECTION_MISC     => array( __( 'Miscellaneous Options', 'wp-dbmanager' ), '' ),
		);

		foreach ( $sections as $id => $section ) {
			list( $title, $callback ) = $section;

			add_settings_section(
				$id,
				$title,
				'' === $callback ? '__return_false' : array( __CLASS__, $callback ),
				$page
			);
		}

		$fields = array(
			'mysqldumppath'        => array( __( 'Path To mysqldump:', 'wp-dbmanager' ), self::SECTION_PATHS ),
			'mysqlpath'            => array( __( 'Path To mysql:', 'wp-dbmanager' ), self::SECTION_PATHS ),
			'path'                 => array( __( 'Path To Backup:', 'wp-dbmanager' ), self::SECTION_PATHS ),
			'max_backup'           => array( __( 'Maximum Backup Files:', 'wp-dbmanager' ), self::SECTION_PATHS ),
			'backup'               => array( __( 'Automatic Backing Up Of DB:', 'wp-dbmanager' ), self::SECTION_SCHEDULE ),
			'optimize'             => array( __( 'Automatic Optimizing Of DB:', 'wp-dbmanager' ), self::SECTION_SCHEDULE ),
			'repair'               => array( __( 'Automatic Repairing Of DB:', 'wp-dbmanager' ), self::SECTION_SCHEDULE ),
			'backup_email'         => array( _x( 'To', 'Label for the address a backup is mailed to', 'wp-dbmanager' ), self::SECTION_EMAIL ),
			'backup_email_attach'  => array( __( 'Attach Backup File', 'wp-dbmanager' ), self::SECTION_EMAIL ),
			'backup_email_from'    => array( _x( 'From', 'Label for the address a backup is mailed from', 'wp-dbmanager' ), self::SECTION_EMAIL ),
			'backup_email_subject' => array( __( 'Subject:', 'wp-dbmanager' ), self::SECTION_EMAIL ),
			'hide_admin_notices'   => array( __( 'Hide Admin Notices', 'wp-dbmanager' ), self::SECTION_MISC ),
		);

		foreach ( $fields as $key => $field ) {
			list( $label, $section ) = $field;

			add_settings_field(
				$key,
				$label,
				array( __CLASS__, 'field_' . $key ),
				$page,
				$section
			);
		}
	}

	/**
	 * Validate a submitted screen and merge it over the stored settings.
	 *
	 * Merging rather than replacing means a key this screen does not render
	 * cannot be blanked by saving it.
	 *
	 * Nothing here reaches back for an upgrade marker, and nothing can: the
	 * markers live in their own row (section 2.1), so this is an honest function
	 * from what the form posted to what gets stored.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$values = WP_DBManager_Options::get();

		if ( ! is_array( $input ) ) {
			return $values;
		}

		$previous = $values;

		// Text-ish settings.
		if ( isset( $input['mysqldumppath'] ) ) {
			$values['mysqldumppath'] = sanitize_text_field( $input['mysqldumppath'] );
		}
		if ( isset( $input['mysqlpath'] ) ) {
			$values['mysqlpath'] = sanitize_text_field( $input['mysqlpath'] );
		}
		if ( isset( $input['path'] ) ) {
			$values['path'] = sanitize_text_field( $input['path'] );
		}
		if ( isset( $input['backup_email_from_name'] ) ) {
			$values['backup_email_from_name'] = sanitize_text_field( $input['backup_email_from_name'] );
		}
		if ( isset( $input['backup_email_subject'] ) ) {
			$values['backup_email_subject'] = sanitize_text_field( $input['backup_email_subject'] );
		}

		// Addresses.
		if ( isset( $input['backup_email'] ) ) {
			$values['backup_email'] = sanitize_email( $input['backup_email'] );
		}
		if ( isset( $input['backup_email_from'] ) ) {
			$values['backup_email_from'] = sanitize_email( $input['backup_email_from'] );
		}

		// Counts.
		foreach ( array( 'max_backup', 'backup', 'optimize', 'repair' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$values[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}

		// Flags.
		foreach ( array( 'backup_gzip', 'backup_email_attach', 'hide_admin_notices' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$values[ $key ] = 1 === (int) $input[ $key ] ? 1 : 0;
			}
		}

		// Intervals, taken from the same list the screen offers so it cannot
		// present a period this callback then silently rejects.
		$periods = array_keys( WP_DBManager_Options::periods() );

		foreach ( array( 'backup_period', 'optimize_period', 'repair_period' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$submitted      = (int) $input[ $key ];
				$values[ $key ] = in_array( $submitted, $periods, true ) ? $submitted : $previous[ $key ];
			}
		}

		self::validate_paths( $values, $previous );

		return $values;
	}

	/**
	 * Reject unusable paths and keep the previous value for each one.
	 *
	 * Each path is judged on its own, so a bad mysqldump path does not stop the
	 * backup folder being checked - otherwise a user with two mistakes fixes one
	 * per save and never finds out about the other.
	 *
	 * @param array $values   Sanitized values, modified in place.
	 * @param array $previous Previously stored values.
	 * @return void
	 */
	protected static function validate_paths( array &$values, array $previous ) {
		if ( realpath( $values['path'] ) === false ) {
			add_settings_error(
				WP_DBManager_Options::OPTION,
				'path',
				/* translators: %s: configured backup folder path. */
				sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $values['path'] )
			);
			$values['path'] = $previous['path'];
		}

		if ( WP_DBManager_Database::is_valid_path( $values['mysqldumppath'] ) === 0 ) {
			add_settings_error(
				WP_DBManager_Options::OPTION,
				'mysqldumppath',
				/* translators: %s: configured mysqldump path. */
				sprintf( __( '%s is not a valid mysqldump path', 'wp-dbmanager' ), $values['mysqldumppath'] )
			);
			$values['mysqldumppath'] = $previous['mysqldumppath'];
		}

		if ( WP_DBManager_Database::is_valid_path( $values['mysqlpath'] ) === 0 ) {
			add_settings_error(
				WP_DBManager_Options::OPTION,
				'mysqlpath',
				/* translators: %s: configured mysql path. */
				sprintf( __( '%s is not a valid mysql path', 'wp-dbmanager' ), $values['mysqlpath'] )
			);
			$values['mysqlpath'] = $previous['mysqlpath'];
		}

		// The backup folder may have moved, so the cached reachability answer is
		// about somewhere else now.
		WP_DBManager_Folder::flush();

		/*
		 * A folder the site is moving to gets the protection the old one had.
		 * create() drops in the .htaccess or the Web.config, the
		 * silence-is-golden index.php, and the 0750 -- and it ran on activation
		 * and from the "try to fix" notice and nowhere else. So changing the path
		 * here pointed backups at a bare directory, and the shipped default is
		 * inside wp-content, which is served. A dump holds the users table.
		 *
		 * The new path is passed explicitly: this is a sanitize callback, so the
		 * row still holds the old one and create() reading the option would
		 * guard the folder the site has just stopped using.
		 */
		if ( $values['path'] !== $previous['path'] && '' !== $values['path'] ) {
			WP_DBManager_Folder::create( $values['path'] );
		}
	}

	/**
	 * The name attribute for one setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	protected static function name( $key ) {
		return WP_DBManager_Options::OPTION . '[' . $key . ']';
	}

	/**
	 * The stored settings.
	 *
	 * @return array
	 */
	protected static function values() {
		return WP_DBManager_Options::get();
	}

	/**
	 * The prose that used to sit in a "Note" table of its own.
	 *
	 * @return void
	 */
	public static function section_paths() {
		?>
		<p>
			<strong><?php esc_html_e( 'Windows Server', 'wp-dbmanager' ); ?></strong><br />
			<?php esc_html_e( 'For mysqldump path, you can try \'mysqldump.exe\'.', 'wp-dbmanager' ); ?><br />
			<?php esc_html_e( 'For mysql path, you can try \'mysql.exe\'.', 'wp-dbmanager' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Linux Server', 'wp-dbmanager' ); ?></strong><br />
			<?php esc_html_e( 'For mysqldump path, normally is just \'mysqldump\'.', 'wp-dbmanager' ); ?><br />
			<?php esc_html_e( 'For mysql path, normally is just \'mysql\'.', 'wp-dbmanager' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Note', 'wp-dbmanager' ); ?></strong><br />
			<?php esc_html_e( 'The \'Auto Detect\' function does not work for some servers. If it does not work for you, please contact your server administrator for the MYSQL and MYSQL DUMP paths.', 'wp-dbmanager' ); ?>
		</p>
		<?php
	}

	/**
	 * The mysqldump path, with an Auto Detect button.
	 *
	 * @return void
	 */
	public static function field_mysqldumppath() {
		$values = self::values();
		?>
		<input type="text" id="db_mysqldumppath" name="<?php echo esc_attr( self::name( 'mysqldumppath' ) ); ?>" class="regular-text code" maxlength="100" value="<?php echo esc_attr( $values['mysqldumppath'] ); ?>" dir="ltr" />
		<button type="button" class="button" data-dbmanager-detect="mysqldump"><?php esc_html_e( 'Auto Detect', 'wp-dbmanager' ); ?></button>
		<p class="description"><?php esc_html_e( 'The absolute path to mysqldump without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The mysql client path, with an Auto Detect button.
	 *
	 * @return void
	 */
	public static function field_mysqlpath() {
		$values = self::values();
		?>
		<input type="text" id="db_mysqlpath" name="<?php echo esc_attr( self::name( 'mysqlpath' ) ); ?>" class="regular-text code" maxlength="100" value="<?php echo esc_attr( $values['mysqlpath'] ); ?>" dir="ltr" />
		<button type="button" class="button" data-dbmanager-detect="mysql"><?php esc_html_e( 'Auto Detect', 'wp-dbmanager' ); ?></button>
		<p class="description"><?php esc_html_e( 'The absolute path to mysql without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The backup folder.
	 *
	 * @return void
	 */
	public static function field_path() {
		$values = self::values();
		?>
		<input type="text" name="<?php echo esc_attr( self::name( 'path' ) ); ?>" class="large-text code" maxlength="200" value="<?php echo esc_attr( $values['path'] ); ?>" dir="ltr" />
		<p class="description"><?php esc_html_e( 'The absolute path to your database backup folder without trailing slash. Make sure the folder is writable.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * How many backups to keep.
	 *
	 * @return void
	 */
	public static function field_max_backup() {
		$values = self::values();
		?>
		<input type="number" min="0" name="<?php echo esc_attr( self::name( 'max_backup' ) ); ?>" class="small-text" value="<?php echo esc_attr( $values['max_backup'] ); ?>" />
		<p class="description"><?php esc_html_e( 'The maximum number of database backup files that is allowed in the backup folder as stated above. The oldest database backup file is always deleted in order to maintain this value. This is to prevent the backup folder from getting too large.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The backup schedule, plus the gzip choice that belongs to it.
	 *
	 * @return void
	 */
	public static function field_backup() {
		$values = self::values();

		self::render_next_run( 'wp_dbmanager_cron_backup' );
		self::render_schedule( 'backup', $values );
		?>
		<p>
			<label for="db_backup_gzip"><?php esc_html_e( 'Gzip', 'wp-dbmanager' ); ?></label>
			<select id="db_backup_gzip" name="<?php echo esc_attr( self::name( 'backup_gzip' ) ); ?>">
				<option value="0"<?php selected( 0, (int) $values['backup_gzip'] ); ?>><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></option>
				<option value="1"<?php selected( 1, (int) $values['backup_gzip'] ); ?>><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></option>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'WP-DBManager can automatically backup your database after a certain period.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The optimize schedule.
	 *
	 * @return void
	 */
	public static function field_optimize() {
		self::render_next_run( 'wp_dbmanager_cron_optimize' );
		self::render_schedule( 'optimize', self::values() );
		?>
		<p class="description"><?php esc_html_e( 'WP-DBManager can automatically optimize your database after a certain period.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The repair schedule.
	 *
	 * @return void
	 */
	public static function field_repair() {
		self::render_next_run( 'wp_dbmanager_cron_repair' );
		self::render_schedule( 'repair', self::values() );
		?>
		<p class="description"><?php esc_html_e( 'WP-DBManager can automatically repair your database after a certain period.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * Where the scheduled backup e-mail goes.
	 *
	 * @return void
	 */
	public static function field_backup_email() {
		$values = self::values();
		?>
		<input type="email" name="<?php echo esc_attr( self::name( 'backup_email' ) ); ?>" class="regular-text" maxlength="250" placeholder="<?php esc_attr_e( 'To E-mail', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email'] ); ?>" dir="ltr" />
		<p class="description"><?php esc_html_e( '(Leave blank to disable this feature)', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * Whether the dump itself rides along.
	 *
	 * @return void
	 */
	public static function field_backup_email_attach() {
		$values = self::values();
		?>
		<fieldset>
			<label for="db_backup_email_attach-yes">
				<input type="radio" id="db_backup_email_attach-yes" name="<?php echo esc_attr( self::name( 'backup_email_attach' ) ); ?>" value="1"<?php checked( 1, (int) $values['backup_email_attach'] ); ?> />
				<?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?>
			</label>
			<label for="db_backup_email_attach-no">
				<input type="radio" id="db_backup_email_attach-no" name="<?php echo esc_attr( self::name( 'backup_email_attach' ) ); ?>" value="0"<?php checked( 0, (int) $values['backup_email_attach'] ); ?> />
				<?php esc_html_e( 'No', 'wp-dbmanager' ); ?>
			</label>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Attaches the database backup file to the scheduled backup e-mail. The e-mail always includes the file name, checksum, date and size. Choose \'No\' to keep a copy of your database out of your mailbox.', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * Who the scheduled backup e-mail comes from.
	 *
	 * @return void
	 */
	public static function field_backup_email_from() {
		$values = self::values();
		?>
		<input type="text" name="<?php echo esc_attr( self::name( 'backup_email_from_name' ) ); ?>" class="regular-text" maxlength="250" placeholder="<?php esc_attr_e( 'From Name', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_from_name'] ); ?>" dir="ltr" />
		<input type="email" name="<?php echo esc_attr( self::name( 'backup_email_from' ) ); ?>" class="regular-text" maxlength="250" placeholder="<?php esc_attr_e( 'From E-mail', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_from'] ); ?>" dir="ltr" />
		<p class="description"><?php esc_html_e( '(Leave blank to use the default)', 'wp-dbmanager' ); ?></p>
		<?php
	}

	/**
	 * The subject line template.
	 *
	 * @return void
	 */
	public static function field_backup_email_subject() {
		$values = self::values();
		?>
		<input type="text" name="<?php echo esc_attr( self::name( 'backup_email_subject' ) ); ?>" class="large-text" maxlength="255" placeholder="<?php esc_attr_e( 'Subject', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_subject'] ); ?>" dir="ltr" />
		<p class="description">
			<?php esc_html_e( '(Leave blank to use the default). These are replaced:', 'wp-dbmanager' ); ?>
			<code>%SITE_NAME%</code> <code>%POST_DATE%</code> <code>%POST_TIME%</code>
		</p>
		<?php
	}

	/**
	 * Whether the backup folder warning is shown.
	 *
	 * @return void
	 */
	public static function field_hide_admin_notices() {
		$values = self::values();
		?>
		<fieldset>
			<label for="db_hide_admin_notices-yes">
				<input type="radio" id="db_hide_admin_notices-yes" name="<?php echo esc_attr( self::name( 'hide_admin_notices' ) ); ?>" value="1"<?php checked( 1, (int) $values['hide_admin_notices'] ); ?> />
				<?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?>
			</label>
			<label for="db_hide_admin_notices-no">
				<input type="radio" id="db_hide_admin_notices-no" name="<?php echo esc_attr( self::name( 'hide_admin_notices' ) ); ?>" value="0"<?php checked( 0, (int) $values['hide_admin_notices'] ); ?> />
				<?php esc_html_e( 'No', 'wp-dbmanager' ); ?>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Render one "Every N <period>" control.
	 *
	 * @param string $key    Setting prefix, one of backup, optimize or repair.
	 * @param array  $values Current settings.
	 * @return void
	 */
	protected static function render_schedule( $key, $values ) {
		?>
		<p>
			<label for="<?php echo esc_attr( 'db_' . $key ); ?>"><?php esc_html_e( 'Every', 'wp-dbmanager' ); ?></label>
			<input type="number" min="0" id="<?php echo esc_attr( 'db_' . $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" class="small-text" value="<?php echo esc_attr( $values[ $key ] ); ?>" />
			<select name="<?php echo esc_attr( self::name( $key . '_period' ) ); ?>">
				<?php foreach ( WP_DBManager_Options::periods() as $seconds => $label ) : ?>
					<option value="<?php echo esc_attr( $seconds ); ?>"<?php selected( $seconds, (int) $values[ $key . '_period' ] ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Show when a scheduled job is next due.
	 *
	 * @param string $hook Cron hook.
	 * @return void
	 */
	protected static function render_next_run( $hook ) {
		$next = wp_next_scheduled( $hook );

		echo '<p>';
		esc_html_e( 'Next run:', 'wp-dbmanager' );
		echo ' ';

		if ( $next ) {
			echo '<strong>' . esc_html( WP_DBManager::format_timestamp( $next ) ) . '</strong>';
		} else {
			esc_html_e( 'N/A', 'wp-dbmanager' );
		}

		echo '</p>';
	}

	/**
	 * Render the Settings screen.
	 *
	 * @return void
	 */
	public static function render() {
		WP_DBManager_Admin::check_capability( 'options' );
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Database Settings', 'wp-dbmanager' ); ?></h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( self::GROUP );

		// Deliberately unfiltered. options.php registers its "Settings saved."
		// under the 'general' slug rather than under the option being saved, so
		// passing the option name here shows the validation errors and silently
		// swallows the confirmation - the screen saves and says nothing at all,
		// where it used to say "Database Settings Updated".
		settings_errors();

		do_settings_sections( self::page() );
		submit_button();
		?>
	</form>
</div>
		<?php
	}
}
