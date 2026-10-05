<?php

class Falcon_REST {
	const USER_PREFERENCES_FIELD = 'falcon_preferences';

	/**
	 * Bootstrap.
	 */
	public static function bootstrap() {
		register_rest_field( 'user', static::USER_PREFERENCES_FIELD, [
			'get_callback' => [ get_called_class(), 'get_preferences_field' ],
			'update_callback' => [ get_called_class(), 'update_preferences_field' ],
			'schema' => static::get_preferences_schema(),
		] );
	}

	/**
	 * Get the preferences field value.
	 *
	 * Calls out to the enabled connectors.
	 *
	 * @param array $data Full response data.
	 * @return array Data for the field.
	 */
	public static function get_preferences_field( $data ) {
		$user = get_user_by( 'id', $data['id'] );
		$field_data = [];
		foreach ( Falcon::get_connectors() as $type => $connector ) {
			/**
			 * Filter preference field value.
			 *
			 * Connect to this in your connector to make the data available
			 * via the REST API.
			 *
			 * @param mixed $connector_data Data for your connector. Null to skip in the API.
			 * @param WP_User $user User to get data for.
			 */
			$connector_data = apply_filters( "falcon.rest.get_preferences_field.$type", null, $user );
			if ( $connector_data === null ) {
				continue;
			}

			$field_data[ $type ] = $connector_data;
		}
		return $field_data;
	}

	/**
	 * Update the preferences field value.
	 *
	 * Calls out to the enabled connectors.
	 *
	 * @param array $value Value passed by the user (validated by the schema).
	 * @param WP_User $user User being updated.
	 * @return boolean|WP_Error True if field was updated, error otherwise.
	 */
	public static function update_preferences_field( $value, WP_User $user ) {
		if ( empty( $value ) ) {
			return true;
		}

		$connectors = Falcon::get_connectors();
		foreach ( $value as $type => $type_options ) {
			if ( empty( $connectors[ $type ] ) ) {
				return new WP_Error(
					'falcon.rest.update_preferences_field.invalid_type',
					__( 'Attempted to set preference for invalid connector', 'falcon' ),
					[
						'type' => $type,
						'status' => WP_Http::BAD_REQUEST,
					]
				);
			}

			/**
			 * Filter the result of updating the field.
			 *
			 * Connect to this in your connector to make the data updatable via
			 * the REST API.
			 *
			 * @param mixed $result True if field was updated, WP_Error if cannot update, null if unhandled.
			 */
			$result = apply_filters( "falcon.rest.update_preferences_field.$type", null, $type_options, $user );
			if ( $result === null ) {
				return new WP_Error(
					'falcon.rest.update_preferences_field.unhandled_update',
					__( 'Connector does not support updating via the API', 'falcon' ),
					[
						'type' => $type,
						'status' => WP_Http::BAD_REQUEST,
					]
				);
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Get the schema for the preferences field.
	 *
	 * Calls out to the enabled connectors.
	 *
	 * @return array Schema for the preferences field.
	 */
	public static function get_preferences_schema() {
		$schema = [
			'description' => __( 'Falcon notification preferences', 'falcon' ),
			'type' => 'object',
			// Preferences are private to the user, so never expose in the
			// public view context.
			'context' => [ 'edit' ],
			'properties' => [],
		];

		foreach ( Falcon::get_connectors() as $type => $connector ) {
			/**
			 * Filter preference field schema.
			 *
			 * Connect to this in your connector to make the data available
			 * via the REST API.
			 *
			 * @param mixed $connector_schema Schema for your connector. Null to skip in the API.
			 */
			$connector_schema = apply_filters( "falcon.rest.get_preferences_schema.$type", null );
			if ( $connector_schema === null ) {
				continue;
			}

			$schema['properties'][ $type ] = $connector_schema;
		}

		return $schema;
	}
}
