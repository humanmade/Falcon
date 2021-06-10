<?php

class Falcon_API {
	const USER_PREF_FIELD = 'falcon_preferences';

	/**
	 * Bootstrap.
	 */
	public static function bootstrap() {
		register_rest_field( 'user', static::USER_PREF_FIELD, [
			'get_callback' => [ get_called_class(), 'get_pref_field' ],
			'update_callback' => [ get_called_class(), 'update_pref_field' ],
			'schema' => static::get_pref_schema(),
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
	public static function get_pref_field( $data ) {
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
			$connector_data = apply_filters( "falcon.api.get_pref_field.$type", null, $user );
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
	public static function update_pref_field( $value, WP_User $user ) {
		if ( empty( $value ) ) {
			return true;
		}

		$connectors = Falcon::get_connectors();
		foreach ( $value as $type => $type_options ) {
			if ( empty( $connectors[ $type ] ) ) {
				return new WP_Error(
					'falcon.api.update_pref_field.invalid_type',
					__( 'Attempted to set preference for invalid connector', 'falcon' ),
					compact( 'type' )
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
			$result = apply_filters( "falcon.api.update_pref_field.$type", null, $type_options, $user );
			if ( $result === null ) {
				return new WP_Error(
					'falcon.api.update_pref_field.unhandled_update',
					__( 'Connector does not support updating via the API', 'falcon' ),
					compact( 'type' )
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
	public static function get_pref_schema() {
		$schema = [
			'description' => __( 'Falcon notification preferences', 'falcon' ),
			'type' => 'object',
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
			$connector_schema = apply_filters( "falcon.api.get_pref_schema.$type", null );
			if ( $connector_schema === null ) {
				continue;
			}

			$schema['properties'][ $type ] = $connector_schema;
		}

		return $schema;
	}
}
