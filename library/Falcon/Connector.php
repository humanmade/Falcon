<?php

abstract class Falcon_Connector {
	/**
	 * Get a human-readable name for the handler
	 *
	 * This is used for the handler selector and is shown to the user.
	 *
	 * @return string
	 */
	abstract public static function get_name();

	/**
	 * Get a machine-readable ID for the handler.
	 *
	 * This is used for preference handling.
	 *
	 * @return string
	 */
	abstract protected function get_id();

	/**
	 * Add a notification hook.
	 *
	 * Basically `add_action` but specifically for actions to send
	 * notifications on. Automatically registers cron tasks if running in
	 * asynchronous mode.
	 *
	 * @param string $hook Name of the action to register for.
	 * @param callback $callback Callback to register.
	 * @param int $priority Priority to register at, larger numbers = later run.
	 * @param int $accepted_args Number of arguments to pass through, default 0.
	 */
	protected function add_notify_action( $hook, $callback, $priority = 10, $accepted_args = 0 ) {
		if ( Falcon::should_send_async() ) {
			add_action( $hook, array( $this, 'schedule_async_action' ), $priority, $accepted_args );
			add_action( 'falcon_async-' . $hook, $callback, 10, $accepted_args );
		} else {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}

	/**
	 * Run the current action asynchronously.
	 */
	public function schedule_async_action() {
		$hook = current_action();
		$args = func_get_args();

		wp_schedule_single_event( time(), 'falcon_async-' . $hook, $args );
	}

	/**
	 * Get the key for a setting.
	 *
	 * This should use `Falcon_Manager::key_for_setting()` with a unique ID
	 * for the connector.
	 */
	protected function key_for_setting( $key, $site_id = null ) {
		return Falcon_Manager::key_for_setting( $this->get_id(), $key, $site_id );
	}

	/**
	 * Get default notification settings
	 *
	 * @return array Map of type => pref value
	 */
	protected function get_default_settings() {
		$keys = $this->get_settings_fields();
		$defaults = array();

		foreach ( $keys as $key => $opts ) {
			$hardcoded_default = $opts['default'];
			$option_key = $this->key_for_setting( 'notifications.' . $key );
			$value = Falcon::get_option( $option_key, null );

			$defaults[ $key ] = isset( $value ) ? $value : $hardcoded_default;
		}

		return $defaults;
	}

	/**
	 * Get notification settings for the current user
	 *
	 * @param int $user_id User to get settings for
	 * @return array Map of type => pref value
	 */
	protected function get_settings_for_user( $user_id, $site_id = null ) {
		$available = $this->get_available_settings();
		$settings = array();

		foreach ( $available as $type => $choices ) {
			$key = $this->key_for_setting( 'notifications.' . $type, $site_id );
			$value = get_user_meta( $user_id, $key );
			if ( empty( $value ) ) {
				continue;
			}

			$settings[ $type ] = $value[0];
		}

		return $settings;
	}

	protected function print_field( $field, $settings, $is_defaults_screen = false ) {
		$defaults = $this->get_default_settings();

		$site_id = get_current_blog_id();
		$default = isset( $defaults[ $field ] ) ? $defaults[ $field ] : false;
		$current = isset( $settings[ $field ] ) ? $settings[ $field ] : $default;

		$notifications = $this->get_available_settings();

		foreach ( $notifications[ $field ] as $value => $title ) {
			$maybe_default = '';
			if ( ! $is_defaults_screen && $value === $default ) {
				$maybe_default = '<strong>' . esc_html__( ' (default)', 'falcon' ) . '</strong>';
			}

			printf(
				'<label><input type="radio" name="%s" value="%s" %s /> %s</label><br />',
				esc_attr( $this->key_for_setting( 'notifications.' . $field ) ),
				esc_attr( $value ),
				checked( $value, $current, false ),
				esc_html( $title ) . $maybe_default
			);
		}
	}

	public function save_profile_settings( $user_id, $args = array(), $sites = null ) {
		$available = $this->get_available_settings();

		if ( $sites === null ) {
			$sites = array( get_current_blog_id() );
		}

		foreach ( $available as $type => $options ) {
			foreach ( $sites as $site ) {
				$key = $this->key_for_setting( 'notifications.' . $type, $site );

				// PHP strips '.' out of POST data as a relic from the
				// register_globals days, so we need to take that into account
				$request_key = str_replace( '.', '_', $key );
				if ( ! isset( $args[ $request_key ] ) ) {
					continue;
				}
				$value = $args[ $request_key ];

				// Check the value is valid
				$options = array_keys( $options );
				if ( ! in_array( $value, $options ) ) {
					continue;
				}

				// Actually set it!
				if ( ! update_user_meta( $user_id, wp_slash( $key ), wp_slash( $value ) ) ) {
					// TODO: Log this?
					continue;
				}
			}
		}
	}

	protected function register_settings_hooks() {
		add_action( 'falcon.manager.profile_fields', array( $this, 'output_settings' ) );
		add_action( 'falcon.manager.save_profile_fields', array( $this, 'save_profile_settings' ), 10, 2 );
		add_action( 'falcon.manager.network_profile_fields', array( $this, 'network_notification_settings' ), 10, 2 );
		add_action( 'falcon.manager.save_network_profile_fields', array( $this, 'save_profile_settings' ), 10, 3 );
	}

	public function output_settings( $user = null ) {
		// Are we on the notification defaults screen?
		$is_defaults_screen = empty( $user );

		// Grab defaults and currently set
		$settings = $is_defaults_screen ? $this->get_default_settings() : $this->get_settings_for_user( $user->ID );

		$label_prefix = '';
		$has_multiple = count( Falcon::get_connectors() ) > 1;
		if ( $has_multiple ) {
			$label_prefix = $this->get_name() . ' ';
		}

		$fields = $this->get_settings_fields();

		foreach ( $fields as $key => $options ) {
			printf(
				'<tr><th scope="row">%s</th><td>',
				esc_html( $label_prefix . $options['label'] )
			);
			$this->print_field( $key, $settings, $is_defaults_screen );
			echo '</td></tr>';
		}
	}

	public function network_notification_settings( $user = null, $sites ) {
		// Are we on the notification defaults screen?
		$is_defaults_screen = empty( $user );

		$fields = $this->get_settings_fields();
		$available = $this->get_available_settings();
		$short_names = $this->get_available_settings_short();
		$defaults = $this->get_default_settings();

		$section_label = '';
		$has_multiple = count( Falcon::get_connectors() ) > 1;
		if ( $has_multiple ) {
			$section_label = $this->get_name();
		}

		?>
		<table class="widefat falcon-grid">
			<thead>
				<tr>
					<th class="falcon-grid-section-label"><?php echo esc_html( $section_label ) ?></th>
					<?php
					$last = key( array_slice( $fields, -1, 1, true ) );
					foreach ( $fields as $key => $options ) {
						printf(
							'<th colspan="%d" class="%s">%s</th>',
							esc_attr( count( $available[ $key ] ) ),
							( $key === $last ? 'last_of_col' : '' ),
							esc_html( $options['label'] )
						);
					}
					?>
				</tr>
				<tr>
					<th></th>
					<?php
					foreach ( $available as $type => $opts ) {
						$last = key( array_slice( $opts, -1, 1, true ) );

						foreach ( $opts as $key => $title ) {
							printf(
								'<td class="%s"><abbr title="%s">%s</abbr>%s</td>',
								( $key === $last ? 'last_of_col' : '' ),
								esc_attr( $title ),
								esc_html( $short_names[ $type ][ $key ] ),
								( $key === $defaults[ $type ] ) ? ' <strong>*</strong>' : ''
							);
						}
					}
					?>
				</tr>
			</thead>

			<?php
			foreach ( $sites as $site ):
				$details = get_blog_details( $site );
				$settings = $this->get_settings_for_user( $user->ID, $site );

				$title = esc_html( $details->blogname ) . '<br >';
				$path = $details->path;
				if ( $path === '/' ) {
					$path = '';
				}

				$title .= '<span class="details">' . esc_html( $details->domain . $path ) . '</span>';
				?>
				<tr>
					<th scope="row"><?php echo $title ?></th>

					<?php
					foreach ( $available as $type => $opts ) {
						$default = isset( $defaults[ $type ] ) ? $defaults[ $type ] : false;
						$current = isset( $settings[ $type ] ) ? $settings[ $type ] : $default;

						$name = $this->key_for_setting( 'notifications.' . $type, $site );

						foreach ( $opts as $key => $title ) {
							printf(
								'<td><input type="radio" name="%s" value="%s" %s /></td>',
								esc_attr( $name ),
								esc_attr( $key ),
								checked( $key, $current, false )
							);
						}
					}
					?>
				</tr>
			<?php endforeach ?>
		</table>
		<?php
	}
}
