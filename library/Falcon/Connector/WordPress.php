<?php

class Falcon_Connector_WordPress {
	const SENT_META_KEY = 'falcon_sent';
	const MESSAGE_ID_KEY = 'falcon_message_ids';

	/**
	 * Sending handler
	 *
	 * @var Falcon_Handler
	 */
	protected $handler;

	public function __construct( $handler ) {
		$this->handler = $handler;

		add_action( 'publish_post', array( $this, 'notify_on_publish' ), 10, 2 );

		add_action( 'wp_insert_comment', array( $this, 'notify_on_reply' ), 10, 2 );
		add_action( 'comment_approve_comment', array( $this, 'notify_on_reply' ), 10, 2 );

		add_action( 'falcon.reply.insert', array( $this, 'handle_insert' ), 20, 2 );
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

		$message = new Falcon_Message();

		$message->set_text( $this->get_post_content_as_text( $post ) );

		$subject = apply_filters( 'bbsub_topic_email_subject', '[' . get_option( 'blogname' ) . '] ' . get_the_title( $id ), $id );
		$message->set_subject( $subject );

		$message->set_author( get_the_author_meta( 'display_name', $post->post_author ) );

		$options = array();
		if ( $this->handler->supports_message_ids() ) {
			$options['message-id'] = $this->get_message_id_for_post( $post );
		}
		$message->set_options( $options );

		$message->set_reply_address_handler( function ( WP_User $user, Falcon_Message $message ) use ( $post ) {
			return Falcon::get_reply_address( $post->ID, $user );
		} );

		$responses = $this->handler->send_mail( $recipients, $message );
		if ( ! $this->handler->supports_message_ids() && ! empty( $responses ) ) {
			update_post_meta( $id, self::MESSAGE_ID_KEY, $responses );
		}

		// Stop any future double-sends
		update_post_meta( $id, static::SENT_META_KEY, true );
	}

	protected function get_post_content_as_text( $post ) {
		$content = apply_filters( 'the_content', $post->post_content );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = "%1\$s\n\n";
		$text .= "---\nReply to this email directly or view it online:\n%2\$s\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the topic to unsubscribe from these emails.";
		$text = sprintf( $text, $content, get_permalink( $post->ID ) );

		return $text;
	}

	/**
	 * Send a notification to subscribers
	 */
	public function notify_on_reply( $id = 0, $comment = null ) {
		if ( empty( $this->handler ) ) {
			return false;
		}

		if ( wp_get_comment_status( $comment ) !== 'approved' ) {
			return false;
		}

		// Is the post published?
		$post = get_post( $comment->comment_post_ID );
		if ( get_post_status( $post ) !== 'publish' ) {
			return false;
		}

		// Grab the users we should notify
		$users = $this->get_comment_subscribers( $comment );
		if ( empty( $users ) ) {
			return false;
		}

		$message = new Falcon_Message();

		// Poster name
		$message->set_author( apply_filters( 'falcon.connector.wordpress.comment_author', $comment->comment_author ) );

		// Don't send notifications to the person who made the post
		$send_to_author = get_option('bbsub_send_to_author', false);

		if ( ! $send_to_author && ! empty( $comment->user_id ) ) {
			$author = (int) $comment->user_id;

			$users = array_filter( $users, function ($user) use ($author) {
				return $user->ID !== $author;
			} );
		}

		// Sanitize the HTML into text
		$content = apply_filters( 'comment_text', get_comment_text( $comment ) );
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = "%1\$s\n\n";
		$text .= "---\nReply to this post directly or view it online:\n%2\$s\n\n";
		$text .= "You are receiving this email because you subscribed to it. Login and visit the post to unsubscribe from these emails.";
		$text = sprintf( $text, $content, get_comment_link( $comment ) );
		$message->set_text( apply_filters( 'bbsub_email_message', $text, $id, $post->ID, $content ) );

		$subject = apply_filters('bbsub_email_subject', 'Re: [' . get_option( 'blogname' ) . '] ' . get_the_title( $post ), $id, $post->ID);
		$message->set_subject( $subject );

		$message->set_reply_address_handler( function ( WP_User $user, Falcon_Message $message ) use ( $post ) {
			return Falcon::get_reply_address( $post->ID, $user );
		} );

		$options = array();

		if ( $this->handler->supports_message_ids() ) {
			$options['references']  = $this->get_references_for_comment( $comment );
			$options['message-id']  = $this->get_message_id_for_comment( $comment );

			if ( ! empty( $comment->comment_parent ) ) {
				$parent = get_comment( $comment->comment_parent );
				$options['in-reply-to'] = $this->get_message_id_for_comment( $parent );
			}
			else {
				$options['in-reply-to'] = $this->get_message_id_for_post( $post );
			}
		}
		else {
			$message_ids = get_post_meta( $id, self::MESSAGE_ID_KEY, $responses );
			$options['in-reply-to'] = $message_ids;
		}

		$message->set_options( $options );

		$this->handler->send_mail( $users, $message );

		return true;
	}

