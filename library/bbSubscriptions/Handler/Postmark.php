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
		$this->api_key = '2a464cc2-8982-4742-85b9-f8831f2d26f6';
	}

	public function send_mail($users, $subject, $content, $attrs) {
		extract($attrs);

		foreach ($users as $user) {
			$data = array(
				'From' => sprintf('%s <reply@bbpress.test.renku.me>', $reply_author_name),
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
	public function check_inbox() {}

	public function handle_post() {
		$input = file_get_contents('php://input');
		if (empty($input)) {
			header('X-Fail: No input', true, 400);
			echo 'No input found.';
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
			echo 'Hello tester!';
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
			echo 'Reply could not be added?';
			// Log this?
		}
	}
}
