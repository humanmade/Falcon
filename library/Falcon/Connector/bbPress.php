<?php

class Falcon_Connector_bbPress {
	protected $handler;

	public function __construct( $handler ) {
		$this->handler = $handler;

		add_action( 'bbp_new_topic', array( $this, 'notify_new_topic' ), 10, 4 );
		add_action( 'bbp_new_reply', array( $this, 'notify_on_reply'  ),  1, 5 );

		// Remove built-in bbPress subscription handler.
		remove_action( 'bbp_new_topic', 'bbp_notify_forum_subscribers', 11, 4 );
		remove_action( 'bbp_new_reply', 'bbp_notify_topic_subscribers', 11, 5 );

		add_action( 'falcon.reply.insert', array( $this, 'handle_insert' ), 20, 2 );
	}

	protected function get_text_footer( $url ) {
		$text = "---\n";
		$text .= sprintf( 'Reply to this email directly or view it on %s:', get_option( 'blogname' ) );
		$text .= "\n" . $url . "\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";

		return apply_filters( 'falcon.connector.bbpress.text_footer', $text, $url );
	}

	protected function get_html_footer( $url ) {
		$footer = '<p style="font-size:small;-webkit-text-size-adjust:none;color:#666;">&mdash;<br>';
		$footer .= sprintf(
			'Reply to this email directly or <a href="%s">view it on %s</a>.',
			$url,
			get_option( 'blogname' )
		);
		$footer .= '</p>';

		return apply_filters( 'falcon.connector.wordpress.html_footer', $footer, $url );
	}

	/**
	 * Notify user roles on new topic
	 *
	 * @param int $topic_id Topic that has been created.
	 */
	public function notify_new_topic( $topic_id ) {
		if ( empty( $this->handler ) || ! Falcon::is_enabled_for_site() ) {
			return false;
		}

		if ( ! bbp_is_topic_published( $topic_id ) ) {
			return false;
		}

		$recipients = $this->get_topic_subscribers( $topic_id );

		$subject = sprintf(
			'[%s] %s',
			get_option( 'blogname' ),
			html_entity_decode( bbp_get_topic_title( $topic_id ), ENT_QUOTES )
		);
		$subject = apply_filters( 'bbsub_topic_email_subject', $subject, $topic_id);

		$options = [
			'author' => bbp_get_topic_author_display_name( $topic_id ),
			'id'     => $topic_id,
		];
		$message = new Falcon_Message();
		$message->set_subject( $subject );
		$message->set_text( $this->get_topic_content_as_text( $topic_id ) );
		$message->set_html( $this->get_topic_content_as_html( $topic_id ) );
		$message->set_author( bbp_get_topic_author_display_name( $topic_id ) );
		$message->set_options( $options );

		// Fire legacy actions for bbPress.
		$forum_id = bbp_get_topic_forum_id( $topic_id );
		$user_ids = array_map( function ( WP_User $user ) {
			return $user->ID;
		}, $recipients );
		do_action( 'bbp_pre_notify_forum_subscribers', $topic_id, $forum_id, $user_ids );

		$this->handler->send_mail( $recipients, $message );

		do_action( 'bbp_post_notify_forum_subscribers', $topic_id, $forum_id, $user_ids );

		return true;
	}

	/**
	 * Get all subscribers for topic notifications
	 *
	 * @param int $topic_id Topic ID
	 * @return WP_User[]
	 */
	protected function get_topic_subscribers( $topic_id ) {
		$forum_id = bbp_get_topic_forum_id( $topic_id );

		$recipients = [];

		// Get topic subscribers and bail if empty
		$bbpress_subscribers = bbp_get_forum_subscribers( $forum_id, true );
		$bbpress_subscribers = apply_filters( 'bbp_forum_subscription_user_ids', $bbpress_subscribers );
		foreach ( $bbpress_subscribers as $user ) {
			$recipients[] = get_user_by( 'id', $user );
		}

		// Find any roles that should be subscribed too.
		$user_roles = Falcon::get_option( 'bbsub_topic_notification', array() );
		if ( ! empty( $user_roles ) ) {
			foreach ($user_roles as $role) {
				$users = get_users( [ 'role' => $role ] );
				$recipients = array_merge( $recipients, $users );
			}
		}

		return $recipients;
	}

