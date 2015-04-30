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
	/**
	 * For the settings callbacks, we need to hold on to the current options
	 *
	 * @var array
	 */
	protected static $current_options = array();

	public function __construct($options) {
		if (empty($options) || empty($options['api_key'])) {
			throw new Exception(__('Mandrill API key not set', 'falcon'));
		}
		$this->api_key = $options['api_key'];
	}

	public function check_inbox() {}

	public function send_mail( $users, Falcon_Message $message ) {
		$options = $message->get_options();

		$from = Falcon::get_from_address();
		$author = $message->get_author();

		$messages = array();
		foreach ($users as $user) {
			$data = array(
				'from_email' => $from,
				'to'         => array(
					array(
						'email' => $user->user_email,
						'name'  => $user->display_name,
					)
				),
				'subject'    => $message->get_subject(),
				'headers'    => array(
					'Reply-To' => $message->get_reply_address( $user ),
				),
			);

			if ( $author ) {
				$data['from_name'] = $author;
			}

			if ( $text = $message->get_text() ) {
				$data['text'] = $text;
			}
			if ( $html = $message->get_html() ) {
				$data['html'] = $html;
			}

			// Set the message ID if we've got one
			if ( ! empty( $options['message-id'] ) ) {
				$data['headers']['Message-ID'] = $options['message-id'];
			}

			// If this is a reply, set the headers as needed
			if ( ! empty( $options['in-reply-to'] ) ) {
				$original = $options['in-reply-to'];
				if ( is_array( $original ) ) {
					$original = isset( $options['in-reply-to'][ $user->ID ] ) ? $options['in-reply-to'][ $user->ID ] : null;
				}

				if ( ! empty( $original ) ) {
					$data['headers']['In-Reply-To'] = $original;
				}
			}

			if ( ! empty( $options['references'] ) ) {
				$references = implode( ' ', $options['references'] );
				$data['headers']['References'] = $references;
			}

			$messages[ $user->ID ] = $this->send_single($data);
		}

		return $messages;
	}

	protected function send_single($data) {
		$headers = array(
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
		);
		$body = array(
			'key' => $this->api_key,
			'message' => $data,
		);

		$response = wp_remote_post('https://mandrillapp.com/api/1.0/messages/send.json', array(
			'headers' => $headers,
			'body' => json_encode($body)
		));

		$code = wp_remote_retrieve_response_code($response);
		switch ($code) {
			case 200:
				break;

			case 401:
				throw new Exception(__('Invalid API key', 'falcon'), 401);
			case 422:
				throw new Exception(sprintf(__('Error with sent body: %s', 'falcon'), wp_remote_retrieve_body($response)), 422);
			case 500:
				throw new Exception(__('Mandrill server error', 'falcon'), 500);
			default:
				throw new Exception(__('Unknown error', 'falcon'), (int) $code);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data ) ) {
			throw new Exception(__('Invalid response from Mandrill', 'falcon'));
		}

		return $data[0]->_id;
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
		return true;
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