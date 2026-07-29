<?php
/**
 * The DB Options screen.
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
 * @since 3.0.0
 */
class WP_DBManager_Settings {

	/**
	 * Settings group, which is the settings row name (section 2.2).
	 */
	const GROUP = 'wp_dbmanager_options';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the option.
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
	}

	/**
	 * Validate a submitted screen and merge it over the stored settings.
	 *
	 * Merging rather than replacing means a key this screen does not render
	 * cannot be blanked by saving it.
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
	}

	/**
	 * Render one "Every N <period>" control.
	 *
	 * @param string $key   Setting prefix, one of backup, optimize or repair.
	 * @param array  $values Current settings.
	 * @return void
	 */
	protected static function render_schedule( $key, $values ) {
		$option = WP_DBManager_Options::OPTION;
		?>
		<?php esc_html_e( 'Every', 'wp-dbmanager' ); ?>&nbsp;<input type="text" name="<?php echo esc_attr( $option . '[' . $key . ']' ); ?>" size="3" maxlength="5" value="<?php echo esc_attr( $values[ $key ] ); ?>" />&nbsp;
		<select name="<?php echo esc_attr( $option . '[' . $key . '_period]' ); ?>" size="1">
			<?php foreach ( WP_DBManager_Options::periods() as $seconds => $label ) : ?>
				<option value="<?php echo esc_attr( $seconds ); ?>"<?php selected( $seconds, (int) $values[ $key . '_period' ] ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
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

		if ( $next ) {
			echo '<strong>' . esc_html( WP_DBManager::format_timestamp( $next ) ) . '</strong>';
		} else {
			esc_html_e( 'N/A', 'wp-dbmanager' );
		}
	}

	/**
	 * Render the DB Options screen.
	 *
	 * @return void
	 */
	public static function render() {
		WP_DBManager_Admin::check_capability();

		$option = WP_DBManager_Options::OPTION;
		$values = WP_DBManager_Options::get();
		?>
<div class="wrap">
	<h2><?php esc_html_e( 'Database Options', 'wp-dbmanager' ); ?></h2>
	<form method="post" action="options.php">
		<?php
		settings_fields( self::GROUP );

		// Deliberately unfiltered. options.php registers its "Settings saved."
		// under the 'general' slug rather than under the option being saved, so
		// passing the option name here shows the validation errors and silently
		// swallows the confirmation - the screen saves and says nothing at all,
		// where it used to say "Database Options Updated".
		settings_errors();
		?>
		<h3><?php esc_html_e( 'Paths', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td width="20%" valign="top"><strong><?php esc_html_e( 'Path To mysqldump:', 'wp-dbmanager' ); ?></strong></td>
				<td width="80%">
					<input type="text" id="db_mysqldumppath" name="<?php echo esc_attr( $option . '[mysqldumppath]' ); ?>" size="60" maxlength="100" value="<?php echo esc_attr( $values['mysqldumppath'] ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php esc_attr_e( 'Auto Detect', 'wp-dbmanager' ); ?>" data-dbmanager-detect="mysqldump" />
					<p><?php esc_html_e( 'The absolute path to mysqldump without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Path To mysql:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" id="db_mysqlpath" name="<?php echo esc_attr( $option . '[mysqlpath]' ); ?>" size="60" maxlength="100" value="<?php echo esc_attr( $values['mysqlpath'] ); ?>" dir="ltr" />&nbsp;&nbsp;<input type="button" value="<?php esc_attr_e( 'Auto Detect', 'wp-dbmanager' ); ?>" data-dbmanager-detect="mysql" />
					<p><?php esc_html_e( 'The absolute path to mysql without trailing slash. If unsure, please email your server administrator about this.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Path To Backup:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" name="<?php echo esc_attr( $option . '[path]' ); ?>" size="60" maxlength="200" value="<?php echo esc_attr( $values['path'] ); ?>" dir="ltr" />
					<p><?php esc_html_e( 'The absolute path to your database backup folder without trailing slash. Make sure the folder is writable.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Maximum Backup Files:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<input type="text" name="<?php echo esc_attr( $option . '[max_backup]' ); ?>" size="5" maxlength="5" value="<?php echo esc_attr( $values['max_backup'] ); ?>" />
					<p><?php esc_html_e( 'The maximum number of database backup files that is allowed in the backup folder as stated above. The oldest database backup file is always deleted in order to maintain this value. This is to prevent the backup folder from getting too large.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Note', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td>
					<strong><?php esc_html_e( 'Windows Server', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'For mysqldump path, you can try \'mysqldump.exe\'.', 'wp-dbmanager' ); ?><br />
					<?php esc_html_e( 'For mysql path, you can try \'mysql.exe\'.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php esc_html_e( 'Linux Server', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'For mysqldump path, normally is just \'mysqldump\'.', 'wp-dbmanager' ); ?><br />
					<?php esc_html_e( 'For mysql path, normally is just \'mysql\'.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<strong><?php esc_html_e( 'Note', 'wp-dbmanager' ); ?></strong><br />
					<?php esc_html_e( 'The \'Auto Detect\' function does not work for some servers. If it does not work for you, please contact your server administrator for the MYSQL and MYSQL DUMP paths.', 'wp-dbmanager' ); ?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Automatic Scheduling', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Backing Up Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
					esc_html_e( 'Next backup date: ', 'wp-dbmanager' );
					self::render_next_run( 'dbmanager_cron_backup' );
					?>
					<p>
						<?php self::render_schedule( 'backup', $values ); ?>&nbsp;&nbsp;&nbsp;
						<?php esc_html_e( 'Gzip', 'wp-dbmanager' ); ?>
						<select name="<?php echo esc_attr( $option . '[backup_gzip]' ); ?>" size="1">
							<option value="0"<?php selected( 0, (int) $values['backup_gzip'] ); ?>><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></option>
							<option value="1"<?php selected( 1, (int) $values['backup_gzip'] ); ?>><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></option>
						</select>
					</p>
					<p><?php esc_html_e( 'WP-DBManager can automatically backup your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Optimizing Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
					esc_html_e( 'Next optimize date: ', 'wp-dbmanager' );
					self::render_next_run( 'dbmanager_cron_optimize' );
					?>
					<p><?php self::render_schedule( 'optimize', $values ); ?></p>
					<p><?php esc_html_e( 'WP-DBManager can automatically optimize your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Automatic Repairing Of DB:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<?php
					esc_html_e( 'Next repair date: ', 'wp-dbmanager' );
					self::render_next_run( 'dbmanager_cron_repair' );
					?>
					<p><?php self::render_schedule( 'repair', $values ); ?></p>
					<p><?php esc_html_e( 'WP-DBManager can automatically repair your database after a certain period.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Backup Email Options', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'To', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="<?php echo esc_attr( $option . '[backup_email]' ); ?>" size="30" maxlength="250" placeholder="<?php esc_attr_e( 'To E-mail', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email'] ); ?>" dir="ltr" />
					</p>
					<p><?php esc_html_e( '(Leave blank to disable this feature)', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Attach Backup File', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="radio" id="db_backup_email_attach-yes" name="<?php echo esc_attr( $option . '[backup_email_attach]' ); ?>" value="1"<?php checked( 1, (int) $values['backup_email_attach'] ); ?> />&nbsp;<label for="db_backup_email_attach-yes"><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="db_backup_email_attach-no" name="<?php echo esc_attr( $option . '[backup_email_attach]' ); ?>" value="0"<?php checked( 0, (int) $values['backup_email_attach'] ); ?> />&nbsp;<label for="db_backup_email_attach-no"><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label>
					</p>
					<p><?php esc_html_e( 'Attaches the database backup file to the scheduled backup e-mail. The e-mail always includes the file name, checksum, date and size. Choose \'No\' to keep a copy of your database out of your mailbox.', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'From', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="<?php echo esc_attr( $option . '[backup_email_from_name]' ); ?>" size="60" maxlength="250" placeholder="<?php esc_attr_e( 'From Name', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_from_name'] ); ?>" dir="ltr" />&nbsp;
						&lt;<input type="text" name="<?php echo esc_attr( $option . '[backup_email_from]' ); ?>" size="30" maxlength="250" placeholder="<?php esc_attr_e( 'From E-mail', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_from'] ); ?>" dir="ltr" />&gt;
					</p>
					<p><?php esc_html_e( '(Leave blank to use the default)', 'wp-dbmanager' ); ?></p>
				</td>
			</tr>
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Subject:', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="text" name="<?php echo esc_attr( $option . '[backup_email_subject]' ); ?>" size="90" maxlength="255" placeholder="<?php esc_attr_e( 'Subject', 'wp-dbmanager' ); ?>" value="<?php echo esc_attr( $values['backup_email_subject'] ); ?>" dir="ltr" />
					</p>
					<p>
						<?php esc_html_e( '(Leave blank to use the default). These are replaced:', 'wp-dbmanager' ); ?>
						<code>%SITE_NAME%</code> <code>%POST_DATE%</code> <code>%POST_TIME%</code>
					</p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Miscellaneous Options', 'wp-dbmanager' ); ?></h3>
		<table class="form-table">
			<tr>
				<td valign="top"><strong><?php esc_html_e( 'Hide Admin Notices', 'wp-dbmanager' ); ?></strong></td>
				<td>
					<p>
						<input type="radio" id="db_hide_admin_notices-yes" name="<?php echo esc_attr( $option . '[hide_admin_notices]' ); ?>" value="1"<?php checked( 1, (int) $values['hide_admin_notices'] ); ?> />&nbsp;<label for="db_hide_admin_notices-yes"><?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>
						<input type="radio" id="db_hide_admin_notices-no" name="<?php echo esc_attr( $option . '[hide_admin_notices]' ); ?>" value="0"<?php checked( 0, (int) $values['hide_admin_notices'] ); ?> />&nbsp;<label for="db_hide_admin_notices-no"><?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
		<?php
	}
}