	protected function get_topic_content_as_text( $topic_id ) {
		$content = bbp_get_topic_content( $topic_id );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = $content . "\n\n" . $this->get_text_footer( bbp_get_topic_permalink( $topic_id ) );

		// Run legacy filter.
		$text = apply_filters_deprecated(
			'bbsub_topic_email_message',
			[ $text, $topic_id, $content ],
			'Falcon-0.5',
			'falcon.connector.bbpress.topic_content_text'
		);

		/**
		 * Filter the email content
		 *
		 * Use this to change document formatting, etc
		 *
		 * @param string $text Text content
		 * @param int $topic_id ID for the topic
		 */
		return apply_filters( 'falcon.connector.bbpress.topic_content_text', $text, $topic_id );
	}

	protected function get_topic_content_as_html( $topic_id ) {
		$content = bbp_get_topic_content( $topic_id );

		$text = $content . "\n\n" . $this->get_html_footer( bbp_get_topic_permalink( $topic_id ) );

		/**
		 * Filter the email content
		 *
		 * Use this to add tracking codes, metadata, etc
		 *
		 * @param string $text HTML content
		 * @param int $topic_id ID for the topic
		 */
		return apply_filters( 'falcon.connector.bbpress.topic_content_html', $text, $topic_id );
	}

	/**
	 * Send a notification to subscribers
	 *
	 * @wp-filter bbp_new_reply 1
	 */
	public function notify_on_reply( $reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0 ) {
		if ($this->handler === null) {
			return false;
		}

		global $wpdb;

		if (!bbp_is_subscriptions_active()) {
			return false;
		}

		$reply_id = bbp_get_reply_id( $reply_id );
		$topic_id = bbp_get_topic_id( $topic_id );
		$forum_id = bbp_get_forum_id( $forum_id );

		if (!bbp_is_reply_published($reply_id)) {
			return false;
		}

		if (!bbp_is_topic_published($topic_id)) {
			return false;
		}

		$user_ids = bbp_get_topic_subscribers($topic_id, true);
		if (empty($user_ids)) {
			return false;
		}

		// Poster name
		$reply_author_name = apply_filters('bbsub_reply_author_name', bbp_get_reply_author_display_name($reply_id));

		do_action( 'bbp_pre_notify_subscribers', $reply_id, $topic_id, $user_ids );

		// Don't send notifications to the person who made the post
		$send_to_author = Falcon::get_option('bbsub_send_to_author', false);

		if (!$send_to_author && !empty($reply_author)) {
			$user_ids = array_filter($user_ids, function ($id) use ($reply_author) {
				return ((int) $id !== (int) $reply_author);
			});
		}

		// Get userdata for all users
		$user_ids = array_map(function ($id) {
			return get_userdata($id);
		}, $user_ids);

		// Build email
		$subject = apply_filters( 'bbsub_email_subject', 'Re: [' . get_option( 'blogname' ) . '] ' . bbp_get_topic_title( $topic_id ), $reply_id, $topic_id );

		$options = array(
			'id'     => $topic_id,
			'author' => $reply_author_name,
		);
		$message = new Falcon_Message();
		$message->set_subject( $subject );
		$message->set_text( $this->get_reply_content_as_text( $reply_id, $topic_id ) );
		$message->set_html( $this->get_reply_content_as_html( $reply_id ) );
		$message->set_options( $options );
		$this->handler->send_mail( $user_ids, $message );

		do_action( 'bbp_post_notify_subscribers', $reply_id, $topic_id, $user_ids );

		return true;
	}

