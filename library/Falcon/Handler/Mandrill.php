<?php
/**
 * Mandrill Subscription Handler
 *
 * @author Tareq Hasan <tareq@wedevs.com>
 * @package bbSubscriptions
 * @subpackage Handlers
 */

/**
 * Mandrill Subscription Handler
 *
 * @author Tareq Hasan <tareq@wedevs.com>
 * @package bbSubscriptions
 * @subpackage Handlers
 */
class Falcon_Handler_Mandrill implements Falcon_Handler {
	public function __construct( $options ) {}

	public function check_inbox() {}

	public static function options_section_header() {
	?>
	<p><?php printf(
		__("Once you've set your Reply-To address, set your Route in your <a href='%s'>Mandrill inbound dashboard</a>", 'falcon'),
		'https://mandrillapp.com/inbound'
	) ?></p>

	<p><?php _e('For example, if <code>reply+%1$d-%2$s@yourdomain.com</code> is your reply-to, your Mandrill route would be <code>reply+*@yourdomain.com</code>', 'falcon') ?></p>
	<?php
	}

	public static function register_option_fields( $group, $section, $options ) {}

	public static function validate_options( $input ) {}

	public function send_mail( $users, Falcon_Message $message ) {
		$from = Falcon::get_from_address();
		if ( $author = $message->get_author() ) {
			$from = sprintf( '%s <%s>', $author, $from );
		}

		foreach ($users as $user) {

			$from_address = $from;
			$reply_to = $message->get_reply_address( $user );
			$headers = "Reply-to:$reply_to\nFrom:$from_address";

			wp_mail( $user->user_email, $message->get_subject(), $message->get_text(), $headers );
		}
	}

	/**
	 * Handles Mandrill inbound web hook
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( isset( $_POST['mandrill_events'] ) ) {
			$parsed = reset( json_decode( stripslashes( $_POST['mandrill_events'] ) ) );

			if ( !$parsed ) {
				return;
			}

			$reply = new Falcon_Reply();
			$reply->from = $parsed->msg->from_email;
			$reply->subject = $parsed->msg->subject;
			$reply->body = $parsed->msg->text;

			list($reply->post, $reply->nonce) = Falcon_Reply::parse_to( $parsed->msg->email );

			$reply_id = $reply->insert();

			if ( $reply_id === false ) {
				header( 'X-Fail: No reply ID', true, 400 );
				echo 'Reply could not be added?'; // intentionally not translated
				// Log this?
			}
		}
	}

	public static function get_name() {
		return 'Mandrill';
	}

	public static function supports_message_ids() {
		return false;
	}

}