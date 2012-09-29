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
	public function __construct() {
		$this->api_key = '';
	}

	public static function send_mail($users, $subject, $content, $attrs) {
		extract($attrs);

		foreach ($users as $user) {
			$from = sprintf('%s <%s>', $reply_author_name, bbSubscriptions::get_reply_address($topic_id, $user));
			$data = array(
				'From' => $from,
				'ReplyTo' => $from,
				'To' => $user->user_email,
				'Subject' => $subject,
				'TextBody' => $content,
			);

			self::send_single($data);
		}
	}

	protected static function send_single($data) {
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'X-Postmark-Server-Token: ' . $this->api_key,
		);

		$response = wp_remote_post('http://api.postmarkapp.com/email', array(
			'headers' => $headers,
			'body' => json_encode($this->data)
		));

		$code = wp_remote_retrieve_response_code($response);
		switch ($code) {
			case 200:
				return true;
			case 401:
				throw new Exception('Invalid API key', 401);
			case 422:
				throw new Exception('Error with sent body: ' . wp_remote_retrieve_body($response), 422);
			case 500:
				throw new Exception('Postmark server error', 500);
			default:
				throw new Exception('Unknown error', $code);
		}
	}

	/**
	 * Check the inbox for replies
	 *
	 * Postmark sends POST requests when we receive an email instead
	 */
	public static function check_inbox() {}

	public static function handle_post() {
		$inbound = new \Postmark\Inbound(file_get_contents('php://input'));

		$reply = new bbSubscriptions_Reply();
		$reply->from = $inbound->FromEmail();
		$reply->subject = $inbound->Subject();
		$reply->body = $inbound->Text();
		$reply_id = $reply->insert();
		if ($reply_id === false) {
			continue;
		}

		echo $inbound->Subject();
		echo $inbound->FromEmail();
	}
}
