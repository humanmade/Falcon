<?php

class bbSubscriptions_Handler_Lamson implements bbSubscriptions_Handler {
	public function __construct() {
		
	}

	public static function send_mail($user, $subject, $content, $headers, $attrs) {
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
	 * With Lamson, we don't have to worry about this, as we get instant
	 * notifications instead.
	 */
	public static function check_inbox() {
		// no-op
	}
}
