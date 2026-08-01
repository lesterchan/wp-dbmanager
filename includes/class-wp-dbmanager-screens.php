<?php
/**
 * The seven admin screens.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Database screens.
 *
 * Each of these was a standalone PHP file included by admin.php at global
 * scope, which is why the pre-3.0.0 versions each wrapped themselves in a
 * function to stop their locals leaking into the global namespace. They are
 * ordinary menu callbacks now, and the work they used to do inline - building
 * shell commands, validating table names - has moved to the domain classes.
 *
 * @since 3.0.0
 */
class WP_DBManager_Screens {

	/**
	 * The Database information screen.
	 *
	 * @return void
	 */
	public static function manager() {
		WP_DBManager_Admin::check_capability( 'manager' );

		$version = WP_DBManager_Tables::version();

		$information = array(
			__( 'Database Host', 'wp-dbmanager' )    => DB_HOST,
			__( 'Database Name', 'wp-dbmanager' )    => DB_NAME,
			__( 'Database User', 'wp-dbmanager' )    => DB_USER,
			__( 'Database Type', 'wp-dbmanager' )    => 'MYSQL',
			__( 'Database Version', 'wp-dbmanager' ) => 'v' . $version,
		);
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Database', 'wp-dbmanager' ); ?></h1>

	<h2><?php esc_html_e( 'Database Information', 'wp-dbmanager' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'wp-dbmanager' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'wp-dbmanager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $information as $label => $value ) : ?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo esc_html( $value ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Tables Information', 'wp-dbmanager' ); ?></h2>
		<?php
		$tables = new WP_DBManager_Tables_Table( 'info' );
		$tables->prepare_items();
		$tables->display();
		?>
</div>
		<?php
	}

	/**
	 * The Backup Database screen.
	 *
	 * @return void
	 */
	public static function backup() {
		WP_DBManager_Admin::check_capability( 'backup' );

		$options      = WP_DBManager_Options::get();
		$current_date = WP_DBManager::format_timestamp( time() );
		$backup_path  = $options['path'];
		$messages     = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_backup' );

			if ( __( 'Backup', 'wp-dbmanager' ) === $action ) {
				$gzip   = isset( $_POST['gzip'] ) ? 1 === (int) $_POST['gzip'] : false;
				$result = WP_DBManager_Database::backup( $gzip );

				$failures = array(
					/* translators: %s: date and time of the attempt. */
					'path'    => __( 'Database Failed To Backup On \'%s\'. Backup Folder Not Writable.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'empty'   => __( 'Database Failed To Backup On \'%s\'. Backup File Size Is 0KB.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'missing' => __( 'Database Failed To Backup On \'%s\'. Invalid Backup File Path.', 'wp-dbmanager' ),
					/* translators: %s: date and time of the attempt. */
					'command' => __( 'Database Failed To Backup On \'%s\'.', 'wp-dbmanager' ),
				);

				if ( $result['success'] ) {
					$messages[] = array(
						'type' => 'success',
						/* translators: %s: date and time of the backup. */
						'text' => sprintf( __( 'Database Backed Up Successfully On \'%s\'.', 'wp-dbmanager' ), $current_date ),
					);
				} else {
					$reason     = isset( $failures[ $result['reason'] ] ) ? $failures[ $result['reason'] ] : $failures['command'];
					$messages[] = array(
						'type' => 'error',
						'text' => sprintf( $reason, $current_date ),
					);
				}
			}
		}

		$backup_date     = time();
		$backup_filename = $backup_date . '_-_' . DB_NAME . '.sql';
		$backup_gzip     = (int) $options['backup_gzip'];

		$has_error         = false;
		$disabled_function = false;

		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Backup Database', 'wp-dbmanager' ); ?></h1>
		<?php WP_DBManager_Admin::render_messages( $messages ); ?>

	<h2><?php esc_html_e( 'Checking Security Status', 'wp-dbmanager' ); ?></h2>
		<?php
		$server_type = WP_DBManager_Folder::server_type();
		$backup_url  = WP_DBManager_Folder::url();

