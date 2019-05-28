<?php

class Falcon_Handler_SES extends Falcon_Handler_WPMail {
	/**
	 * Get a human-readable name for the SES handler
	 *
	 * @return string
	 */
	public static function get_name() {
		return __( 'Amazon SES', 'falcon' );
	}

	/**
	 * Register handler-specific option fields
	 *
	 * @see bbSubscriptions_Handler::register_option_fields
	 * @param string $group Settings group (4th parameter to `add_settings_field`)
	 * @param string $section Settings section (5th parameter to `add_settings_field`)
	 * @param array $options Current options
	 */
	public static function register_option_fields($group, $section, $options) {
		add_settings_field(
			'bbsub_ses_outbound_status',
			__( 'Outbound Status', 'falcon' ),
			[ __CLASS__, 'render_outbound_status' ],
			$group,
			$section
		);
		add_settings_field(
			'bbsub_ses_inbound_status',
			__( 'Inbound Status', 'falcon' ),
			[ __CLASS__, 'render_inbound_status' ],
			$group,
			$section
		);
	}

	/**
	 * Render the outbound status field.
	 */
	public static function render_outbound_status() {
		// Check outbound status
		$has_ses = class_exists( 'AWS_SES_WP_Mail\\SES' );
		if ( ! $has_ses ) {
			printf(
				__( '⚠️ Unable to detect <a href="%s">AWS SES WP_Mail</a>, ensure you have set it up to send mail.', 'falcon' ),
				'https://github.com/humanmade/aws-ses-wp-mail'
			);
		} else {
			esc_html_e( '✅ AWS SES WP_Mail is installed.' );
		}
	}

	/**
	 * Render the inbound status field.
	 */
	public static function render_inbound_status() {
		$topic = Falcon::get_option( 'bbsub_ses_topic_arn', null );
		if ( ! $topic ) {
			esc_html_e( 'Waiting for ping from SNS…', 'falcon' );
		} else {
			printf(
				__( '✅ SNS ping received, topic is <code>%s</code>', 'falcon' ),
				$topic
			);
		}
	}

	/**
	 * Output a description for the options
	 */
	public static function options_section_header() {
?>
	<p><?php printf(
		__( 'Follow <a href="%s">these instructions</a> to set up Amazon SES to send and receive email.', 'falcon' ),
		'https://github.com/rmccue/Falcon/blob/master/docs/amazon-ses.md'
	) ?></p>

	<p><?php printf(
		__( 'Your subscription Endpoint is <code>%s</code>', 'falcon' ),
		admin_url('admin-post.php?action=bbsub')
	) ?></p>
<?php
	}

	public function send_mail( $users, Falcon_Message $message ) {
		// Filter arguments to ensure both text and HTML are sent.
		$callback = function ( $message_args ) use ( $message ) {
			// Remove default HTML/text.
			unset( $message_args['text'] );
			unset( $message_args['html'] );

			// Re-add as appropriate.
			if ( $text = $message->get_text() ) {
				$message_args['text'] = $text;
			}
			if ( $html = $message->get_html() ) {
				$message_args['html'] = $html;
			}
			return $message_args;
		};

		add_filter( 'aws_ses_wp_mail_message_args', $callback );

		$res = parent::send_mail( $users, $message );

		remove_filter( 'aws_ses_wp_mail_message_args', $callback );

		return $res;
	}

	public function handle_post() {
		$input = file_get_contents( 'php://input' );
		if ( empty( $input ) ) {
			header( 'X-Fail: No input', true, 400 );
			echo 'No input found.'; // intentionally not translated
			return;
		}

		$data = json_decode( $input );
		if ( ! $data && json_last_error() !== JSON_ERROR_NONE ) {
			header( 'X-Fail: Bad input', true, 400 );
			echo 'Could not decode input.'; // intentionall not translated
			return;
		}

		// Determine the type of SNS notification.
		$type = $_SERVER['HTTP_X_AMZ_SNS_MESSAGE_TYPE'] ?? 'no-header';

		switch ( $type ) {
			case 'SubscriptionConfirmation':
				$this->handle_subscription_confirmation( $data );
				break;

			case 'Notification':
				$this->handle_incoming_email( $data );
				break;

			default:
				header( 'X-Fail: Unknown type', true, 400 );
				printf( 'Could not parse type "%s"', $type );
				break;
		}
	}

