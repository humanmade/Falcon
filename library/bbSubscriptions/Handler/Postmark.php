<?php
/**
 * Postmark API handler
 *
 * @package bbSubscriptions
 * @subpackage Handlers
 */

require_once bbSub::$path . '/vendor/postmark-inbound/lib/Postmark/Autoloader.php';
\Postmark\Autoloader::register();

/**
 * Postmark API handler
 *
 * @package bbSubscriptions
 * @subpackage Handlers
 */
class bbSubscriptions_Handler_Postmark implements bbSubscriptions_Handler {
	/**
	 * For the settings callbacks, we need to hold on to the current options
	 *
	 * @var array
	 */
	protected static $current_options = array();

	public function __construct($options) {
		if (empty($options) || empty($options['api_key'])) {
			throw new Exception(__('Postmark API key not set', 'bbsub'));
		}
		$this->api_key = $options['api_key'];
	}

	public function send_mail($users, $subject, $content, $attrs) {
		extract($attrs);

		foreach ($users as $user) {
			$data = array(
				'From' => sprintf('%s <%s>', $reply_author_name, bbSubscriptions::get_from_address()),
				'ReplyTo' => bbSubscriptions::get_reply_address($topic_id, $user),
				'To' => $user->user_email,
				'Subject' => $subject,
				'TextBody' => $content,
			);

			$this->send_single($data);
		}
	}

	protected function send_single($data) {
		$headers = array(
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
			'X-Postmark-Server-Token' => $this->api_key,
		);

		$response = wp_remote_post('http://api.postmarkapp.com/email', array(
			'headers' => $headers,
			'body' => json_encode($data)
		));

		$code = wp_remote_retrieve_response_code($response);
		switch ($code) {
			case 200:
				return true;
			case 401:
				throw new Exception(__('Invalid API key', 'bbsub'), 401);
			case 422:
				throw new Exception(sprintf(__('Error with sent body: %s', 'bbsub'), wp_remote_retrieve_body($response)), 422);
			case 500:
				throw new Exception(__('Postmark server error', 'bbsub'), 500);
			default:
				throw new Exception(__('Unknown error', 'bbsub'), $code);
		}
	}

	/**
	 * Check the inbox for replies
	 *
	 * Postmark sends POST requests when we receive an email instead
	 */
	public function check_inbox() {}

	public function handle_post() {
		$input = file_get_contents('php://input');
		if (empty($input)) {
			header('X-Fail: No input', true, 400);
			echo 'No input found.'; // intentionally not translated
			return;
		}
		file_put_contents('/tmp/postmark', $input);
		try {
			$inbound = new \Postmark\Inbound($input);
		}
		catch (\Postmark\InboundException $e) {
			header('X-Fail: Postmark problem', true, 400);
			echo $e->getMessage();
			return;
		}

		// The "Test" button sends an email from support@postmarkapp.com
		if ($inbound->FromEmail() === 'support@postmarkapp.com') {
			echo 'Hello tester!'; // intentionally not translated
			return;
		}

		$reply = new bbSubscriptions_Reply();
		$reply->from = $inbound->FromEmail();
		$reply->subject = $inbound->Subject();
		$reply->body = $inbound->TextBody();

		$to = $inbound->Recipients();
		list($reply->topic, $reply->nonce) = bbSubscriptions_Reply::parse_to($to[0]->Email);

		$reply_id = $reply->insert();
		if ($reply_id === false) {
			header('X-Fail: No reply ID', true, 400);
			echo 'Reply could not be added?'; // intentionally not translated
			// Log this?
		}
	}

	/**
	 * Get a human-readable name for the handler
	 *
	 * This is used for the handler selector and is shown to the user.
	 * @return string
	 */
	public static function get_name() {
		return 'Postmark';
	}

	/**
	 * Output a description for the options
	 *
	 * If you have any extra information you want to tell the user, this is
	 * probably the best place for it to live.
	 */
	public static function options_section_header() {
?>
	<p><?php printf(
		__("Once you've set up your API key here, make sure to set your Inbound Hook URL to <code>%s</code>", 'bbsub'),
		admin_url('admin-post.php?action=bbsub')
	) ?></p>
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
		add_settings_field('bbsub_postmark_apikey', __('Postmark API Key', 'bbsub'), array(__CLASS__, 'field_apikey'), $group, $section);
	}

	public static function field_apikey() {
		$key = '';
		if (isset(self::$current_options['api_key'])) {
			$key = self::$current_options['api_key'];
		}
?>
		<input type="text" name="bbsub_handler_options[api_key]"
			id="bbsub_postmark_apikey" value="<?php echo esc_attr($key) ?>"
			class="regular-text code" />
		<p class="description">
			<?php _e("You'll need to create an API key on the Postmark site. Head to <a href='https://postmarkapp.com/servers'>your server</a>, then generate an API key under the credentials tab", 'bbsub') ?>
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
