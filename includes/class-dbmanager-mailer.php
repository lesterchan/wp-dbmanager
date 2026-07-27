<?php
/**
 * The backup e-mail.
 *
 * @package WP-DBManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the details of a backup, optionally with the dump attached.
 *
 * @since 3.0.0
 */
class DBManager_Mailer {

	/**
	 * E-mail the details of a backup.
	 *
	 * The body is worth sending on its own - name, checksum, date and size are
	 * enough to confirm a scheduled backup ran - which is why attaching the dump
	 * is a separate decision from sending the mail.
	 *
	 * @param string $to               Recipient address.
	 * @param string $backup_file_path Full path to the backup file.
	 * @param bool   $attach           Whether to attach the backup file itself.
	 * @return bool Whether the mail was handed off successfully.
	 */
	public static function send( $to, $backup_file_path, $attach = true ) {
		$to = ! empty( $to ) ? $to : get_option( 'admin_email' );

		if ( ! is_email( $to ) || ! file_exists( $backup_file_path ) ) {
			return false;
		}

		$options   = DBManager_Options::get();
		$file      = DBManager_Backups::parse_file( $backup_file_path );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = ! empty( $options['backup_email_subject'] )
			? $options['backup_email_subject']
			: DBManager_Options::defaults()['backup_email_subject'];

		$subject = str_replace(
			array( '%SITE_NAME%', '%POST_DATE%', '%POST_TIME%' ),
			array(
				$site_name,
				DBManager::format_timestamp( $file['timestamp'], get_option( 'date_format' ) ),
				DBManager::format_timestamp( $file['timestamp'], get_option( 'time_format' ) ),
			),
			$subject
		);

		$message = __( 'Website Name:', 'wp-dbmanager' ) . ' ' . $site_name . "\n" .
			__( 'Website URL:', 'wp-dbmanager' ) . ' ' . get_bloginfo( 'url' ) . "\n\n" .
			__( 'Backup File Name:', 'wp-dbmanager' ) . ' ' . $file['name'] . "\n" .
			__( 'Backup File MD5 Checksum:', 'wp-dbmanager' ) . ' ' . $file['checksum'] . "\n" .
			__( 'Backup File Date:', 'wp-dbmanager' ) . ' ' . $file['formatted_date'] . "\n" .
			__( 'Backup File Size:', 'wp-dbmanager' ) . ' ' . $file['formatted_size'] . "\n\n" .
			__( 'With Regards,', 'wp-dbmanager' ) . "\n" .
			$site_name . ' ' . __( 'Administrator', 'wp-dbmanager' ) . "\n" .
			get_bloginfo( 'url' );

		$defaults  = DBManager_Options::defaults();
		$from      = ! empty( $options['backup_email_from'] ) ? $options['backup_email_from'] : $defaults['backup_email_from'];
		$from_name = ! empty( $options['backup_email_from_name'] ) ? $options['backup_email_from_name'] : $defaults['backup_email_from_name'];

		$headers = array(
			'From: "' . wp_specialchars_decode( $from_name, ENT_QUOTES ) . '" <' . $from . '>',
		);

		$attachments = $attach ? $backup_file_path : array();

		return wp_mail( $to, $subject, $message, $headers, $attachments );
	}
}
