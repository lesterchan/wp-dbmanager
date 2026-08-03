<?php
/**
 * The backup e-mail.
 *
 * @package WP-DBManager
 */

/**
 * Whether the mail goes out, who it says it is from, and what it carries.
 *
 * The attachment is the interesting part: it is a copy of the whole database,
 * so "send the details but not the dump" has to actually mean that.
 */
class WP_DBManager_Mailer_Test extends WP_DBManager_TestCase {

	/**
	 * Mail handed to wp_mail(), newest last.
	 *
	 * @var array
	 */
	protected $sent = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->sent = array();

		// Short-circuit before any transport is involved, and record what was
		// asked for.
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->sent[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * Write a fake backup into the scratch folder.
	 *
	 * @param string $name File name.
	 * @return string Full path.
	 */
	protected function make_backup( $name = null ) {
		$name = ( null === $name ) ? str_repeat( 'a', 32 ) . '_-_1700000000_-_sitedb.sql' : $name;
		$path = $this->backup_dir . '/' . $name;

		self::write_file( $path, 'SOME SQL' );

		return $path;
	}

	/**
	 * The last mail wp_mail() was asked to send.
	 *
	 * @return array
	 */
	protected function last_mail() {
		return end( $this->sent );
	}

	/**
	 * A backup is mailed to the address given.
	 */
	public function test_a_backup_is_mailed() {
		$this->assertTrue( WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup() ), 'A backup is mailed to a valid address.' );

		$this->assertCount( 1, $this->sent, 'Exactly one message was sent.' );
		$this->assertSame( 'someone@example.com', $this->last_mail()['to'] );
	}

	/**
	 * An empty recipient falls back to the site admin.
	 */
	public function test_an_empty_recipient_falls_back_to_the_admin() {
		WP_DBManager_Mailer::send( '', $this->make_backup() );

		$this->assertSame( get_option( 'admin_email' ), $this->last_mail()['to'] );
	}

	/**
	 * Nothing is sent to an address that is not one.
	 */
	public function test_an_invalid_address_sends_nothing() {
		$this->assertFalse( WP_DBManager_Mailer::send( 'not-an-address', $this->make_backup() ), 'An invalid address sends nothing rather than failing later.' );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * Nothing is sent when the backup is not there.
	 *
	 * Otherwise a failed backup still produces a reassuring e-mail.
	 */
	public function test_a_missing_backup_sends_nothing() {
		$this->assertFalse( WP_DBManager_Mailer::send( 'someone@example.com', $this->backup_dir . '/nope.sql' ), 'A missing backup sends nothing rather than an empty attachment.' );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * The dump is attached by default.
	 */
	public function test_the_dump_is_attached_by_default() {
		$path = $this->make_backup();

		WP_DBManager_Mailer::send( 'someone@example.com', $path );

		$this->assertSame( $path, $this->last_mail()['attachments'] );
	}

	/**
	 * Declining the attachment really leaves the database out.
	 *
	 * The whole point of the option is keeping a copy of the database out of a
	 * mailbox, so this must not be a cosmetic setting.
	 */
	public function test_declining_the_attachment_sends_no_file() {
		WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup(), false );

		$this->assertSame( array(), $this->last_mail()['attachments'] );
	}

	/**
	 * The body still carries the details when the dump is not attached.
	 */
	public function test_the_body_describes_the_backup() {
		$path = $this->make_backup();

		WP_DBManager_Mailer::send( 'someone@example.com', $path, false );

		$message = $this->last_mail()['message'];

		$this->assertStringContainsString( basename( $path ), $message );
		$this->assertStringContainsString( str_repeat( 'a', 32 ), $message, 'The checksum is missing.' );
		$this->assertStringContainsString( get_bloginfo( 'url' ), $message );
		$this->assertStringContainsString( '8 bytes', $message, 'The size is missing.' );
	}

	/**
	 * The subject tokens are replaced.
	 */
	public function test_subject_tokens_are_replaced() {
		$options                         = WP_DBManager_Options::get();
		$options['backup_email_subject'] = '%SITE_NAME% on %POST_DATE% at %POST_TIME%';
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup() );

		$subject = $this->last_mail()['subject'];

		$this->assertStringNotContainsString( '%SITE_NAME%', $subject );
		$this->assertStringNotContainsString( '%POST_DATE%', $subject );
		$this->assertStringNotContainsString( '%POST_TIME%', $subject );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $subject );
	}

	/**
	 * A blank subject falls back to the default rather than sending nothing.
	 */
	public function test_a_blank_subject_falls_back_to_the_default() {
		$options                         = WP_DBManager_Options::get();
		$options['backup_email_subject'] = '';
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup() );

		$this->assertNotSame( '', trim( $this->last_mail()['subject'] ) );
	}

	/**
	 * The From header is built from the configured name and address.
	 */
	public function test_the_from_header_is_set() {
		$options                           = WP_DBManager_Options::get();
		$options['backup_email_from']      = 'backups@example.com';
		$options['backup_email_from_name'] = 'Backup Robot';
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup() );

		$headers = (array) $this->last_mail()['headers'];

		$this->assertStringContainsString( 'From: "Backup Robot" <backups@example.com>', implode( "\n", $headers ) );
	}

	/**
	 * A site title containing a comma does not break the From header.
	 *
	 * This was a real bug once: the title went into the header unescaped and a
	 * comma made the address list ambiguous.
	 */
	public function test_a_comma_in_the_site_title_survives() {
		update_option( 'blogname', 'Lester, Chan &amp; Co' );

		$options                           = WP_DBManager_Options::get();
		$options['backup_email_from_name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		update_option( WP_DBManager_Options::OPTION, $options );

		WP_DBManager_Mailer::send( 'someone@example.com', $this->make_backup() );

		$headers = implode( "\n", (array) $this->last_mail()['headers'] );

		// Quoted, so the comma cannot be read as a separator, and the entity is
		// decoded rather than shown raw.
		$this->assertStringContainsString( 'From: "Lester, Chan & Co"', $headers );
	}
}
