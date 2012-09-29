<?php

interface bbSubscriptions_Handler {
	public function send_mail($users, $subject, $text, $attrs);

	/**
	 * Check the inbox for replies
	 */
	public function check_inbox();

	public function handle_post();
}