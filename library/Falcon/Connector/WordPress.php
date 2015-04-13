<?php

class Falcon_Connector_WordPress {
	const SENT_META_KEY = 'falcon_sent';
	const MESSAGE_ID_KEY = 'falcon_message_ids';

	protected $handler;

	public function __construct( $handler ) {
		$this->handler = $handler;

		add_action( 'publish_post', array( $this, 'notify_on_publish' ), 10, 2 );
	}

	public static function is_allowed_type( $type ) {
		// Only notify for allowed types
		$allowed_types = apply_filters( 'falcon.connector.wordpress.post_types', array( 'post' ) );
		return in_array( $type, $allowed_types );
	}

	/**
	 * Notify user roles on new topic
	 */
	public function notify_on_publish( $id = 0, $post = null ) {
		if ( empty( $this->handler ) ) {
			return;
		}

		// Double-check status
		if ( get_post_status( $id ) !== 'publish' ) {
			return;
		}

		// Only notify for allowed types
		$allowed_types = apply_filters( 'falcon.connector.wordpress.post_types', array( 'post' ) );
		if ( ! $this->is_allowed_type( $post->post_type ) ) {
			return;
		}

		// Don't notify if we're already done so for the post
		$has_sent = get_post_meta( $id, static::SENT_META_KEY, true );

		/**
		 * Should we send a publish notification?
		 *
		 * This is based on meta by default to avoid double-sending, but
		 * override to change the logic to whatever you like for
		 * post publishing.
		 *
		 * @param bool $should_notify Should we notify for this event?
		 * @param WP_Post $post Post we're going to notify for
		 */
		$should_notify = apply_filters( 'falcon.connector.wordpress.should_notify_publish', ! $has_sent, $post );

		if ( ! $should_notify ) {
			return;
		}

		$recipients = $this->get_post_subscribers( $post );

		// still no users?
		if ( empty( $recipients ) ) {
			return;
		}

		$content = apply_filters( 'the_content', $post->post_content );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = "%1\$s\n\n";
		$text .= "---\nReply to this email directly or view it online:\n%2\$s\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";
		$text = sprintf( $text, $content, get_permalink( $id ) );
		$text = apply_filters( 'bbsub_topic_email_message', $text, $id, $content );
		$subject = apply_filters( 'bbsub_topic_email_subject', '[' . get_option( 'blogname' ) . '] ' . get_the_title( $id ), $id );

		$options = array(
			'author' => get_the_author_meta( 'display_name', $post->post_author ),
			'id'     => $id,
		);

		$responses = $this->handler->send_mail( $recipients, $subject, $text, $options );
		if ( ! empty( $responses ) ) {
			update_post_meta( $id, 'falcon_message_ids', $responses );
		}

	}

	protected function get_post_subscribers( WP_Post $post ) {
		$user_roles = get_option( 'falcon_wp_auto_roles', array() );

		// Bail out if no user roles found
		if ( empty( $user_roles ) ) {
			return array();
		}

		$recipients = array();
		foreach ( $user_roles as $role ) {
			$users = get_users( array(
				'role' => $role,
				'fields' => array(
					'ID',
					'user_email',
					'display_name'
				)
			) );

			$recipients = array_merge( $recipients, $users );
		}

		return $recipients;
	}

	public function register_settings() {
		return;

		register_setting( 'bbsub_options', 'falcon_wp_auto_roles', array(__CLASS__, 'validate_topic_notification') );

		add_settings_section('bbsub_options_bbpress', 'WordPress', '__return_null', 'bbsub_options');
		add_settings_field('bbsub_options_bbpress_topic_notification', 'New Post Notification', array(__CLASS__, 'settings_field_topic_notification'), 'bbsub_options', 'bbsub_options_bbpress');
	}

	/**
	 * Print field for new topic notification
	 *
	 * @see self::init()
	 */
	public static function settings_field_topic_notification() {
		global $wp_roles;

		if ( !$wp_roles ) {
			$wp_roles = new WP_Roles();
		}

		$options = get_option( 'falcon_wp_auto_roles', array() );

		foreach ($wp_roles->get_names() as $key => $role_name) {
			$current = in_array($key, $options) ? $key : '0';
			?>
			<label>
				<input type="checkbox" value="<?php echo esc_attr( $key ); ?>" name="falcon_wp_auto_roles[]" <?php checked( $current, $key ); ?> />
				<?php echo $role_name; ?>
			</label>
			<br />
			<?php
		}

		echo '<span class="description">' . __( 'Sends new topic email and auto-subscribe the users from these role to the new topic', 'bbsub' ) . '</span>';
	}

	/**
	 * Validate the new topic notification
	 *
	 * @param array $input
	 * @return array
	 */
	public function validate_topic_notification( $input ) {
		return is_array( $input ) ? $input : array();
	}
}
