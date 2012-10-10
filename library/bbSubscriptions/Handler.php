<?php

interface bbSubscriptions_Handler {
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

	public function send_mail($users, $subject, $text, $attrs);

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