<?php

class bbSubscriptions_Admin extends bbSubscriptions_Autohooker {
	/**
	 * Should we wipe the handler-specific options?
	 *
	 * When the handler is changed, we can't keep the same handler-specific
	 * options saved, so this flag ensures that we wipe the data.
	 *
	 * @var boolean
	 */
	protected static $wipe_handler_options = false;

	/**
	 * Bootstrap the class
	 *
	 * Ensures all necessary hooks are added
	 */
	public static function bootstrap() {
		self::register_hooks();
	}

	/**
	 * Initialization
	 *
	 * @wp-action admin_init
	 */
	public static function init() {
		if (!is_admin()) {
			return false;
		}

		register_setting( 'bbsub_options', 'bbsub_handler_type', array(__CLASS__, 'validate_type') );
		register_setting( 'bbsub_options', 'bbsub_handler_options', array(__CLASS__, 'validate_handler_options') );

		add_settings_section('bbsub_options_global', 'Main Settings', array(__CLASS__, 'settings_section_main'), 'bbsub_options');
		add_settings_field('bbsub_options_global_type', 'Messaging Handler', array(__CLASS__, 'settings_field_type'), 'bbsub_options', 'bbsub_options_global');

		add_settings_section('bbsub_options_handleroptions', 'Handler Settings', array(__CLASS__, 'settings_section_handler'), 'bbsub_options');
		self::register_handler_settings_fields('bbsub_options', 'bbsub_options_handleroptions');
	}

	/**
	 * Add our menu item
	 *
	 * @wp-action admin_menu
	 */
	public static function register_menu() {
		add_options_page('Reply by Email', 'Reply by Email', 'manage_options', 'bbsub_options', array(__CLASS__, 'admin_page'));
	}

	/**
	 * Print the content
	 */
	public static function admin_page() {
?>
		<div class="wrap">
			<h2>bbPress Reply by Email Options</h2>
			<form method="post" action="options.php">
				<?php settings_fields('bbsub_options') ?>
				<?php do_settings_sections('bbsub_options') ?>
				<?php submit_button() ?>
			</form>
		</div?
<?php
	}

	/**
	 * Print description for the main settings section
	 *
	 * @see self::init()
	 */
	public static function settings_section_main() {
		echo '<p>Main settings for the plugin</p>';
	}

	/**
	 * Print field for the handler type
	 *
	 * @see self::init()
	 */
	public static function settings_field_type() {
		$current = get_option('bbsub_handler_type', false);
		$available = bbSubscriptions::get_handlers();

		echo '<select name="bbsub_handler_type">';
		foreach ($available as $type => $class) {
			echo '<option value="' . esc_attr($type) . '"' . selected($current, $type) . '>' . $type . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Validate the handler type
	 *
	 * Ensures that the selected type can actually be selected
	 * @param string $input Selected class name
	 * @return string|bool Selected class name if valid, otherwise false
	 */
	public static function validate_type($input) {
		if ( in_array( $input, array_keys(bbSubscriptions::get_handlers()) ) ) {
			if ($input !== get_option('bbsub_handler_type', false)) {
				self::$wipe_handler_options = true;
			}
			return $input;
		}

		return false;
	}

	public static function settings_section_handler() {}

	/**
	 * Notify the handler to register handler-specific options
	 *
	 * @see self::init()
	 */
	public static function register_handler_settings_fields($group, $section) {
		$current = get_option('bbsub_handler_options', array());
		try {
			$handler = bbSubscriptions::get_handler_class();
		}
		catch (Exception $e) {
			return false;
		}
		$handler::register_option_fields($group, $section, $current);
	}

	/**
	 * Validate the handler-specific options via the handler's methods
	 *
	 * @see self::init()
	 */
	public static function validate_handler_options($input) {
		if (self::$wipe_handler_options) {
			return array();
		}

		try {
			$handler = bbSubscriptions::get_handler_class();
		}
		catch (Exception $e) {
			return array();
		}
		return $handler::validate_options($input);
	}
}