		// Ask the server whether the folder is reachable, rather than assuming a
		// dropped in file did the job. On nginx it did not.
		$is_public = WP_DBManager_Folder::is_public();

		if ( false === $backup_url ) {
			WP_DBManager_Admin::render_status( 'success', __( 'Your backup folder is outside the web root, so it cannot be downloaded over HTTP.', 'wp-dbmanager' ) );
		} else {
			printf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: public URL of the backup folder. */
					esc_html__( 'Your backup folder is inside the web root, at %s', 'wp-dbmanager' ),
					'<strong>' . esc_html( $backup_url ) . '</strong>'
				)
			);

			if ( true === $is_public ) {
				WP_DBManager_Admin::render_status( 'error', __( 'The backup folder responds over HTTP. Anyone who guesses a backup file name can download your entire database.', 'wp-dbmanager' ) );
				$has_error = true;
			} elseif ( false === $is_public ) {
				WP_DBManager_Admin::render_status( 'success', __( 'The backup folder does not respond over HTTP.', 'wp-dbmanager' ) );
			} else {
				WP_DBManager_Admin::render_status( 'warning', __( 'Could not determine whether the backup folder responds over HTTP. Check it yourself, or move the folder outside the web root.', 'wp-dbmanager' ) );
				$has_error = true;
			}

			if ( 'nginx' === $server_type ) {
				WP_DBManager_Admin::render_status( 'error', __( 'This site runs on nginx, which ignores .htaccess files. No file placed in the backup folder can protect it.', 'wp-dbmanager' ) );
				printf( '<p>%s</p>', esc_html__( 'Move the backup folder outside your web root under Database -> Settings, or add this to your nginx server block:', 'wp-dbmanager' ) );
				$backup_uri = wp_parse_url( $backup_url, PHP_URL_PATH );
				echo '<pre dir="ltr"><code>location ^~ ' . esc_html( trailingslashit( $backup_uri ) ) . ' { deny all; }</code></pre>';
			}

			if ( 'iis' === $server_type ) {
				if ( is_file( $backup_path . '/Web.config' ) ) {
					/* translators: %s: backup folder path. */
					WP_DBManager_Admin::render_status( 'success', sprintf( __( 'Web.config is present in %s', 'wp-dbmanager' ), $backup_path ) );
				} else {
					/* translators: %s: backup folder path. */
					WP_DBManager_Admin::render_status( 'error', sprintf( __( 'Web.config is missing from %s', 'wp-dbmanager' ), $backup_path ) );
					$has_error = true;
				}
			} elseif ( 'apache' === $server_type ) {
				if ( is_file( $backup_path . '/.htaccess' ) ) {
					/* translators: %s: backup folder path. */
					WP_DBManager_Admin::render_status( 'success', sprintf( __( '.htaccess is present in %s', 'wp-dbmanager' ), $backup_path ) );
				} else {
					/* translators: %s: backup folder path. */
					WP_DBManager_Admin::render_status( 'error', sprintf( __( '.htaccess is missing from %s', 'wp-dbmanager' ), $backup_path ) );
					$has_error = true;
				}
			}
		}

		if ( is_file( $backup_path . '/index.php' ) ) {
			/* translators: %s: backup folder path. */
			WP_DBManager_Admin::render_status( 'success', sprintf( __( 'index.php is present in %s', 'wp-dbmanager' ), $backup_path ) );
		} else {
			/* translators: %s: backup folder path. */
			WP_DBManager_Admin::render_status( 'error', sprintf( __( 'index.php is missing from %s', 'wp-dbmanager' ), $backup_path ) );
			$has_error = true;
		}
		?>

	<h2><?php esc_html_e( 'Checking Backup Status', 'wp-dbmanager' ); ?></h2>
	<p>
		<?php esc_html_e( 'Checking Backup Folder', 'wp-dbmanager' ); ?> <span dir="ltr">(<strong><?php echo esc_html( $backup_path ); ?></strong>)</span>
	</p>
		<?php
		if ( realpath( $backup_path ) === false ) {
			/* translators: %s: configured backup folder path. */
			WP_DBManager_Admin::render_status( 'error', sprintf( __( '%s is not a valid backup path', 'wp-dbmanager' ), $backup_path ) );
			$has_error = true;
		} else {
			if ( is_dir( $backup_path ) ) {
				WP_DBManager_Admin::render_status( 'success', __( 'Backup folder exists', 'wp-dbmanager' ) );
			} else {
				/* translators: %s: wp-content directory path. */
				WP_DBManager_Admin::render_status( 'error', sprintf( __( 'Backup folder does NOT exist. Please create \'backup-db\' folder in \'%s\' folder and CHMOD it to \'777\' or change the location of the backup folder under DB Option.', 'wp-dbmanager' ), WP_CONTENT_DIR ) );
				$has_error = true;
			}

			if ( wp_is_writable( $backup_path ) ) {
				WP_DBManager_Admin::render_status( 'success', __( 'Backup folder is writable', 'wp-dbmanager' ) );
			} else {
				WP_DBManager_Admin::render_status( 'error', __( 'Backup folder is NOT writable. Please CHMOD it to \'777\'.', 'wp-dbmanager' ) );
				$has_error = true;
			}
		}

		if ( WP_DBManager_Database::is_valid_path( $options['mysqldumppath'] ) === 0 ) {
			/* translators: %s: configured mysqldump path. */
			WP_DBManager_Admin::render_status( 'error', sprintf( __( '%s is not a valid backup mysqldump path', 'wp-dbmanager' ), $options['mysqldumppath'] ) );
			$has_error = true;
		} else {
			printf(
				'<p>%1$s <span dir="ltr">(<strong>%2$s</strong>)</span></p>',
				esc_html__( 'Checking MYSQL Dump Path', 'wp-dbmanager' ),
				esc_html( $options['mysqldumppath'] )
			);

			if ( file_exists( $options['mysqldumppath'] ) ) {
				WP_DBManager_Admin::render_status( 'success', __( 'MYSQL dump path exists.', 'wp-dbmanager' ) );
			} else {
				WP_DBManager_Admin::render_status( 'error', __( 'MYSQL dump path does NOT exist. Please check your mysqldump path under Database -> Settings. If uncertain, contact your server administrator.', 'wp-dbmanager' ) );
				$has_error = true;
			}
		}

		if ( WP_DBManager_Database::is_valid_path( $options['mysqlpath'] ) === 0 ) {
			/* translators: %s: configured mysql path. */
			WP_DBManager_Admin::render_status( 'error', sprintf( __( '%s is not a valid backup mysql path', 'wp-dbmanager' ), $options['mysqlpath'] ) );
			$has_error = true;
		} else {
			printf(
				'<p>%1$s <span dir="ltr">(<strong>%2$s</strong>)</span></p>',
				esc_html__( 'Checking MYSQL Path', 'wp-dbmanager' ),
				esc_html( $options['mysqlpath'] )
			);

			if ( file_exists( $options['mysqlpath'] ) ) {
				WP_DBManager_Admin::render_status( 'success', __( 'MYSQL path exists.', 'wp-dbmanager' ) );
			} else {
				WP_DBManager_Admin::render_status( 'error', __( 'MYSQL path does NOT exist. Please check your mysql path under Database -> Settings. If uncertain, contact your server administrator.', 'wp-dbmanager' ) );
				$has_error = true;
			}
		}
		?>
	<p>
		<?php esc_html_e( 'Checking PHP Functions', 'wp-dbmanager' ); ?> <span dir="ltr">(<strong>passthru()</strong>, <strong>system()</strong> <?php esc_html_e( 'and', 'wp-dbmanager' ); ?> <strong>exec()</strong>)</span>
	</p>
		<?php
		foreach ( array( 'passthru', 'system', 'exec' ) as $function_name ) {
			if ( WP_DBManager_Database::is_function_disabled( $function_name ) ) {
				/* translators: %s: PHP function name. */
				WP_DBManager_Admin::render_status( 'error', sprintf( __( '%s() disabled.', 'wp-dbmanager' ), $function_name ) );
				$disabled_function = true;
			} elseif ( ! function_exists( $function_name ) ) {
				/* translators: %s: PHP function name. */
				WP_DBManager_Admin::render_status( 'error', sprintf( __( '%s() missing.', 'wp-dbmanager' ), $function_name ) );
				$disabled_function = true;
			} else {
				/* translators: %s: PHP function name. */
				WP_DBManager_Admin::render_status( 'success', sprintf( __( '%s() enabled.', 'wp-dbmanager' ), $function_name ) );
			}
		}

		if ( $disabled_function ) {
			WP_DBManager_Admin::render_status( 'error', __( 'I\'m sorry, your server administrator has disabled passthru(), system() and/or exec(), thus you cannot use this plugin. Please find an alternative plugin.', 'wp-dbmanager' ) );
		} elseif ( $has_error ) {
			WP_DBManager_Admin::render_status( 'error', __( 'Please Rectify The Errors Above Before Proceeding On.', 'wp-dbmanager' ) );
		} else {
			WP_DBManager_Admin::render_status( 'success', __( 'Excellent. You Are Good To Go.', 'wp-dbmanager' ) );
		}
		?>
	<p><em><?php esc_html_e( 'Note: The checking of backup status is still undergoing testing, it may not be accurate.', 'wp-dbmanager' ); ?></em></p>

	<h2><?php esc_html_e( 'Backup Database', 'wp-dbmanager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( WP_DBManager_Admin::page_url( 'backup' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_backup' ); ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Option', 'wp-dbmanager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Value', 'wp-dbmanager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Name:', 'wp-dbmanager' ); ?></th>
					<td><?php echo esc_html( DB_NAME ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Backup To:', 'wp-dbmanager' ); ?></th>
					<td><span dir="ltr"><?php echo esc_html( $backup_path ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Backup Date:', 'wp-dbmanager' ); ?></th>
					<td><?php echo esc_html( WP_DBManager::format_timestamp( $backup_date ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Backup File Name:', 'wp-dbmanager' ); ?></th>
					<td><span dir="ltr"><?php echo esc_html( $backup_filename ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Backup Type:', 'wp-dbmanager' ); ?></th>
					<td><?php esc_html_e( 'Full (Structure and Data)', 'wp-dbmanager' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MYSQL Dump Location:', 'wp-dbmanager' ); ?></th>
					<td><span dir="ltr"><?php echo esc_html( $options['mysqldumppath'] ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'GZIP Database Backup File?', 'wp-dbmanager' ); ?></th>
					<td>
						<fieldset>
							<label for="gzip-yes"><input type="radio" id="gzip-yes" name="gzip" value="1"<?php checked( 1, $backup_gzip ); ?> /> <?php esc_html_e( 'Yes', 'wp-dbmanager' ); ?></label>
							<label for="gzip-no"><input type="radio" id="gzip-no" name="gzip" value="0"<?php checked( 0, $backup_gzip ); ?> /> <?php esc_html_e( 'No', 'wp-dbmanager' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" name="do" value="<?php esc_attr_e( 'Backup', 'wp-dbmanager' ); ?>" class="button button-primary" />
			<button type="button" class="button" data-dbmanager-back="1"><?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?></button>
		</p>
	</form>
</div>
		<?php
	}

	/**
	 * The Manage Backup Database screen.
	 *
	 * @return void
	 */
	public static function manage() {
		WP_DBManager_Admin::check_capability( 'manage' );

		$options  = WP_DBManager_Options::get();
		$messages = array();

		$table = new WP_DBManager_Backups_Table();

		// current_action() reads the bulk dropdown at either end of the table.
		$action = $table->current_action();

		if ( false !== $action ) {
			check_admin_referer( WP_DBManager_Backups_Table::nonce_action() );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry is run through sanitize_file_name() below.
			$selected = isset( $_POST['backups'] ) ? (array) wp_unslash( $_POST['backups'] ) : array();
			$selected = array_filter( array_map( 'sanitize_file_name', $selected ) );

			$messages = self::handle_backup_action( $action, $selected, $options );
		}

		$table->prepare_items();

		$restore_warning = __( 'You Are About To Restore A Database.\nThis Action Is Not Reversible.\nAny Data Inserted After The Backup Date Will Be Gone.\n\n Choose [Cancel] to stop, [Ok] to restore.', 'wp-dbmanager' );
		$delete_warning  = __( 'You Are About To Delete The Selected Database Backup Files.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager' );

		$confirmations = wp_json_encode(
			array(
				'restore' => $restore_warning,
				'delete'  => $delete_warning,
			)
		);
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Manage Backup Database', 'wp-dbmanager' ); ?></h1>
		<?php WP_DBManager_Admin::render_messages( $messages ); ?>
	<p><?php esc_html_e( 'Choose A Backup To E-Mail, Restore, Download Or Delete. Restore and Download act on one backup at a time.', 'wp-dbmanager' ); ?></p>
	<form method="post" action="<?php echo esc_url( WP_DBManager_Admin::page_url( 'manage' ) ); ?>" data-dbmanager-confirm-actions="<?php echo esc_attr( $confirmations ); ?>">
		<p>
			<label for="email_to"><?php esc_html_e( 'E-mail database backup file to:', 'wp-dbmanager' ); ?></label>
			<input type="email" id="email_to" name="email_to" class="regular-text" maxlength="50" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" dir="ltr" />
		</p>
		<?php $table->display(); ?>
		<p>
			<?php
			printf(
				/* translators: 1: number of backup files, 2: total size on disk. */
				esc_html( _n( '%1$s backup file, %2$s on disk.', '%1$s backup files, %2$s on disk.', count( $table->items ), 'wp-dbmanager' ) ),
				esc_html( number_format_i18n( count( $table->items ) ) ),
				esc_html( WP_DBManager_Backups::format_size( $table->total_size() ) )
			);
			?>
		</p>
	</form>
</div>
		<?php
	}

	/**
	 * Carry out a bulk action from the Manage screen.
	 *
	 * @param string $action   Chosen action.
	 * @param array  $selected Sanitized backup file names.
	 * @param array  $options  Plugin settings.
	 * @return array Messages to render.
	 */
	protected static function handle_backup_action( $action, array $selected, array $options ) {
		$messages = array();

		if ( empty( $selected ) ) {
			return array(
				array(
					'type' => 'error',
					'text' => __( 'No Backup Database File Selected', 'wp-dbmanager' ),
				),
			);
		}

		// Restoring overwrites the whole database and downloading sends one file,
		// so neither has a sensible meaning for a multiple selection. Refusing is
		// better than silently picking one of them.
		if ( in_array( $action, array( 'restore', 'download' ), true ) && count( $selected ) > 1 ) {
			return array(
				array(
					'type' => 'error',
					'text' => __( 'Please Select Only One Backup Database File', 'wp-dbmanager' ),
				),
			);
		}

		switch ( $action ) {
			case 'restore':
				$file   = WP_DBManager_Backups::parse_filename( $selected[0] );
				$result = WP_DBManager_Database::restore( $selected[0] );

				foreach ( $result['errors'] as $error ) {
					$messages[] = array(
						'type' => 'error',
						'text' => $error,
					);
				}

				// Only report on the restore when it actually ran; the path errors
				// above already explain why it did not.
				if ( $result['ran'] ) {
					$messages[] = array(
						'type' => $result['error'] ? 'error' : 'success',
						'text' => $result['error']
							/* translators: %s: date and time of the backup file. */
							? sprintf( __( 'Database On \'%s\' Failed To Restore', 'wp-dbmanager' ), $file['formatted_date'] )
							/* translators: %s: date and time of the backup file. */
							: sprintf( __( 'Database On \'%s\' Restored Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
					);
				}
				break;

			case 'email':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller before anything is read.
				$to = ! empty( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : get_option( 'admin_email' );

				foreach ( $selected as $name ) {
					$file = WP_DBManager_Backups::parse_filename( $name );

					if ( WP_DBManager_Mailer::send( $to, $options['path'] . '/' . $name ) ) {
						$messages[] = array(
							'type' => 'success',
							/* translators: 1: date and time of the backup file, 2: e-mail address. */
							'text' => sprintf( __( 'Database Backup File For \'%1$s\' Successfully E-Mailed To \'%2$s\'', 'wp-dbmanager' ), $file['formatted_date'], $to ),
						);
					} else {
						$messages[] = array(
							'type' => 'error',
							/* translators: 1: date and time of the backup file, 2: e-mail address. */
							'text' => sprintf( __( 'Unable To E-Mail Database Backup File For \'%1$s\' To \'%2$s\'', 'wp-dbmanager' ), $file['formatted_date'], $to ),
						);
					}
				}
				break;

			case 'download':
				// A real download exits during init, so reaching here means the
				// file could not be resolved inside the backup folder.
				$messages[] = array(
					'type' => 'error',
					'text' => __( 'Invalid Database Backup File', 'wp-dbmanager' ),
				);
				break;

			case 'delete':
				foreach ( $selected as $name ) {
					$file = WP_DBManager_Backups::parse_filename( $name );
					$path = $options['path'] . '/' . $name;

					if ( ! is_file( $path ) ) {
						$messages[] = array(
							'type' => 'error',
							/* translators: %s: date and time of the backup file. */
							'text' => sprintf( __( 'Invalid Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] ),
						);
						continue;
					}

					// wp_delete_file() returns nothing, so ask the filesystem
					// whether it actually worked.
					wp_delete_file( $path );

					$messages[] = array(
						'type' => file_exists( $path ) ? 'error' : 'success',
						'text' => file_exists( $path )
							/* translators: %s: date and time of the backup file. */
							? sprintf( __( 'Unable To Delete Database Backup File On \'%s\'', 'wp-dbmanager' ), $file['formatted_date'] )
							/* translators: %s: date and time of the backup file. */
							: sprintf( __( 'Database Backup File On \'%s\' Deleted Successfully', 'wp-dbmanager' ), $file['formatted_date'] ),
					);
				}
				break;
		}

		return $messages;
	}

	/**
	 * The Optimize Database screen.
	 *
	 * @return void
	 */
	public static function optimize() {
		self::render_table_screen(
			'optimize',
			__( 'Optimize Database', 'wp-dbmanager' ),
			array( 'p' => __( 'Database should be optimized once every month. Select the tables to optimize, then choose Optimize from the bulk actions.', 'wp-dbmanager' ) )
		);
	}

	/**
	 * The Repair Database screen.
	 *
	 * @return void
	 */
	public static function repair() {
		self::render_table_screen(
			'repair',
			__( 'Repair Database', 'wp-dbmanager' ),
			array( 'p' => __( 'Select the tables to repair, then choose Repair from the bulk actions.', 'wp-dbmanager' ) )
		);
	}

	/**
	 * The Empty/Drop Tables screen.
	 *
	 * @return void
	 */
	public static function empty_tables() {
		self::render_table_screen(
			'empty',
			__( 'Empty/Drop Tables', 'wp-dbmanager' ),
			array(
				'p'       => __( 'Select the tables, then choose Empty or Drop from the bulk actions.', 'wp-dbmanager' ),
				'notes'   => array(
					__( 'EMPTYING a table means all the rows in the table will be deleted. This action is not REVERSIBLE.', 'wp-dbmanager' ),
					__( 'DROPPING a table means deleting the table. This action is not REVERSIBLE.', 'wp-dbmanager' ),
				),
				'confirm' => array(
					'empty' => __( 'You Are About To Empty The Selected Tables.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to empty.', 'wp-dbmanager' ),
					'drop'  => __( 'You Are About To Drop The Selected Tables.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to drop.', 'wp-dbmanager' ),
				),
			)
		);
	}

	/**
	 * Render one of the three table-operation screens.
	 *
	 * Optimize, Repair and Empty/Drop differ only in the bulk actions they
	 * offer and the wording around the table, so they share this.
	 *
	 * @param string $mode  One of optimize, repair or empty.
	 * @param string $title Screen heading.
	 * @param array  $copy  Optional intro paragraph, footnotes and confirmations.
	 * @return void
	 */
	protected static function render_table_screen( $mode, $title, array $copy = array() ) {
		WP_DBManager_Admin::check_capability( $mode );

		$table  = new WP_DBManager_Tables_Table( $mode );
		$action = $table->current_action();

		$messages = array();

		if ( false !== $action ) {
			check_admin_referer( WP_DBManager_Tables_Table::nonce_action() );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Names are validated against SHOW TABLES by WP_DBManager_Tables::filter().
			$submitted = isset( $_POST['tables'] ) ? (array) wp_unslash( $_POST['tables'] ) : array();

			$messages = self::handle_table_action( $action, $submitted );
		}

		$table->prepare_items();

		$confirmations = isset( $copy['confirm'] ) ? wp_json_encode( $copy['confirm'] ) : '';
		?>
<div class="wrap">
	<h1><?php echo esc_html( $title ); ?></h1>
		<?php WP_DBManager_Admin::render_messages( $messages ); ?>
		<?php if ( ! empty( $copy['p'] ) ) : ?>
		<p><?php echo esc_html( $copy['p'] ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( WP_DBManager_Admin::page_url( 'empty' === $mode ? 'empty' : $mode ) ); ?>"<?php echo '' !== $confirmations ? ' data-dbmanager-confirm-actions="' . esc_attr( $confirmations ) . '"' : ''; ?>>
		<?php $table->display(); ?>
	</form>
		<?php if ( ! empty( $copy['notes'] ) ) : ?>
		<ol>
			<?php foreach ( $copy['notes'] as $note ) : ?>
				<li><?php echo esc_html( $note ); ?></li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * Carry out a bulk action from one of the table screens.
	 *
	 * The submitted names are request *keys* turned into values by the
	 * checkboxes, so they still cannot be sanitized into safety - they are
	 * matched against SHOW TABLES and anything that does not appear there is
	 * dropped before a query is built.
	 *
	 * @param string $action    Chosen action.
	 * @param array  $submitted Submitted table names.
	 * @return array Messages to render.
	 */
	protected static function handle_table_action( $action, array $submitted ) {
		$wanted = array_fill_keys( array_map( 'strval', $submitted ), 'yes' );
		$tables = WP_DBManager_Tables::filter( $wanted, 'yes' );

		if ( empty( $tables ) ) {
			return array(
				array(
					'type' => 'error',
					'text' => __( 'No Tables Selected', 'wp-dbmanager' ),
				),
			);
		}

		switch ( $action ) {
			case 'optimize':
				// Called once and held: inlining this into both branches of the
				// ternary below would run OPTIMIZE TABLE twice.
				$optimized = WP_DBManager_Tables::optimize( $tables );

				return array(
					array(
						'type' => $optimized ? 'success' : 'error',
						'text' => $optimized
							/* translators: %s: comma separated table names. */
							? sprintf( __( 'Table(s) \'%s\' Optimized', 'wp-dbmanager' ), implode( ', ', $tables ) )
							/* translators: %s: comma separated table names. */
							: sprintf( __( 'Table(s) \'%s\' NOT Optimized', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					),
				);

			case 'repair':
				$repaired = WP_DBManager_Tables::repair( $tables );

				return array(
					array(
						'type' => $repaired ? 'success' : 'error',
						'text' => $repaired
							/* translators: %s: comma separated table names. */
							? sprintf( __( 'Table(s) \'%s\' Repaired', 'wp-dbmanager' ), implode( ', ', $tables ) )
							/* translators: %s: comma separated table names. */
							: sprintf( __( 'Table(s) \'%s\' NOT Repaired', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					),
				);

			case 'empty':
				$messages = array();

				foreach ( $tables as $table ) {
					WP_DBManager_Tables::truncate( $table );

					$messages[] = array(
						'type' => 'success',
						/* translators: %s: table name. */
						'text' => sprintf( __( 'Table \'%s\' Emptied', 'wp-dbmanager' ), $table ),
					);
				}

				return $messages;

			case 'drop':
				WP_DBManager_Tables::drop( $tables );

				return array(
					array(
						'type' => 'success',
						/* translators: %s: comma separated table names. */
						'text' => sprintf( __( 'Table(s) \'%s\' Dropped', 'wp-dbmanager' ), implode( ', ', $tables ) ),
					),
				);
		}

		return array();
	}

	/**
	 * The Run SQL Query screen.
	 *
	 * @return void
	 */
	public static function run() {
		WP_DBManager_Admin::check_capability( 'run' );

		$messages = array();

		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( '' !== $action ) {
			// Verified before any request data is read, not part way down the switch.
			check_admin_referer( 'wp-dbmanager_run' );

			if ( __( 'Run', 'wp-dbmanager' ) === $action ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This is a raw SQL console; sanitizing the query would defeat the feature. Statement types are filtered by run_query().
				$blob = isset( $_POST['sql_query'] ) ? trim( wp_unslash( $_POST['sql_query'] ) ) : '';
				// empty() rather than a comparison against '': the pre-3.0.0 code
				// gated on plain truthiness, so a console holding only "0" counted
				// as an empty query, and that is worth keeping identical.
				$queries = ! empty( $blob ) ? WP_DBManager_Tables::split_queries( $blob ) : array();

				if ( empty( $queries ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'Empty Query', 'wp-dbmanager' ),
					);
				} else {
					$total   = 0;
					$success = 0;

					foreach ( $queries as $query ) {
						$outcome = WP_DBManager_Tables::run_query( $query );

						if ( 'ignored' === $outcome ) {
							continue;
						}

						++$total;

						if ( 'ok' === $outcome ) {
							++$success;
						}

						$messages[] = array(
							'type' => 'ok' === $outcome ? 'success' : 'error',
							'text' => $query,
						);
					}

					$messages[] = array(
						'type' => 'info',
						'text' => number_format_i18n( $success ) . '/' . number_format_i18n( $total ) . ' ' . __( 'Query(s) Executed Successfully', 'wp-dbmanager' ),
					);
				}
			}
		}

		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Run SQL Query', 'wp-dbmanager' ); ?></h1>
		<?php WP_DBManager_Admin::render_messages( $messages ); ?>
	<form method="post" action="<?php echo esc_url( WP_DBManager_Admin::page_url( 'run' ) ); ?>">
		<?php wp_nonce_field( 'wp-dbmanager_run' ); ?>
		<p>
			<label for="sql_query"><strong><?php esc_html_e( 'Separate Multiple Queries With A New Line', 'wp-dbmanager' ); ?></strong></label>
		</p>
		<p class="description"><?php esc_html_e( 'Use Only INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER statements.', 'wp-dbmanager' ); ?></p>
		<p>
			<textarea id="sql_query" name="sql_query" class="large-text code" rows="20" dir="ltr"></textarea>
		</p>
		<p class="submit">
			<input type="submit" name="do" value="<?php esc_attr_e( 'Run', 'wp-dbmanager' ); ?>" class="button button-primary" />
			<button type="button" class="button" data-dbmanager-back="1"><?php esc_html_e( 'Cancel', 'wp-dbmanager' ); ?></button>
		</p>
	</form>
	<ol>
		<li><?php esc_html_e( 'CREATE statement will return an error, which is perfectly normal due to the database class. To confirm that your table has been created check the Manage Database page.', 'wp-dbmanager' ); ?></li>
		<li><?php esc_html_e( 'UPDATE statement may return an error sometimes due to the newly updated value being the same as the previous value.', 'wp-dbmanager' ); ?></li>
		<li><?php esc_html_e( 'ALTER statement will return an error because there is no value returned.', 'wp-dbmanager' ); ?></li>
	</ol>
</div>
		<?php
	}
}
