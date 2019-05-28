<?php

class Falcon_Manager extends Falcon_Autohooker {
	protected static $available = array();
	protected static $registered_settings = array();

	public static function bootstrap() {
		self::register_hooks();
	}

	public static function key_for_setting( $connector, $key, $site_id = null ) {
		if ( is_multisite() ) {
			$site_id = $site_id ? $site_id : get_current_blog_id();
			return sprintf( 'falcon.site_%d.%s.%s', $site_id, $connector, $key );
		}

		return sprintf( 'falcon.%s.%s', $connector, $key );
	}

	/**
	 * @wp-action edit_user_profile
	 * @wp-action show_user_profile
	 */
	public static function user_profile_fields( $user_id ) {
		echo '<h3>' . esc_html__( 'Notification Settings', 'falcon' ) . '</h3>';

		if ( Falcon::is_network_mode() ) {
			// On multisite, we want to output a table of all
			// notification settings
			$sites = Falcon::get_option( 'falcon_enabled_sites', array() );

			echo '<p class="description">' . esc_html__( 'Set your email notification settings for the following sites.', 'falcon' ) . '</p>';
			do_action( 'falcon.manager.network_profile_fields', $user_id, $sites );

			?>
			<style>
				.falcon-grid {
					margin-bottom: 1rem;
				}

				.falcon-grid .last_of_col {
					border-right: 2px solid rgba(0, 0, 0, 0.2);
				}
				.falcon-grid .last_of_col:last-child {
					border-right: none;
				}

				.falcon-grid thead th,
				.falcon-grid thead td,
				.falcon-grid tbody td {
					text-align: center;
				}
				.falcon-grid thead th {
					font-weight: bold;
				}
				.falcon-grid thead th,
				.falcon-grid thead td {
					border-bottom: 1px solid rgba(0, 0, 0, 0.1);
				}
				.falcon-grid .falcon-grid-section-label {
					text-align: left;
				}

				.falcon-grid th .details {
					font-weight: normal;
					font-size: 13px;
					color: #aaa;
				}
				.falcon-grid tbody th,
				.falcon-grid tbody td {
					border-bottom: 1px solid rgba(0, 0, 0, 0.05);
				}
			</style>
			<?php

		}
		else {
			?>

			<p class="description"><?php esc_html_e( 'Set your email notification settings for the current site.', 'falcon' ) ?></p>

			<table class="form-table">
				<?php do_action( 'falcon.manager.profile_fields', $user_id ) ?>
			</table>
			<?php
		}
	}

	/**
	 * @wp-action personal_options_update
	 * @wp-action edit_user_profile_update
	 */
	public static function save_profile_settings( $user_id ) {
		// Double-check permissions
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$args = wp_unslash( $_POST );
		if ( Falcon::is_network_mode() ) {
			$sites = Falcon::get_option( 'falcon_enabled_sites', array() );
			do_action( 'falcon.manager.save_network_profile_fields', $user_id, $args, $sites );
		}
		else {
			do_action( 'falcon.manager.save_profile_fields', $user_id, $args );
		}
	}

	/**
	 * @wp-action wp_dashboard_setup
	 */
	public static function register_dashboard_widget( $widgets ) {
		wp_add_dashboard_widget( 'falcon_notification_settings', __( 'Notification Settings', 'falcon' ), array( get_class(), 'output_dashboard_widget' ) );
	}

	public static function output_dashboard_widget() {
		$user = wp_get_current_user();
		?>
		<table class="form-table">
			<?php do_action( 'falcon.manager.profile_fields', $user ) ?>
		</table>
		<?php
	}

	protected static function available_sites() {
		$sites = wp_get_sites( array(
			'archived' => false,
			'deleted' => false,
			'spam' => false,
		) );

		return apply_filters( 'falcon.manager.available_sites', $sites );
	}

