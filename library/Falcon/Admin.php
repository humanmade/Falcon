<?php

class Falcon_Admin extends Falcon_Autohooker {
	/**
	 * Should we wipe the handler-specific options?
	 *
	 * When the handler is changed, we can't keep the same handler-specific
	 * options saved, so this flag ensures that we wipe the data.
	 *
	 * @var boolean
	 */
	protected static $wipe_handler_options = false;

	protected static $registered_handler_settings = false;

	/**
	 * Bootstrap the class
	 *
	 * Ensures all necessary hooks are added
	 */
	public static function bootstrap() {
		self::register_hooks();
		Falcon_Manager::bootstrap();
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
		register_setting( 'bbsub_options', 'bbsub_replyto', array(__CLASS__, 'validate_replyto') );
		register_setting( 'bbsub_options', 'bbsub_from_email', array(__CLASS__, 'validate_from_email') );
		register_setting( 'bbsub_options', 'bbsub_send_to_author', array(__CLASS__, 'validate_send_to_author') );
		register_setting( 'bbsub_options', 'bbsub_handler_options', array(__CLASS__, 'validate_handler_options') );

		// Global Settings
		add_settings_section('bbsub_options_global', 'Main Settings', array(__CLASS__, 'settings_section_main'), 'bbsub_options');
		add_settings_field('bbsub_options_global_type', 'Messaging Handler', array(__CLASS__, 'settings_field_type'), 'bbsub_options', 'bbsub_options_global');
		add_settings_field('bbsub_options_global_replyto', 'Reply-To Address', array(__CLASS__, 'settings_field_replyto'), 'bbsub_options', 'bbsub_options_global');
		add_settings_field('bbsub_options_global_from_email', 'From Address', array(__CLASS__, 'settings_field_from'), 'bbsub_options', 'bbsub_options_global');
		add_settings_field('bbsub_options_global_send_to_author', 'Send To', array(__CLASS__, 'settings_field_send_to_author'), 'bbsub_options', 'bbsub_options_global');

		// Note: title is false so that we can handle it ourselves
		add_settings_section('bbsub_options_handleroptions', false, array(__CLASS__, 'settings_section_handler'), 'bbsub_options');

		foreach ( Falcon::get_connectors() as $connector ) {
			if ( ! is_callable( $connector, 'register_settings' ) ) {
				continue;
			}

			$connector->register_settings();
		}

		Falcon_Manager::register_default_settings();
	}

	/**
	 * Add our menu item
	 *
	 * @wp-action admin_menu
	 */
	public static function register_menu() {
		add_options_page(_x('Falcon', 'page title', 'falcon'), _x('Falcon', 'menu title', 'falcon'), 'manage_options', 'bbsub_options', array(__CLASS__, 'admin_page'));
	}

