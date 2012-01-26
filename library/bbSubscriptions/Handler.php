<?php

interface bbSubscriptions_Handler {
	public static function send_mail($user, $subject, $text, $headers, $attrs);

	/**
	 * Check the inbox for replies
	 */
	public static function check_inbox();
}