	/**
	 * Get the Message ID for a post
	 *
	 * @param WP_Post $post Post object
	 * @return string Message ID
	 */
	protected function get_message_id_for_post( WP_Post $post ) {
		$left = 'falcon/' . $post->post_type . '/' . $post->ID;
		$right = parse_url( home_url(), PHP_URL_HOST );

		$id = sprintf( '<%s@%s>', $left, $right );

		/**
		 * Filter message IDs for posts
		 *
		 * @param string $id Message ID (conforming to RFC5322 Message-ID semantics)
		 * @param WP_Post $post Post object
		 */
		return apply_filters( 'falcon.connector.wordpress.post_message_id', $id, $post );
	}

	/**
	 * Get the Message ID for a comment
	 *
	 * @param stdClass $comment Comment object
	 * @return string Message ID
	 */
	protected function get_message_id_for_comment( $comment ) {
		$post = get_post( $comment->comment_post_ID );
		$type = $comment->comment_type;
		if ( empty( $type ) ) {
			$type = 'comment';
		}

		$left = 'falcon/' . $post->post_type . '/' . $post->ID . '/' . $type . '/' . $comment->comment_ID;
		$right = parse_url( home_url(), PHP_URL_HOST );

		$id = sprintf( '<%s@%s>', $left, $right );

		/**
		 * Filter message IDs for posts
		 *
		 * @param string $id Message ID (conforming to RFC5322 Message-ID semantics)
		 * @param stdClass $post Post object
		 */
		return apply_filters( 'falcon.connector.wordpress.comment_message_id', $id, $comment );
	}

	/**
	 * Get the References for a comment
	 *
	 * @param stdClass $comment Comment object
	 * @return string Message ID
	 */
	protected function get_references_for_comment( $comment ) {
		$references = array();
		if ( ! empty( $comment->comment_parent ) ) {
			// Add parent's references
			$parent = get_comment( $comment->comment_parent );
			$references = array_merge( $references, $this->get_references_for_comment( $parent ) );

			// Add reference to the parent itself
			$references[] = $this->get_message_id_for_comment( $parent );
		}
		else {
			// Parent is a post
			$parent = get_post( $comment->comment_post_ID );
			$references[] = $this->get_message_id_for_post( $parent );
		}

		return $references;
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

	protected function get_comment_subscribers( $comment ) {
		// Grab subscribers for the post itself
		$subscribers = array();

		return $subscribers;
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

		if ( ! $reply->is_valid() ) {
			Falcon::notify_invalid( $user, $post->post_title );
			return new WP_Error( 'falcon.connector.wordpress.invalid_reply' );
		}

		$data = array(
			'comment_post_ID'      => $reply->post,
			'user_id'              => $user->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,

			'comment_content'  => $reply->parse_body(),
		);

		return wp_insert_comment( $data );
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
