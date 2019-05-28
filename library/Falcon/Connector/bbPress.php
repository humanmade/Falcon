<?php

class Falcon_Connector_bbPress extends Falcon_Connector {
	/**
	 * Handler for sending emails.
	 *
	 * @var Falcon_Handler
	 */
	protected $handler;

	/**
	 * Constructor.
	 *
	 * @param Falcon_Handler @handler
	 */
	public function __construct( $handler ) {
		$this->handler = $handler;

		add_action( 'bbp_new_topic', array( $this, 'notify_new_topic' ), 1, 4 );
		add_action( 'bbp_new_reply', array( $this, 'notify_on_reply' ), 1, 5 );

		// Remove built-in bbPress subscription handler.
		remove_action( 'bbp_new_topic', 'bbp_notify_forum_subscribers', 11, 4 );
		remove_action( 'bbp_new_reply', 'bbp_notify_topic_subscribers', 11, 5 );

		add_action( 'falcon.reply.insert', array( $this, 'handle_insert' ), 20, 2 );

		$this->register_settings_hooks();
	}

	/**
	 * Get a human-readable name for the handler
	 *
	 * This is used for the handler selector and is shown to the user.
	 * @return string
	 */
	public static function get_name() {
		return 'bbPress';
	}

	/**
	 * Get a machine-readable ID for the handler.
	 *
	 * This is used for preference handling.
	 *
	 * @return string
	 */
	protected function get_id() {
		return 'bbpress';
	}

	/**
	 * Get text-formatted footer.
	 *
	 * @param string $url URL for the topic/reply
	 * @return string Text footer to append to message.
	 */
	protected function get_text_footer( $url ) {
		$text = "---\n";
		$text .= sprintf( 'Reply to this email directly or view it on %s:', get_option( 'blogname' ) );
		$text .= "\n" . $url . "\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";

		return apply_filters( 'falcon.connector.bbpress.text_footer', $text, $url );
	}

	/**
	 * Get HTML-formatted footer.
	 *
	 * @param string $url URL for the topic/reply
	 * @return string HTML footer to append to message.
	 */
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
	 * @return bool True if notified, false otherwise.
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

	/**
	 * Get text-formatted message for a topic.
	 *
	 * @param int $topic_id Topic ID to notify for.
	 * @return string Plain text message.
	 */
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

	/**
	 * Get HTML-formatted message for a topic.
	 *
	 * @param int $topic_id Topic ID to notify for.
	 * @return string HTML message.
	 */
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
	 * @param int $reply_id Reply that has been created.
	 */
	public function notify_on_reply( $reply_id ) {
		if ($this->handler === null) {
			return false;
		}

		global $wpdb;

		if (!bbp_is_subscriptions_active()) {
			return false;
		}

		$reply_id = bbp_get_reply_id( $reply_id );
		$topic_id = bbp_get_reply_topic_id( $reply_id );

		if ( ! bbp_is_reply_published( $reply_id ) ) {
			return false;
		}

		if ( ! bbp_is_topic_published( $topic_id ) ) {
			return false;
		}

		$user_ids = bbp_get_topic_subscribers( $topic_id, true );
		if ( empty( $user_ids ) ) {
			return false;
		}

		// Poster name
		$reply_author_name = apply_filters('bbsub_reply_author_name', bbp_get_reply_author_display_name($reply_id));

		do_action( 'bbp_pre_notify_subscribers', $reply_id, $topic_id, $user_ids );

		// Don't send notifications to the person who made the post
		$send_to_author = Falcon::get_option('bbsub_send_to_author', false);

		$reply_author = bbp_get_reply_author_id( $reply_id );
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
			'id' => $topic_id,
		);
		$message = new Falcon_Message();
		$message->set_subject( $subject );
		$message->set_text( $this->get_reply_content_as_text( $reply_id, $topic_id ) );
		$message->set_html( $this->get_reply_content_as_html( $reply_id ) );
		$message->set_author( $reply_author_name );
		$message->set_options( $options );
		$this->handler->send_mail( $user_ids, $message );

		do_action( 'bbp_post_notify_subscribers', $reply_id, $topic_id, $user_ids );

		return true;
	}

	/**
	 * Get text-formatted message for a reply.
	 *
	 * @param int $reply_id Reply ID to notify for.
	 * @param int $topic_id Topic the reply belongs to.
	 * @return string Plain text message.
	 */
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

	/**
	 * Get HTML-formatted message for a reply.
	 *
	 * @param int $reply_id Reply ID to notify for.
	 * @return string HTML message.
	 */
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

	/**
	 * Check if the given type is allowed to be replied to.
	 *
	 * @param string $type Post type to check.
	 * @return bool True for allowed types, false otherwise.
	 */
	protected function is_allowed_type( $type ) {
		$allowed = array( 'bbp_topic' );
		return in_array( $type, $allowed );
	}

	/**
	 * Handle inserting a reply.
	 *
	 * @param mixed $value Inserted ID if set, null if not yet handled.
	 * @param Falcon_Reply $reply Reply data being inserted.
	 * @return mixed `$value` if already handled, `false` if invalid, or int reply ID if inserted.
	 */
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

	/**
	 * Get available settings for notifications
	 *
	 * @return array
	 */
	public function get_available_settings() {
		return [
			'topic' => [
				'all' => __( 'All new topics', 'falcon' ),
				''    => __( 'Only subscribed topics', 'falcon' ),
			],

			'reply' => [
				'all'         => __( 'All new replies', 'falcon' ),
				'participant' => __( "New comments on topics I've commented on", 'falcon' ),
				'replies'     => __( 'Replies to my topics', 'falcon' ),
				''            => __( 'Only subscribed topics', 'falcon' )
			],
		];
	}

	public function get_available_settings_short() {
		return array(
			'topic' => array(
				'all' => __( 'All', 'falcon' ),
				''    => __( 'Subscribed', 'falcon' ),
			),

			'reply' => array(
				'all'         => __( 'All', 'falcon' ),
				'participant' => __( "Participant", 'falcon' ),
				'replies'     => __( 'Replies', 'falcon' ),
				''            => __( 'Subscribed', 'falcon' )
			),
		);
	}

	protected function get_settings_fields() {
		return [
			'topic' => [
				'default' => 'all',
				'label' => __( 'Topics', 'falcon' ),
			],
			'reply' => [
				'default' => 'all',
				'label' => __( 'Replies', 'falcon' ),
			],
		];
	}

	/**
	 * Output the settings fields.
	 *
	 * Overridden to add note about subscriptions.
	 *
	 * @inheritDoc
	 */
	public function output_settings( $user = null ) {
		parent::output_settings( $user );

		echo '<tr><td colspan="2"><p class="description">';
		_e( '<strong>Note:</strong> Notifications will always be sent for subscribed topics or forums.', 'falcon' );
		echo '</p></td></tr>';
	}

	/**
	 * Output the network-mode settings.
	 *
	 * Overridden to add note about subscriptions.
	 *
	 * @inheritDoc
	 */
	public function network_notification_settings( $user = null, $sites ) {
		parent::network_notification_settings( $user, $sites );

		echo '<p class="description">';
		_e( '<strong>Note:</strong> Notifications will always be sent for subscribed topics or forums.', 'falcon' );
		echo '</p>';
	}
}