	public static function register_default_settings() {
		if ( Falcon::is_network_mode() ) {
			self::register_network_settings();
		}

		add_settings_section( 'bbsub_options_notifications', 'Default Notification Settings', array( get_class(), 'output_default_settings_header' ), 'bbsub_options' );

		$connectors = Falcon::get_connectors();
		foreach ( $connectors as $type => $connector ) {
			if ( ! is_callable( array( $connector, 'get_available_settings' ) ) ) {
				continue;
			}

			$args = array(
				'type' => $type,
				'connector' => $connector,
			);
			add_settings_field(
				'falcon_options_notifications-' . $type,
				$connector->get_name(),
				array( get_class(), 'output_default_settings' ),
				'bbsub_options',
				'bbsub_options_notifications',
				$args
			);

			$available = $connector->get_available_settings();
			self::$available[ $type ] = $available;

			foreach ( $available as $key => $title ) {
				$setting_key = self::key_for_setting( $type, 'notifications.' . $key );
				register_setting( 'bbsub_options', $setting_key );

				// Add the filter ourselves, so that we can specify two params
				add_filter( "sanitize_option_{$setting_key}", array( get_class(), 'sanitize_notification_option' ), 10, 2 );

				// Save the key for later
				self::$registered_settings[ $setting_key ] = array( $type, $key );
			}
		}
	}

	public static function output_default_settings_header() {
		echo '<p>' . __('Set the default user notification settings here.', 'falcon') . '</p>';
	}

	public static function output_default_settings( $args ) {
		$connector = $args['connector'];
		?>
		<table class="form-table">
			<?php $connector->output_settings() ?>
		</table>
		<?php
	}

	/**
	 * POST data in PHP has `.` converted to underscores. For
	 * `register_setting` to work correctly, we need to undo this.
	 *
	 * The reason PHP does this appears to be a holdover from legacy
	 * `register_globals` days. PHP variables can't have a `.` in them, so it
	 * converts these to legal variable names.
	 *
	 * @wp-filter option_page_capability_bbsub_options
	 */
	public static function unmangle_notification_data( $cap ) {
		foreach ( self::$registered_settings as $key => $opts ) {
			$mangled = str_replace( '.', '_', $key );

			if ( isset( $_POST[ $mangled ] ) && !isset( $_POST[ $key ] ) ) {
				$_POST[ $key ] = $_POST[ $mangled ];
			}
		}

		return $cap;
	}

	public static function sanitize_notification_option( $value, $name ) {
		if ( ! isset( self::$registered_settings[ $name ] ) ) {
			return $value;
		}

		list( $connector, $key ) = self::$registered_settings[ $name ];
		$valid = self::$available[ $connector ][ $key ];

		// Check the value is valid
		if ( isset( $valid[ $value ] ) ) {
			return $value;
		}

		add_settings_error(
			$name,
			'bbsub_option_invalid',
			__('The notification option is invalid', 'falcon')
		);
		return false;
	}

	public static function register_network_settings() {
		register_setting( 'bbsub_options', 'falcon_enabled_sites', array(__CLASS__, 'validate_sites') );
		add_settings_field( 'bbsub_options_sites', 'Active Sites', array( __CLASS__, 'settings_field_sites' ), 'bbsub_options', 'bbsub_options_global' );
	}

	public static function settings_field_sites() {
		$sites = self::available_sites();
		$current = Falcon::get_option( 'falcon_enabled_sites', array() );

		foreach ( $sites as $site ) {
			$details = get_blog_details( $site['blog_id'] );
			$value = absint( $site['blog_id'] );
			$enabled = in_array( $value, $current );

			printf(
				'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label><br />',
				'falcon_enabled_sites',
				esc_attr( $value ),
				checked( $enabled, true, false ),
				esc_html( $details->blogname )
			);

		}

		echo '<p class="description">' . esc_html__( 'Select which sites to activate Falcon on.', 'falcon' ) . '</p>';
	}

	public static function validate_sites( $value ) {
		$value = (array) $value;

		$sites = self::available_sites();
		$site_ids = wp_list_pluck( $sites, 'blog_id' );

		// Ensure all values are available
		$sanitized = array_values( array_intersect( $value, $site_ids ) );
		$sanitized = array_map( 'absint', $sanitized );
		return $sanitized;
	}
}