	protected function get_reply_content_as_text( $reply_id, $topic_id ) {
		$content = bbp_get_reply_content( $reply_id );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = $content . "\n\n" . $this->get_text_footer( bbp_get_reply_url( $reply_id ) );

		// Run legacy filter.
		$text = apply_filters_deprecated(
			'bbsub_email_message',
			[ $text, $reply_id, $topic_id, $content ],
			'Falcon-0.5',
			'falcon.connector.bbpress.reply_content_text'
		);

		/**
		 * Filter the email content
		 *
		 * Use this to change document formatting, etc
		 *
		 * @param string $text Text content
		 * @param int $reply_id ID for the reply
		 */
		return apply_filters( 'falcon.connector.bbpress.reply_content_text', $text, $reply_id );
	}

	protected function get_reply_content_as_html( $reply_id ) {
		$content = bbp_get_reply_content( $reply_id );

		$text = $content . "\n\n" . $this->get_html_footer( bbp_get_reply_url( $reply_id ) );

		/**
		 * Filter the email content
		 *
		 * Use this to add tracking codes, metadata, etc
		 *
		 * @param string $text HTML content
		 * @param int $reply_id ID for the reply
		 */
		return apply_filters( 'falcon.connector.bbpress.reply_content_html', $text, $reply_id );
	}

	protected function is_allowed_type( $type ) {
		$allowed = array( 'bbp_topic' );
		return in_array( $type, $allowed );
	}

	public function handle_insert( $value, Falcon_Reply $reply ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		$post = get_post( $reply->post );
		if ( ! $this->is_allowed_type( $post->post_type ) ) {
			return $value;
		}

		$user = $reply->get_user();

		if ( $reply->is_valid() ) {
			Falcon::notify_invalid( $user, bbp_get_topic_title( $reply->post ) );
			return false;
		}

		$new_reply = array(
			'post_parent'   => $reply->post, // topic ID
			'post_author'   => $user->ID,
			'post_content'  => $reply->parse_body(),
			'post_title'    => $reply->subject,
		);
		$meta = array(
			'author_ip' => '127.0.0.1', // we could parse Received, but it's a pain, and inaccurate
			'forum_id' => bbp_get_topic_forum_id($reply->post),
			'topic_id' => $reply->post
		);

		$reply_id = bbp_insert_reply($new_reply, $meta);

		do_action( 'bbp_new_reply', $reply_id, $meta['topic_id'], $meta['forum_id'], false, $new_reply['post_author'] );

		// bbPress removes the user's subscription because bbp_update_reply() is hooked to 'bbp_new_reply' and it checks for $_POST['bbp_topic_subscription']
		bbp_add_user_subscription( $new_reply['post_author'], $meta['topic_id'] );

		return $reply_id;
	}

	public function register_settings() {
		register_setting( 'bbsub_options', 'bbsub_topic_notification', array(__CLASS__, 'validate_topic_notification') );

		add_settings_section('bbsub_options_bbpress', 'bbPress', '__return_null', 'bbsub_options');
		add_settings_field('bbsub_options_bbpress_topic_notification', 'New Topic Notification', array(__CLASS__, 'settings_field_topic_notification'), 'bbsub_options', 'bbsub_options_bbpress');
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

		$options = Falcon::get_option( 'bbsub_topic_notification', array() );

		foreach ($wp_roles->get_names() as $key => $role_name) {
			$current = in_array($key, $options) ? $key : '0';
			?>
			<label>
				<input type="checkbox" value="<?php echo esc_attr( $key ); ?>" name="bbsub_topic_notification[]" <?php checked( $current, $key ); ?> />
				<?php echo $role_name; ?>
			</label>
			<br />
			<?php
		}

		echo '<span class="description">' . __( 'Sends new topic email and auto subscribe the users from these role to the new topic', 'bbsub' ) . '</span>';
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
