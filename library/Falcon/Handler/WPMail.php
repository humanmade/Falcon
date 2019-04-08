<?php

/**
 * WP Mail Handler
 *
 * @author Joe Hoyle <joe@humanmade.com>
 * @package Falcon
 * @subpackage Handlers
 */
class Falcon_Handler_WPMail implements Falcon_Handler {

	public function __construct( $options ) {

	}

	public function check_inbox() {}

	public function send_mail( $users, Falcon_Message $message ) {
		$options = $message->get_options();

		$from = Falcon::get_from_address();
		$author = $message->get_author();

		$messages = array();
		foreach ( $users as $user ) {

			$headers = array(
				'From'         => sprintf( '%s <%s>', $author, $from ),
				'Reply-To'     => sprintf( '%s', $message->get_reply_address( $user ) ),
				'Content-Type' => 'text/html',
			);

			// Set the message ID if we've got one
			if ( ! empty( $options['message-id'] ) ) {
				$headers['Message-ID'] = $options['message-id'];
			}

			// If this is a reply, set the headers as needed
			if ( ! empty( $options['in-reply-to'] ) ) {
				$original = $options['in-reply-to'];
				if ( is_array( $original ) ) {
					$original = isset( $options['in-reply-to'][ $user->ID ] ) ? $options['in-reply-to'][ $user->ID ] : null;
				}

				if ( ! empty( $original ) ) {
					$headers['In-Reply-To'] = $original;
				}
			}

			if ( ! empty( $options['references'] ) ) {
				$references = implode( ' ', $options['references'] );
				$headers['References'] = $references;
			}

			foreach ( $headers as $header => $value ) {
				$headers[ $header ] = $header . ': ' . $value;
			}

			$result = wp_mail(
				sprintf( '%s <%s>', $user->display_name, $user->user_email ),
				$message->get_subject(),
				$message->get_html(),
				array_values( $headers )
			);

			if ( ! $result ) {
				trigger_error( 'wp_mail() failed to send message.', E_USER_WARNING );
			}
		}

		return null;
	}

	public static function get_name() {
		return __( 'WP Mail', 'falcon' );
	}

	public static function supports_message_ids() {
		return false;
	}

	public static function options_section_header() {}

	public static function register_option_fields( $group, $section, $options ) {}

	/**
	 * Validate the options from the submitted form
	 *
	 * @param array $input Raw POSTed data
	 * @return array Sanitized POST data
	 */
	public static function validate_options( $input ) {
		return $input;
	}

	public function handle_post() {}
}
