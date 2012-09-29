<?php

class bbSubscriptions_Handler_Postmark implements bbSubscriptions_Handler {
	public function __construct() {
		require_once bbSub::$path . '/vendor/postmark-inbound/lib/Postmark/Autoloader.php';
		\Postmark\Autoloader::register();

		$this->options = array(
			'host' => 'imap.gmail.com',
			'ssl' => true,
			'port' => 993,
			'authentication' => array(
				'user' => 'me@ryanmccue.info',
				'pass' => 'bfqqiunulpshlnek----preferably not, \'k?'
			)
		);
	}

	public static function send_mail($users, $subject, $content, $headers, $attrs) {
		extract($attrs);

		// For some stupid reason, a lot of plugins override 'From:'
		// without checking if it's the default, so we need to
		// filter instead of using $headers
		$mailer_filter = function (&$phpmailer) use ($topic_id, $reply_author_name, $user) {
			$phpmailer->From = bbSubscriptions::get_reply_address($topic_id, $user);
			$phpmailer->FromName = $reply_author_name;
			$phpmailer->AddReplyTo($phpmailer->From, $phpmailer->FromName);
		};

		// Register
		add_filter('phpmailer_init', $mailer_filter, 9999);

		// Send notification email
		wp_mail( $user->user_email, $subject, $content, $headers );

		// And unregister
		remove_filter('phpmailer_init', $mailer_filter, 9999);
	}

	/**
	 * Check the inbox for replies
	 *
	 * Postmark sends POST requests when we receive an email instead
	 */
	public static function check_inbox() {}

	public static function handle_post() {
		$inbound = new \Postmark\Inbound(file_get_contents('php://input'));

		echo $inbound->Subject();
		echo $inbound->FromEmail();
	}
}