	/**
	 * Handle incoming subscription confirmation events from SES.
	 *
	 * We need to fetch the subscribe URL to confirm our subscription.
	 *
	 * @param stdClass $data Data object from handle_post()
	 */
	protected function handle_subscription_confirmation( $data ) {
		// Send a GET request to the subscription confirmation URL.
		$result = wp_remote_get( $data->SubscribeURL );
		if ( wp_remote_retrieve_response_code( $result ) !== 200 ) {
			header( 'X-Fail: Could not confirm', true, 500 );
			echo 'Could not confirm subscription.';
			return;
		}

		// Mark it as confirmed.
		Falcon::update_option( 'bbsub_ses_topic_arn', $data->TopicArn );
	}

	/**
	 * Handle incoming email events from SES.
	 *
	 * @link https://docs.aws.amazon.com/ses/latest/DeveloperGuide/receiving-email-notifications-contents.html
	 *
	 * @param stdClass $data Data object from handle_post()
	 */
	protected function handle_incoming_email( $data ) {
		if ( $data->notificationType !== 'Received' ) {
			// "For this type of notification, the value is always Received."
			// https://docs.aws.amazon.com/ses/latest/DeveloperGuide/receiving-email-notifications-contents.html
			return;
		}

		$reply = new Falcon_Reply();
		$reply->subject = $data->mail->commonHeaders->subject;
		$reply->body = $this->get_body_from_raw_message( $data->content, $data->mail->headers );

		$to = $data->mail->commonHeaders->to;
		list( $reply->post, $reply->site, $reply->user, $reply->nonce ) = Falcon_Reply::parse_to( $to[0] );

		$reply_id = $reply->insert();
		if ($reply_id === false) {
			header('X-Fail: No reply ID', true, 400);
			echo 'Reply could not be added?'; // intentionally not translated
			// Log this?
		}
	}

	/**
	 * Parse the text email body from the raw message data.
	 *
	 * SES only parses the headers for us, we need to get the raw body data
	 * back out.
	 *
	 * @param string $message Base 64-encoded raw MIME message.
	 * @param array $headers Parsed headers from SES.
	 * @return string|null Plain text body if available, then HTML if available, or null if neither are available.
	 */
	public function get_body_from_raw_message( $message, $headers ) {
		// Split headers and body.
		$decoded = base64_decode( $message );
		list( $raw_head, $raw_body ) = explode( "\r\n\r\n", $decoded, 2 );

		// Check content-type, and split if necessary.
		$content_type = null;
		$boundary = null;
		foreach ( $headers as $header ) {
			if ( strtolower( $header->name ) !== 'content-type' ) {
				continue;
			}

			$parts = explode( ';', $header->value, 2 );
			$content_type = $parts[0];
			if ( $content_type === 'multipart/alternative' ) {
				if ( ! preg_match( '#boundary="([A-Z0-9\'()+_,\-./:=? ]+)"#i', $parts[1], $matches ) ) {
					continue;
				}

				$boundary = $matches[1];
			}
			break;
		}

		// If it's not multipart, shortcircuit.
		if ( $content_type !== 'multipart/alternative' ) {
			return $raw_body;
		}

		if ( empty( $boundary ) ) {
			return null;
		}

		// Parse the parts out.
		$body_parts = explode( '--' . $boundary, $raw_body );
		$parsed_parts = [];
		foreach ( $body_parts as $part ) {
			if ( empty( $part ) || trim( $part ) === '--' ) {
				// Empty/last part, skip.
				continue;
			}

			list( $part_head, $part_body ) = explode( "\r\n\r\n", $part, 2 );
			$part_header_lines = explode( "\r\n", ltrim( $part_head ) );

			// Find content-type.
			$part_type = null;
			$part_headers = [];
			foreach ( $part_header_lines as $line )  {
				list( $key, $value ) = explode( ':', $line, 2 );
				$part_headers[ strtolower( $key ) ] = ltrim( $value );
			}

			if ( empty( $part_headers['content-type'] ) ) {
				continue;
			}

			list( $part_type, $etc ) = explode( ';', $part_headers['content-type'], 2 );
			$part_type = trim( $part_type );
			if ( empty( $part_type ) ) {
				continue;
			}

			// Decode content if we need to.
			if ( ! empty( $part_headers['content-transfer-encoding'] ) && $part_headers['content-transfer-encoding'] === 'quoted-printable' ) {
				$part_body = quoted_printable_decode( $part_body );
			}

			$parsed_parts[ $part_type ] = rtrim( $part_body, "\r\n" );
		}

		// Finally, get text/plain if we can, or fall back to HTML.
		if ( isset( $parsed_parts['text/plain'] ) ) {
			return $parsed_parts['text/plain'];
		}

		if ( isset( $parsed_parts['text/html'] ) ) {
			return $parsed_parts['text/html'];
		}

		return null;
	}
}