	/**
	 * Print the content
	 */
	public static function admin_page() {
	?>
		<div class="wrap">
			<h2><?php _e('Falcon Options', 'falcon') ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields('bbsub_options') ?>
				<?php do_settings_sections('bbsub_options') ?>
				<?php submit_button() ?>
			</form>
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				var clearForm = function () {
					// Replace the title and form with the contents
					var $header = $('#bbsub-handlersettings-header');
					var $table = $header.next();
					if ( $table.is( '.form-table' ) ) {
						$table.remove();
					}
					$header.remove();

					var $error = $('#bbsub-handlersettings-error');
					if ( $error.length ) {
						$error.remove();
					}
				};
				$('#bbsub_options_global_type').on('change', function (e) {
					$('#bbsub_options_global_type').after(' <img src="<?php echo esc_js( esc_url( admin_url( 'images/loading.gif' ) ) ); ?>" id="bbsub-loading" />' );
					$.ajax({
						url: ajaxurl,
						data: {
							action: 'bbsub_handler_section',
							handler: $(this).val()
						},
						success: function (response) {
							clearForm();

							$('#bbsub-handlersettings-insert').after(response);
							$('#bbsub-loading').remove();
						},
						error: function (response) {
							clearForm();

							$('#bbsub-handlersettings-insert').after(response.responseText);
							$('#bbsub-loading').remove();
						}
					});
				});
			})
		</script>
	<?php
	}

	/**
	 * Handle an AJAX request for the handler section
	 *
	 * @wp-action wp_ajax_bbsub_handler_section
	 */
	public static function ajax_handler_section() {
		try {
			if (!isset($_REQUEST['handler'])) {
				throw new Exception(__('Invalid handler type', 'falcon'));
			}

			// Setup the handler settings for the newly selected handler
			$handler = self::validate_type($_REQUEST['handler']);
			if (!$handler) {
				throw new Exception(__('Invalid handler', 'falcon'));
			}

			$options = get_option('bbsub_handler_options', array());
			// validate_type() will set this flag if the type isn't equal to
			// the current one
			if (self::$wipe_handler_options) {
				$options = array();
			}
			self::register_handler_settings_fields('bbsub_options', 'bbsub_options_handleroptions', $handler, $options);
			self::$registered_handler_settings = true;

			// Now, output the section
			$page = 'bbsub_options';
			$section = 'bbsub_options_handleroptions';


			global $wp_settings_fields;
			self::settings_section_handler();

			if ( !isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section]) )
				die();

			echo '<table class="form-table">';
			do_settings_fields($page, $section);
			echo '</table>';

			// Special field to ensure we don't wipe settings fully
			echo '<input type="hidden" name="bbsub_used_ajax" value="1" />';
		}
		catch (Exception $e) {
			header('X-Excepted: true', true, 500);
			echo '<div class="error" style="width:317px" id="bbsub-handlersettings-error"><p>' . $e->getMessage() . '</p></div>';
		}

		die();
	}

	/**
	 * Print description for the main settings section
	 *
	 * @see self::init()
	 */
	public static function settings_section_main() {
		echo '<p>' . __('Main settings for the plugin', 'falcon') . '</p>';
	}

	/**
	 * Print field for the handler type
	 *
	 * @see self::init()
	 */
	public static function settings_field_type() {
		$current = get_option('bbsub_handler_type', false);
		$available = Falcon::get_handlers();

		if (empty($available)) {
			echo '<p class="error">' . __('No handlers are available!', 'falcon') . '</p>';
		}

		echo '<select name="bbsub_handler_type" id="bbsub_options_global_type">';
		echo '<option>' . _x('None', 'handler', 'falcon') . '</option>';
		foreach ($available as $type => $class) {
			echo '<option value="' . esc_attr($type) . '"' . selected($current, $type) . '>' . esc_html($class::get_name()) . '</option>';
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
		if ( in_array( $input, array_keys(Falcon::get_handlers()) ) ) {
			if ($input !== get_option('bbsub_handler_type', false) && empty($_POST['bbsub_used_ajax'])) {
				self::$wipe_handler_options = true;
			}
			return $input;
		}

		add_settings_error(
			'bbsub_handler_type',
			'bbsub_handler_invalid',
			__('The selected handler is invalid', 'falcon')
		);
		return false;
	}

	/**
	 * Print field for the reply-to address
	 *
	 * @see self::init()
	 */
	public static function settings_field_replyto() {
		$current = get_option('bbsub_replyto', '');

		echo '<input type="text" name="bbsub_replyto" class="regular-text" value="' . esc_attr($current) . '" />';
		echo '<p class="description">';
		_e('Falcon will append an authentication token to this email before sending.', 'falcon');
		echo '</p>';
	}

	/**
	 * Validate the reply-to address
	 *
	 * Ensures that the reply-to address is a valid formattable email address
	 * @param string $input New reply-to address
	 * @return string Updated reply-to address if valid, otherwise the old address
	 */
	public static function validate_replyto($input) {
		$oldvalue = get_option('bbsub_replyto', '');

		// Append the plus address if it's not already there
		$address = $input;
		if ( strpos( $address, '+' ) === false) {
			list( $user_part, $host_part ) = explode( '@', $address );
			$user_part .= '+%1$s-%2$s';
			$address = $user_part . '@' . $host_part;
		}

		// Test it out!
		$hmac = hash_hmac('sha1', '5|1', 'bbsub_reply_by_email');
		$formatted = sprintf($address, 5, $hmac);

		// Check that the resulting email is valid
		if (!is_email($formatted)) {
			add_settings_error(
				'bbsub_replyto',
				'bbsub_replyto_invalid',
				__('The reply-to address must be a valid address', 'falcon')
			);
			return $oldvalue;
		}

		return $input;
	}

	/**
	 * Print field for the Send to Author checkbox
	 *
	 * @see self::init()
	 */
	public static function settings_field_send_to_author() {
		$current = get_option('bbsub_send_to_author', '');

		echo '<label><input type="checkbox" name="bbsub_send_to_author" ' . checked($current, true, false) . ' /> ';
		_e('Send a notification to the reply author', 'falcon');
		echo '</label>';
	}

	/**
	 * Validate the Send to Author checkbox
	 *
	 * @param string $input
	 * @return string
	 */
	public static function validate_send_to_author($input) {
		return (bool) $input;
	}

	/**
	 * Print field for the reply-to address
	 *
	 * @see self::init()
	 */
	public static function settings_field_from() {
		$current = get_option('bbsub_from_email', '');

		echo '<input type="email" name="bbsub_from_email" class="regular-text" value="' . esc_attr($current) . '" />';
		echo '<p class="description">' . __('Leave blank to use the default email address (<code>wordpress@</code>)', 'falcon') . '</p>';
	}

	/**
	 * Validate the reply-to address
	 *
	 * Ensures that the reply-to address is a valid formattable email address
	 * @param string $input New reply-to address
	 * @return string Updated reply-to address if valid, otherwise the old address
	 */
	public static function validate_from_email($input) {
		$oldvalue = get_option('bbsub_from_email', '');

		// Check that the resulting email is valid
		if (!is_email($input)) {
			add_settings_error(
				'bbsub_from_email',
				'bbsub_from_invalid',
				__('The from address must be a valid address', 'falcon')
			);
			return $oldvalue;
		}

		return $input;
	}

	public static function settings_section_handler() {
		if (!self::$registered_handler_settings) {
			self::register_handler_settings_fields('bbsub_options', 'bbsub_options_handleroptions');
			self::$registered_handler_settings = true;
		}

		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			echo '<div id="bbsub-handlersettings-insert"></div>';
		}

		global $wp_settings_fields;

		// If the handler didn't register any options, don't bother to print the
		// title for the section
		$page = 'bbsub_options';
		$section = 'bbsub_options_handleroptions';
		if ( !isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section]) )
			return;

		echo '<div id="bbsub-handlersettings-header">';
		echo '<h3 id="bbsub-handlersettings-title">' . __('Handler Settings', 'falcon') . '</h3>';

		try {
			$handler = Falcon::get_handler_class();
		}
		catch (Exception $e) {
			return;
		}
		$handler::options_section_header();
		echo '</div>';
	}

	/**
	 * Notify the handler to register handler-specific options
	 *
	 * @see self::init()
	 */
	public static function register_handler_settings_fields($group, $section, $handler_type = null, $current = null) {
		if ($current === null) {
			$current = get_option('bbsub_handler_options', array());
		}
		try {
			$handler = Falcon::get_handler_class($handler_type);
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
			$handler = Falcon::get_handler_class();
		}
		catch (Exception $e) {
			return array();
		}
		return $handler::validate_options($input);
	}
}