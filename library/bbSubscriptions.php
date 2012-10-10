<?php

class bbSubscriptions extends bbSubscriptions_Autohooker {
	protected static $handler = null;

	public static function bootstrap() {
		// Kill the defaults
		remove_action('bbp_new_reply', 'bbp_notify_subscribers', 1, 5);

		if (is_admin()) {
			bbSubscriptions_Admin::bootstrap();
		}

		try {
			// Check for a handler first
			self::$handler = self::get_handler();

			// Then add our own hooks!
			self::register_hooks();
		}
		catch (Exception $e) {
			add_action('all_admin_notices', function () use ($e) {
				printf('<div class="error"><p>Problem setting up bbSubscriptions! %s</p></div>', $e->getMessage());
			});

			return false;
		}
	}

	/**
	 * Get all available handlers
	 *
	 * @return array Associative array of identifier => handler class
	 */
	public static function get_handlers() {
		$default = array(
			'postmark' => 'bbSubscriptions_Handler_Postmark',
		);
		return apply_filters('bbsub_handlers', $default);
	}

	/**
	 * Get the registered handler class for a certain type
	 *
	 * @param string|null $type Type to get, defaults to the option
	 */
	public static function get_handler_class($type = null) {
		if (!$type) {
			$type = get_option('bbsub_handler_type', false);
		}

		$handlers = bbSubscriptions::get_handlers();

		if (empty($type)) {
			throw new Exception('No handler set in the options');
		}
		if (!isset($handlers[$type])) {
			throw new Exception('Handler could not be found.');
		}
		return $handlers[$type];
	}

	/**
	 * Get a mail handler based on the config
	 *
	 * @return bbSubscriptions_Handler
	 */
	protected static function get_handler() {
		$type = get_option('bbsub_handler_type', 'postmark');
		$options = get_option('bbsub_handler_options', array());

		// Get the appropriate handler
		$handler = self::get_handler_class($type);
		$handler = apply_filters('bbsub_handler_' . $type, new $handler($options), $options);

		return $handler;
	}

	/**
	 * Get the reply-to address for a topic and user
	 *
	 * @param int $topic Topic ID
	 * @param WP_User $user User object
	 * @return string Full email address
	 */
	public static function get_reply_address($topic, $user) {
		$address = get_option('bbsub_replyto', false);
		if (empty($address)) {
			throw new Exception('Invalid reply-to address');
		}

		return sprintf($address, $topic, self::get_hash($topic, $user));
	}

	/**
	 * Get the verification hash for a topic and user
	 *
	 * Uses a HMAC rather than a straight hash to avoid vulnerabilities.
	 * @see http://benlog.com/articles/2008/06/19/dont-hash-secrets/
	 * @see http://blog.jcoglan.com/2012/06/09/why-you-should-never-use-hash-functions-for-message-authentication/
	 *
	 * @param int $topic Topic ID
	 * @param WP_User $user User object
	 * @return string Verification hash (10 characters long)
	 */
	public static function get_hash($topic, $user) {
		return hash_hmac('sha1', $topic . '|' . $user->ID, 'bbsub_reply_by_email');
	}

	/**
	 * Get the From address
	 *
	 * Defaults to the same default email as wp_mail(), including filters
	 * @return string Full email address
	 */
	public static function get_from_address() {
		$address = get_option('bbsub_from_email', false);
		if (empty($address)) {
			// Get the site domain and get rid of www.
			$sitename = strtolower( $_SERVER['SERVER_NAME'] );
			if ( substr( $sitename, 0, 4 ) == 'www.' ) {
				$sitename = substr( $sitename, 4 );
			}

			$address = 'wordpress@' . $sitename;
			$address = apply_filters('wp_mail_from', $address);
		}

		return $address;
	}

	/**
	 * Add a more frequent cron schedule
	 *
	 * We need to check the inbox much more regularly than hourly, so here we
	 * do it every minute instead.
	 *
	 * @wp-filter cron_schedules
	 */
	public static function add_schedule($schedules) {
		$schedules['bbsub_minutely'] = array('interval' => 60, 'display' => 'Once Every Minute');
		return $schedules;
	}

	/**
	 * Send a notification to subscribers
	 *
	 * @wp-filter bbp_new_reply 1
	 */
	public static function notify_on_reply( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0 ) {
		if (self::$handler === null) {
			return false;
		}

		global $wpdb;

		if (!bbp_is_subscriptions_active()) {
			return false;
		}

		$reply_id = bbp_get_reply_id( $reply_id );
		$topic_id = bbp_get_topic_id( $topic_id );
		$forum_id = bbp_get_forum_id( $forum_id );

		if (!bbp_is_reply_published($reply_id)) {
			return false;
		}

		if (!bbp_is_topic_published($topic_id)) {
			return false;
		}

		$user_ids = bbp_get_topic_subscribers($topic_id, true);
		if (empty($user_ids)) {
			return false;
		}

		// Poster name
		$reply_author_name = bbp_get_reply_author_display_name($reply_id);

		do_action( 'bbp_pre_notify_subscribers', $reply_id, $topic_id, $user_ids );

		// Don't send notifications to the person who made the post
		$send_to_author = get_option('bbsub_send_to_author', false);

		if (!$send_to_author) {
			$user_ids = array_filter($user_ids, function ($id) use ($reply_author) {
				return (empty($reply_author) || (int) $id !== (int) $reply_author);
			});
		}

		// Get userdata for all users
		$user_ids = array_map(function ($id) {
			return get_userdata($id);
		}, $user_ids);

		// Build email
		$text = "%1\$s\n\n";
		$text .= "---\nReply to this email directly or view it online:\n%2\$s\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";
		$text = sprintf($text, strip_tags(bbp_get_reply_content($reply_id)), bbp_get_reply_url($reply_id));
		$subject = 'Re: [' . get_option( 'blogname' ) . '] ' . bbp_get_topic_title( $topic_id );

		self::$handler->send_mail($user_ids, $subject, $text, compact('topic_id', 'reply_author_name'));

		do_action( 'bbp_post_notify_subscribers', $reply_id, $topic_id, $user_ids );

		return true;
	}

	/**
	 * @wp-action bbsub_check_inbox
	 */
	public static function check_inbox() {
		if (self::$handler === null) {
			return false;
		}

		self::$handler->check_inbox();
	}

	/**
	 * @wp-action admin_post_nopriv_bbsub
	 * @wp-action admin_post_bbsub
	 */
	public static function post_callback() {
		if (self::$handler === null) {
			return false;
		}

		self::$handler->handle_post();
	}
}
