<?php

class Falcon extends Falcon_Autohooker {
	protected static $handler = null;

	protected static $connectors = array();

	public static function bootstrap() {
		// Kill the defaults
		remove_action( 'bbp_new_reply', 'bbp_notify_subscribers', 11 );

		if (is_admin()) {
			Falcon_Admin::bootstrap();
		}

		try {
			// Check for a handler first
			self::$handler = self::get_handler();

			// Then add our own hooks!
			self::register_hooks();
		}
		catch (Exception $e) {
			add_action('all_admin_notices', function () use ($e) {
				printf('<div class="error"><p>' . __('Problem setting up Falcon! %s', 'bbsub') . '</p></div>', $e->getMessage());
			});

		}

		foreach ( self::get_available_connectors() as $key => $connector ) {
			self::$connectors[ $key ] = new $connector();
		}
	}

	/**
	 * Get all available handlers
	 *
	 * @return array Associative array of identifier => handler class
	 */
	public static function get_handlers() {
		$default = array(
			'postmark' => 'Falcon_Handler_Postmark',
			'mandrill' => 'Falcon_Handler_Mandrill',
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

		$handlers = self::get_handlers();

		if (empty($type)) {
			throw new Exception(__('No handler set in the options', 'bbsub'));
		}
		if (!isset($handlers[$type])) {
			throw new Exception(__('Handler could not be found.', 'bbsub'));
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
	 * Get available connectors
	 *
	 * @return array
	 */
	protected static function get_available_connectors() {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$connectors = array();

		if (is_plugin_active('bbpress/bbpress.php')) {
			$connectors['bbpress'] = 'Falcon_Connector_bbPress';
		}

		return apply_filters( 'falcon_connectors', $connectors );
	}

	public static function get_connectors() {
		return self::$connectors;
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
			throw new Exception(__('Invalid reply-to address', 'bbsub'));
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
	 * Notify the user of an invalid reply
	 *
	 * @param WP_User $user User that supposedly sent the email
	 * @param int $topic_id Topic ID
	 */
	public static function notify_invalid($user, $topic_id) {
		$topic_title = bbp_get_topic_title( $topic_id );
		// Build email
		$text = "Hi %1$s,\n";
		$text .= "Someone just tried to post to the '%2$1' topic as you, but were unable to\n";
		$text .= "authenticate as you. If you recently tried to reply to this topic, try\n";
		$text .= "replying to the original topic again. If that doesn't work, post on the\n";
		$text .= "forums via your browser and ask an admin.\n";
		$text .= "---\nThe admins at %3$s\n\n";
		$text = sprintf($text, $content, $topic_title, get_option('blogname'));

		$text = apply_filters( 'bbsub_email_message_invalid', $message, $user->ID, $content );
		$subject = apply_filters('bbsub_email_subject_invalid', '[' . get_option( 'blogname' ) . '] Invalid Reply Received', $user->ID);

		wp_mail($user->use_email, $subject, $text);
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
		$schedules['falcon_minutely'] = array('interval' => 60, 'display' => 'Once Every Minute');
		return $schedules;
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

	/**
	 * Convert the post content to text
	 *
	 * @wp-filter bbsub_html_to_text
	 * @param string $html HTML to convert
	 * @return string Text version of the content
	 */
	public static function convert_html_to_text($html) {
		$converter = new Falcon_Converter($html);
		return $converter->convert();
	}
}
