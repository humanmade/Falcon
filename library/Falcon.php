<?php

class Falcon extends Falcon_Autohooker {
	protected static $handler = null;

	protected static $connectors = array();

	public static function bootstrap() {
		// Kill the defaults
		remove_action( 'bbp_new_reply', 'bbp_notify_subscribers', 11 );

		add_action( 'rest_api_init', [ 'Falcon_API', 'bootstrap' ] );

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
			self::$connectors[ $key ] = new $connector( self::$handler );
		}
	}

	/**
	 * Get all available handlers
	 *
	 * @return array Associative array of identifier => handler class
	 */
	public static function get_handlers() {
		$default = array(
			'ses' => 'Falcon_Handler_SES',
			'postmark' => 'Falcon_Handler_Postmark',
			'mandrill' => 'Falcon_Handler_Mandrill',
			'wpmail' => 'Falcon_Handler_WPMail',
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
			$type = self::get_option('bbsub_handler_type', false);
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
		$type = self::get_option('bbsub_handler_type', 'postmark');
		$options = self::get_option('bbsub_handler_options', array());

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
		$connectors = array(
			'wordpress' => 'Falcon_Connector_WordPress'
		);

		if (is_plugin_active('bbpress/bbpress.php')) {
			$connectors['bbpress'] = 'Falcon_Connector_bbPress';
		}

		return apply_filters( 'falcon_connectors', $connectors );
	}

	public static function get_connectors() {
		return self::$connectors;
	}

	/**
	 * Get the reply-to address for a post and user
	 *
	 * @param int $post_id Post ID
	 * @param WP_User $user User object
	 * @return string Full email address
	 */
	public static function get_reply_address($post_id, $user, $site_id = null) {
		if ( ! $site_id ) {
			$site_id = get_current_blog_id();
		}

		$address = self::get_option('bbsub_replyto', false);
		if (empty($address)) {
			throw new Exception(__('Invalid reply-to address', 'bbsub'));
		}

		// Append the plus address if it's not already there
		if ( strpos( $address, '+' ) !== false) {
			throw new Exception(__('Invalid reply-to address', 'bbsub'));
		}

		list( $user_part, $host_part ) = explode( '@', $address );
		$user_part .= '+%1$s-%2$d-%3$d-%4$s';
		$address = $user_part . '@' . $host_part;

		return sprintf($address, $post_id, $site_id, $user->ID, self::get_hash($post_id, $user, $site_id));
	}

	/**
	 * Get the verification hash for a post and user
	 *
	 * Uses a HMAC rather than a straight hash to avoid vulnerabilities.
	 * @see http://benlog.com/articles/2008/06/19/dont-hash-secrets/
	 * @see http://blog.jcoglan.com/2012/06/09/why-you-should-never-use-hash-functions-for-message-authentication/
	 *
	 * @param int $post_id Post ID
	 * @param WP_User $user User object
	 * @return string Verification hash (10 characters long)
	 */
	public static function get_hash($post_id, $user, $site_id) {
		return hash_hmac('sha1', $post_id . '|' . $site_id . '|' . $user->ID, 'bbsub_reply_by_email');
	}

	/**
	 * Get the From address
	 *
	 * Defaults to the same default email as wp_mail(), including filters
	 * @return string Full email address
	 */
	public static function get_from_address() {
		$address = self::get_option('bbsub_from_email', false);
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
	public static function notify_invalid($user, $title) {
		// Build email
		$text = 'Hi %1$s,' . "\n";
		$text .= 'Someone just tried to post to the "%2$s" topic as you, but were unable to' . "\n";
		$text .= 'authenticate as you. If you recently tried to reply to this topic, try' . "\n";
		$text .= 'replying to the original topic again. If that doesn\'t work, post on the' . "\n";
		$text .= 'forums via your browser and ask an admin.' . "\n";
		$text .= '---' . "\n" . 'The admins at %3$s' . "\n\n";
		$text = sprintf($text, $user->display_name, $title, get_option('blogname'));

		$text = apply_filters( 'bbsub_email_message_invalid', $text, $user->ID );
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

	/**
	 * Is Falcon in network mode?
	 *
	 * Network mode is used when Falcon is network-activated, and moves some
	 * of the settings to the network admin for super admins instead. It also
	 * adds UI to allow enabling per-site.
	 *
	 * @return boolean
	 */
	public static function is_network_mode() {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		return is_multisite() && is_plugin_active_for_network( FALCON_PLUGIN );
	}

	/**
	 * Get an option's value.
	 *
	 * Uses network-wide options if in network mode. Keys must be prefixed.
	 *
	 * @param string $key Option key/name.
	 * @param mixed $default Default value to return if no option is set.
	 * @return mixed Option value if set, or $default if no option is set.
	 */
	public static function get_option( $key, $default = false ) {
		if ( self::is_network_mode() ) {
			return get_site_option( $key, $default );
		}

		return get_option( $key, $default );
	}

	/**
	 * Update an option.
	 *
	 * Uses network-wide options if in network mode. Keys must be prefixed.
	 *
	 * @param string $key Option key/name.
	 * @param mixed $value Value to set the option to.
	 * @return bool True if option was updated, false otherwise.
	 */
	public static function update_option( $key, $value ) {
		if ( self::is_network_mode() ) {
			return update_site_option( $key, $value );
		}

		return update_option( $key, $value );
	}

	/**
	 * Is Falcon enabled for this site?
	 *
	 * When Falcon is used in network mode, it can be toggled per-site.
	 *
	 * Avoid using this to determine whether to hook in, instead use it inside
	 * your hook callbacks to determine whether to run.
	 *
	 * @param int $site_id Site to check. Default is current site.
	 * @return boolean
	 */
	public static function is_enabled_for_site( $site_id = null ) {
		if ( ! self::is_network_mode() ) {
			return true;
		}

		if ( empty( $site_id ) ) {
			$site_id = get_current_blog_id();
		}

		$sites = Falcon::get_option( 'falcon_enabled_sites', array() );
		return in_array( $site_id, $sites );
	}

	/**
	 * Should notifications be sent asynchronously?
	 *
	 * @return boolean
	 */
	public static function should_send_async() {
		return (bool) Falcon::get_option( 'bbsub_send_async', false );
	}
}
