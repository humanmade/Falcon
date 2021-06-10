<?php

class Falcon_Connector_WordPress extends Falcon_Connector {
	const SENT_META_KEY = 'falcon_sent';
	const MESSAGE_ID_KEY = 'falcon_message_ids';

	/**
	 * Sending handler
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

		$this->add_notify_action( 'publish_post', array( $this, 'notify_on_publish' ), 10, 2 );

		// If notifications on private posts are explicitly allowed via filter.
		if ( apply_filters( 'falcon.connector.wordpress.notify_on_private', false ) ) {
			/**
			 * There is no dedicated action when a private post is published.
			 * Private posts are automatically published once you change the
			 * visibility to private, so we need to hook into all the
			 * `{$old_status}_to_{$new_status}` actions.
			*/
			$this->add_notify_action( 'auto-draft_to_private', array( $this, 'notify_on_private' ), 10, 1 );
			$this->add_notify_action( 'draft_to_private', array( $this, 'notify_on_private' ), 10, 1 );
			$this->add_notify_action( 'pending_to_private', array( $this, 'notify_on_private' ), 10, 1 );
		}

		$this->add_notify_action( 'wp_insert_comment', array( $this, 'notify_on_reply' ), 10, 2 );
		$this->add_notify_action( 'comment_approve_comment', array( $this, 'notify_on_reply' ), 10, 2 );

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
		return 'WordPress';
	}

	/**
	 * Get a machine-readable ID for the handler.
	 *
	 * This is used for preference handling.
	 *
	 * @return string
	 */
	protected function get_id() {
		return 'wordpress';
	}

	/**
	 * Check if the given post type is allowed to be replied to.
	 *
	 * @param string $type Post type to check.
	 * @return bool True for allowed types, false otherwise.
	 */
	public static function is_allowed_type( $type ) {
		// Only notify for allowed types
		$allowed_types = apply_filters( 'falcon.connector.wordpress.post_types', array( 'post' ) );
		return in_array( $type, $allowed_types );
	}

	/**
	 * Check if the given comment type is allowed to be replied to.
	 *
	 * @param string $type Comment type to check.
	 * @return bool True for allowed types, false otherwise.
	 */
	public static function is_allowed_comment_type( $type ) {
		// Only notify for allowed types
		$allowed_types = apply_filters( 'falcon.connector.wordpress.comment_types', array( '', 'comment' ), true );
		return in_array( $type, $allowed_types );
	}

	/**
	 * Notify users on post publish.
	 *
	 * @param int $id ID of the post being published.
	 * @param WP_Post $post Post object for the post being published.
	 */
	public function notify_on_publish( $id = 0, WP_Post $post = null ) {
		if ( empty( $this->handler ) || ! Falcon::is_enabled_for_site() ) {
			return;
		}

		// Double-check status
		if ( get_post_status( $id ) !== 'publish' ) {
			return;
		}

		// Only notify for allowed types
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
		$message->set_html( $this->get_post_content_as_html( $post ) );

		$subject = apply_filters( 'bbsub_topic_email_subject', '[' . get_option( 'blogname' ) . '] ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES ), $id );
		$message->set_subject( $subject );

		$message->set_author( get_the_author_meta( 'display_name', $post->post_author ) );

		$options = array();
		if ( $this->handler->supports_message_ids() ) {
			$options['message-id'] = $this->get_message_id_for_post( $post );
		}
		$message->set_options( $options );

		$message->set_reply_address_handler( function ( WP_User $user, Falcon_Message $message ) use ( $post ) {
			return Falcon::get_reply_address( 'post_' . $post->ID, $user );
		} );

		$responses = $this->handler->send_mail( $recipients, $message );
		if ( ! $this->handler->supports_message_ids() && ! empty( $responses ) ) {
			update_post_meta( $id, self::MESSAGE_ID_KEY, $responses );
		}

		// Stop any future double-sends
		update_post_meta( $id, static::SENT_META_KEY, true );
	}

	/**
	 * Notify users when a private post is published.
	 *
	 * @param int $id ID of the private post being published.
	 * @param WP_Post $post Post object for the private post being published.
	 */
	public function notify_on_private( WP_Post $post ) {
		if ( empty( $this->handler ) || ! Falcon::is_enabled_for_site() ) {
			return;
		}

		$id = $post->ID;

		// Double-check status
		if ( get_post_status( $id ) !== 'private' ) {
			return;
		}

		// Only notify for allowed types
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
		$should_notify = apply_filters( 'falcon.connector.wordpress.should_notify_private', ! $has_sent, $post );

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
		$message->set_html( $this->get_post_content_as_html( $post ) );

		$subject = apply_filters( 'bbsub_topic_email_subject', '[' . get_option( 'blogname' ) . '] ' . html_entity_decode( get_the_title( $id ), ENT_QUOTES ), $id );
		$message->set_subject( $subject );

		$message->set_author( get_the_author_meta( 'display_name', $post->post_author ) );

		$options = array();
		if ( $this->handler->supports_message_ids() ) {
			$options['message-id'] = $this->get_message_id_for_post( $post );
		}
		$message->set_options( $options );

		$message->set_reply_address_handler( function ( WP_User $user, Falcon_Message $message ) use ( $post ) {
			return Falcon::get_reply_address( 'post_' . $post->ID, $user );
		} );

		$responses = $this->handler->send_mail( $recipients, $message );
		if ( ! $this->handler->supports_message_ids() && ! empty( $responses ) ) {
			update_post_meta( $id, self::MESSAGE_ID_KEY, $responses );
		}

		// Stop any future double-sends
		update_post_meta( $id, static::SENT_META_KEY, true );
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
		$text .= "\n" . $url;

		return apply_filters( 'falcon.connector.wordpress.text_footer', $text, $url );
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
	 * Get text-formatted message for a post.
	 *
	 * @param WP_Post $post Post to notify for.
	 * @return string Plain text message.
	 */
	protected function get_post_content_as_text( WP_Post $post ) {
		$content = apply_filters( 'the_content', $post->post_content );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = $content . "\n\n" . $this->get_text_footer( get_permalink( $post->ID ) );

		/**
		 * Filter the email content
		 *
		 * Use this to change document formatting, etc
		 *
		 * @param string $text Text content
		 * @param WP_Post $post Post the content is generated from
		 */
		return apply_filters( 'falcon.connector.wordpress.post_content_text', $text, $post );
	}

	/**
	 * Get HTML-formatted message for a post.
	 *
	 * @param WP_Post $post Post to notify for.
	 * @return string HTML message.
	 */
	protected function get_post_content_as_html( WP_Post $post ) {
		$content = apply_filters( 'the_content', $post->post_content );

		$text = $content . "\n\n" . $this->get_html_footer( get_permalink( $post->ID ) );

		/**
		 * Filter the email content
		 *
		 * Use this to add tracking codes, metadata, etc
		 *
		 * @param string $text HTML content
		 * @param WP_Post $post Post the content is generated from
		 */
		return apply_filters( 'falcon.connector.wordpress.post_content_html', $text, $post );
	}

	/**
	 * Notify users on comment approval.
	 *
	 * @param int $id ID of the comment being approved.
	 * @param WP_Comment $comment Comment object for the comment being approved.
	 * @return boolean True if notifications were sent, false otherwise.
	 */
	public function notify_on_reply( $id = 0, WP_Comment $comment = null ) {
		if ( empty( $this->handler ) || ! Falcon::is_enabled_for_site() ) {
			return false;
		}

		if ( wp_get_comment_status( $comment ) !== 'approved' ) {
			return false;
		}
		// Is the post published?
		$post = get_post( $comment->comment_post_ID );
		$is_allowed_status = get_post_status( $post ) === 'publish'
			||
			( apply_filters( 'falcon.connector.wordpress.notify_on_private', false ) && get_post_status( $post ) === 'private' );

		if ( ! $is_allowed_status ) {
			return false;
		}

		if ( ! $this->is_allowed_comment_type( $comment->comment_type ) ) {
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
		$send_to_author = Falcon::get_option( 'bbsub_send_to_author', false );

		if ( ! $send_to_author && ! empty( $comment->user_id ) ) {
			$author = (int) $comment->user_id;

			$users = array_filter( $users, function ( $user ) use ( $author ) {
				return $user->ID !== $author;
			} );
		}

		// Sanitize the HTML into text
		$message->set_text( $this->get_comment_content_as_text( $comment ) );
		$message->set_html( $this->get_comment_content_as_html( $comment ) );

		$subject = apply_filters( 'bbsub_email_subject', 'Re: [' . get_option( 'blogname' ) . '] ' . html_entity_decode( get_the_title( $post ), ENT_QUOTES ), $id, $post->ID );
		$message->set_subject( $subject );

		$message->set_reply_address_handler( function ( WP_User $user, Falcon_Message $message ) use ( $comment ) {
			return Falcon::get_reply_address( 'comment_' . $comment->comment_ID, $user );
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
			$message_ids = get_post_meta( $id, self::MESSAGE_ID_KEY, true );
			$options['in-reply-to'] = $message_ids;
		}

		$message->set_options( $options );

		$this->handler->send_mail( $users, $message );

		return true;
	}

	/**
	 * Get text-formatted message for a comment.
	 *
	 * @param WP_Comment $comment Comment to notify for.
	 * @return string Plain text message.
	 */
	protected function get_comment_content_as_text( WP_Comment $comment ) {
		$content = apply_filters( 'comment_text', get_comment_text( $comment ) );

		// Sanitize the HTML into text
		$content = apply_filters( 'bbsub_html_to_text', $content );

		// Build email
		$text = $content . "\n\n" . $this->get_text_footer( get_comment_link( $comment ) );

		/**
		 * Filter the email content
		 *
		 * Use this to change document formatting, etc
		 *
		 * @param string $text Text content
		 * @param WP_Comment $comment Comment the content is generated from
		 */
		return apply_filters( 'falcon.connector.wordpress.comment_content_text', $text, $comment );
	}

	/**
	 * Get text-formatted message for a comment.
	 *
	 * @param WP_Comment $comment Comment to notify for.
	 * @return string Plain text message.
	 */
	protected function get_comment_content_as_html( WP_Comment $comment ) {
		$content = apply_filters( 'comment_text', get_comment_text( $comment ) );

		$text = $content . "\n\n" . $this->get_html_footer( get_comment_link( $comment ) );

		/**
		 * Filter the email content
		 *
		 * Use this to add tracking codes, metadata, etc
		 *
		 * @param string $text HTML content
		 * @param WP_Comment $comment Comment the content is generated from
		 */
		return apply_filters( 'falcon.connector.wordpress.comment_content_html', $text, $comment );
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
	 * @param WP_Comment $comment Comment object
	 * @return string Message ID
	 */
	protected function get_message_id_for_comment( WP_Comment $comment ) {
		$post = get_post( $comment->comment_post_ID );
		$type = $comment->comment_type;
		if ( empty( $type ) ) {
			$type = 'comment';
		}

		$left = 'falcon/' . $post->post_type . '/' . $post->ID . '/' . $type . '/' . $comment->comment_ID;
		$right = parse_url( home_url(), PHP_URL_HOST );

		$id = sprintf( '<%s@%s>', $left, $right );

		/**
		 * Filter message IDs for comments
		 *
		 * @param string $id Message ID (conforming to RFC5322 Message-ID semantics)
		 * @param WP_Comment $comment Comment object
		 */
		return apply_filters( 'falcon.connector.wordpress.comment_message_id', $id, $comment );
	}

	/**
	 * Get the References for a comment
	 *
	 * @param WP_Comment $comment Comment object
	 * @return string[] Message IDs
	 */
	protected function get_references_for_comment( WP_Comment $comment ) {
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

	/**
	 * Get all subscribers for post notifications
	 *
	 * @param WP_Post $post Post being checked
	 * @return WP_User[]
	 */
	protected function get_post_subscribers( WP_Post $post ) {
		$recipients = array();

		// Find everyone who has a matching preference, or who is using the
		// default (if it's on)
		$query = array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key' => $this->key_for_setting( 'notifications.post' ),
					'value' => 'all'
				),
			),
		);

		$default = $this->get_default_settings();
		if ( $default['post'] === 'all' ) {
			$query['meta_query'][] = array(
				'key' => $this->key_for_setting( 'notifications.post' ),
				'compare' => 'NOT EXISTS',
			);
		}

		$users = get_users( $query );
		if ( empty( $users ) ) {
			return array();
		}

		// Filter out any users without read access to the post
		$recipients = array_filter( $users, function ( WP_User $user ) use ( $post ) {
			return user_can( $user, 'read_post', $post->ID );
		} );

		return $recipients;
	}

	/**
	 * Get all subscribers for comment notifications
	 *
	 * @param WP_Comment $comment Comment being checked
	 * @return WP_User[]
	 */
	public function get_comment_subscribers( WP_Comment $comment ) {
		// Find everyone who has a matching preference, or who is using the
		// default (if it's on)
		$query = array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key' => $this->key_for_setting( 'notifications.comment' ),
					'value' => 'all'
				),
			),
		);

		$default = $this->get_default_settings();
		if ( $default['post'] === 'all' ) {
			$query['meta_query'][] = array(
				'key' => $this->key_for_setting( 'notifications.comment' ),
				'compare' => 'NOT EXISTS',
			);
		}

		$users = get_users( $query );

		// Also get the original post author, they should always be notified.
		$original_post = get_post( $comment->comment_post_ID );
		if ( $original_post ) {
			$author_id = $original_post->post_author;
			$author_data = $author_id ? get_user_by( 'id', $author_id ) : null;
		}
		$author_array = $author_data ? [ $author_data ] : [];

		// Also grab everyone if they're in the thread and subscribed to
		// same-thread comments
		$sibling_authors = $this->get_thread_subscribers( $comment );
		$users = array_merge( $users, $sibling_authors, $author_array );

		// Trim to unique authors using IDs as key
		$subscribers = $this->filter_unique_users( $users );

		// Ensure users have access.
		$subscribers = array_filter( $subscribers, function ( WP_User $user ) use ( $comment ) {
			return user_can( $user, 'read_post', $comment->comment_post_ID );
		} );

		return $subscribers;
	}

	/**
	 * Get subscribers for the thread that a comment is in
	 *
	 * Gets subscribers to the thread (i.e. parent comment authors who are
	 * subscribed) as well as subscribers to all threads (i.e. comment authors
	 * who are subscribed to all comments on the post)
	 *
	 * @param WP_Comment $comment Comment being checked
	 * @return array
	 */
	protected function get_thread_subscribers( WP_Comment $comment ) {
		$sibling_comments = get_comments( array(
			'post_id'         => $comment->comment_post_ID,
			'comment__not_in' => $comment->comment_ID,
			'type'            => 'comment'
		) );
		if ( empty( $sibling_comments ) ) {
			return array();
		}

		$users = array();
		$indexed = array();
		foreach ( $sibling_comments as $sibling ) {
			// Re-index by ID for later usage
			$indexed[ $sibling->comment_ID ] = $sibling;

			// Grab just comments with author IDs
			if ( empty( $sibling->user_id ) ) {
				continue;
			}

			// Skip duplicate parsing
			if ( isset( $users[ $sibling->user_id ] ) ) {
				continue;
			}

			$pref = get_user_meta( $sibling->user_id, $this->key_for_setting( 'notifications.comment' ), true );
			$users[ $sibling->user_id ] = ( $pref === 'participant' );
		}

		// Now, find users in the thread
		$sibling = $comment;
		while ( ! empty( $sibling->comment_parent ) ) {
			$parent_id = $sibling->comment_parent;
			if ( ! isset( $indexed[ $parent_id ] ) ) {
				break;
			}

			$sibling = $indexed[ $parent_id ];
			if ( ! isset( $sibling->user_id ) ) {
				continue;
			}

			$pref = get_user_meta( $sibling->user_id, $this->key_for_setting( 'notifications.comment' ), true );
			if ( $pref ) {
				$users[ $sibling->user_id ] = true;
			}
		}

		$subscribers = array();
		foreach ( $users as $user => $subscribed ) {
			if ( ! $subscribed ) {
				continue;
			}

			$subscribers[] = get_userdata( $user );
		}
		return $subscribers;
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

		$comment_parent = null;
		list( $type, $parent_id ) = explode( '_', $reply->post, 2 );
		switch ( $type ) {
			case 'post':
				$post = get_post( $parent_id );
				break;

			case 'comment':
				$comment_parent = get_comment( $parent_id );
				if ( empty( $comment_parent ) ) {
					return $value;
				}

				$post = get_post( $comment_parent->comment_post_ID );
				break;

			default:
				return $value;
		}

		if ( ! $this->is_allowed_type( $post->post_type ) ) {
			return $value;
		}

		$user = $reply->get_user();

		if ( ! $reply->is_valid() ) {
			Falcon::notify_invalid( $user, $post->post_title );
			return new WP_Error( 'falcon.connector.wordpress.invalid_reply' );
		}

		$data = array(
			'comment_post_ID'      => $post->ID,
			'user_id'              => $user->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,

			'comment_content'  => $reply->parse_body(),
		);
		if ( ! empty( $comment_parent ) ) {
			$data['comment_parent'] = $comment_parent->comment_ID;
		}

		return wp_insert_comment( $data );
	}

	/**
	 * Get available settings for notifications
	 *
	 * @return array
	 */
	public function get_available_settings() {
		return array(
			'post' => array(
				'all' => __( 'All new posts', 'falcon' ),
				''    => __( 'No notifications', 'falcon' ),
			),

			'comment' => array(
				'all'         => __( 'All new comments', 'falcon' ),
				'participant' => __( "New comments on posts I've commented on", 'falcon' ),
				'replies'     => __( 'Replies to my comments', 'falcon' ),
				''            => __( 'No notifications', 'falcon' )
			),
		);
	}

	public function get_available_settings_short() {
		return array(
			'post' => array(
				'all' => __( 'All', 'falcon' ),
				''    => __( 'None', 'falcon' ),
			),

			'comment' => array(
				'all'         => __( 'All', 'falcon' ),
				'participant' => __( "Participant", 'falcon' ),
				'replies'     => __( 'Replies', 'falcon' ),
				''            => __( 'None', 'falcon' )
			),
		);
	}

	protected function get_settings_fields() {
		return [
			'post' => [
				'default' => 'all',
				'label' => __( 'Posts', 'falcon' ),
			],
			'comment' => [
				'default' => 'all',
				'label' => __( 'Comments', 'falcon' ),
			],
		];
	}
}
