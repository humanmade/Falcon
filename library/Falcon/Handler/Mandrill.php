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
			$parsed = reset( json_decode( wp_unslash( $_POST['mandrill_events'] ) ) );

			if ( !$parsed ) {
				return;
			}

			$reply = new Falcon_Reply();
			$reply->subject = $parsed->msg->subject;
			$reply->body = $parsed->msg->text;

			list($reply->post, $reply->site, $reply->user, $reply->nonce) = Falcon_Reply::parse_to( $parsed->msg->email );

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

	/**
	 * Output a description for the options
	 *
	 * If you have any extra information you want to tell the user, this is
	 * probably the best place for it to live.
	 */
	public static function options_section_header() {
?>
		<p><?php
		printf(
			__("Once you've set up your API key here, add an Inbound route in your <a href='%s'>Mandrill inbound dashboard</a> to <code>%s</code>", 'falcon'),
			'https://mandrillapp.com/inbound',
			admin_url('admin-post.php?action=bbsub')
		)
		?></p>
<?php
	}

	/**
	 * Register handler-specific option fields
	 *
	 * @see bbSubscriptions_Handler::register_option_fields
	 * @param string $group Settings group (4th parameter to `add_settings_field`)
	 * @param string $section Settings section (5th parameter to `add_settings_field`)
	 * @param array $options Current options
	 */
	public static function register_option_fields($group, $section, $options) {
		self::$current_options = $options;
		add_settings_field('bbsub_mandrill_apikey', __('Mandrill API Key', 'falcon'), array(__CLASS__, 'field_apikey'), $group, $section);
	}

	public static function field_apikey() {
		$key = '';
		if (isset(self::$current_options['api_key'])) {
			$key = self::$current_options['api_key'];
		}
?>
		<input type="text" name="bbsub_handler_options[api_key]"
			id="bbsub_mandrill_apikey" value="<?php echo esc_attr($key) ?>"
			class="regular-text code" />
		<p class="description">
			<?php printf(
				__("You'll need to create an API key on the Mandrill site. Head to <a href='%s'>your settings</a>, then generate an API key under the credentials tab", 'falcon'),
				'https://mandrillapp.com/settings'
			) ?>
		</p>
<?php
	}

	/**
	 * Validate the options from the submitted form
	 *
	 * @see bbSubscriptions_Handler::validate_options
	 * @param array $input Raw POSTed data
	 * @return array Sanitized POST data
	 */
	public static function validate_options($input) {
		$sanitized = array();
		$sanitized['api_key'] = trim($input['api_key']);
		return $sanitized;
	}

}