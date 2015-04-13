<?php

interface Falcon_Handler {
	/**
	 * Construct the handler
	 *
	 * This method should set up your handler completely and ensure that it's
	 * ready to start handling sending and receiving data.
	 *
	 * If the supplied options are invalid, throw an exception with the message
	 * set to a human-readable description; it will be displayed to the user on
	 * pageload.
	 *
	 * @throws Exception
	 * @param array $options Handler-specific options, saved via {@see validate_options}
	 */
	public function __construct($options);

	/**
	 * Send a message to specified recipients
	 *
	 * @param array $users Users to notify
	 * @param string $subject Message subject
	 * @param string $text Message text
	 * @param array $options {
	 *     @param int $id Post ID to use for hash
	 *     @param string $author Author name (leave blank for no name)
	 * }
	 * @return array|null Map of user ID to message ID, or empty if IDs are not available.
	 */
	public function send_mail($users, $subject, $text, $options);

	/**
	 * Check the inbox for replies
	 */
	public function check_inbox();

	public function handle_post();

	/**
	 * Get a human-readable name for the handler
	 *
	 * This is used for the handler selector and is shown to the user.
	 * @return string
	 */
	public static function get_name();

	/**
	 * Does the handler support custom message IDs?
	 *
	 * Falcon can operate in one of two modes:
	 *
	 * 1. Leader Mode - Falcon will set the message ID for each message, and
	 *    assume full control over them for threading purposes.
	 * 2. Follower Mode - Falcon will observe message IDs set by the handler,
	 *    and use them internally.
	 *
	 * Where possible, use leader mode, as this has cleaner handling. However,
	 * not all handlers support custom message IDs, so follower mode must exist
	 * for these.
	 *
	 * @return bool True to operate in leader mode, false to operate in follower mode.
	 */
	public static function supports_message_ids();

	/**
	 * Output a description for the options
	 *
	 * If you have any extra information you want to tell the user, this is
	 * probably the best place for it to live.
	 */
	public static function options_section_header();

	/**
	 * Register handler-specific option fields
	 *
	 * This method is expected to call `add_settings_field()` as many times as
	 * needed for the relevant option fields. The `$group` and `$section` params
	 * are the 4th and 5th parameters to `add_settings_field()` respectively.
	 *
	 * e.g. `add_settings_field('bbsub_postmark_apikey', 'Postmark API Key', array(__CLASS__, 'field_apikey'), $group, $section)`
	 *
	 * Note that you need to use "bbsub_handler_options" as the name of the POST
	 * variable in your callback.
	 *
	 * e.g. `echo '<input type="text" name="bbsub_handler_options[api_key]" />`;
	 *
	 * @param string $group Settings group (4th parameter to `add_settings_field`)
	 * @param string $section Settings section (5th parameter to `add_settings_field`)
	 * @param array $options Current options
	 */
	public static function register_option_fields($group, $section, $options);

	/**
	 * Validate the options from the submitted form
	 *
	 * This method takes the values of the POSTed values for
	 * bbsub_handler_options and is expected to return a sanitized version. The
	 * sanitized values will be passed into the handler's constructor.
	 *
	 * @param array $input Raw POSTed data
	 * @return array Sanitized POST data
	 */
	public static function validate_options($input);
}