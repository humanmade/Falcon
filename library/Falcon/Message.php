<?php

class Falcon_Message {
	/**
	 * Subject line
	 *
	 * @var string
	 */
	protected $subject = '';

	/**
	 * Author name
	 *
	 * @var string
	 */
	protected $author = '';

	/**
	 * Reply-To email address handler
	 *
	 * @var callable
	 */
	protected $reply_address_handler = '__return_null';

	/**
	 * Text content
	 *
	 * @var string
	 */
	protected $text = '';

	/**
	 * HTML content
	 *
	 * @var string
	 */
	protected $html = '';

	/**
	 * Handler options
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Get the subject line
	 *
	 * @return string Subject line (including prefixes)
	 */
	public function get_subject() {
		return $this->subject;
	}

	/**
	 * Set the subject line
	 *
	 * If the subject line needs to be prefixed with the site name or similar,
	 * this should be passed in here. The text will be sent as-is to
	 * the handler.
	 *
	 * @param string $subject Subject line
	 */
	public function set_subject( $subject ) {
		$this->subject = $subject;
	}

	/**
	 * Get the author name
	 *
	 * @return string Author name
	 */
	public function get_author() {
		return $this->author;
	}

	/**
	 * Set the author name
	 *
	 * This controls the "From" field in the email. The email address will be
	 * set automatically by the handler based on the preference in the admin.
	 *
	 * @param string $author Author name
	 */
	public function set_author( $author ) {
		$this->author = $author;
	}

	/**
	 * Get the reply address
	 *
	 * This controls the "Reply-To" field in the email. Typically, this will
	 * contain hash data for the connector to work out what incoming emails are
	 * replying to, but this cannot be assumed.
	 *
	 * @return string|null Reply email address
	 */
	public function get_reply_address( WP_User $user ) {
		if ( ! is_callable( $this->reply_address_handler ) ) {
			return null;
		}

		return call_user_func( $this->reply_address_handler, $user, $this );
	}

	/**
	 * Set the reply address handler
	 *
	 * This controls the "Reply-To" field in the email. The callback should
	 * conform to the following interface:
	 *
	 *     /**
	 *      * Get reply address
	 *      *
	 *      * @param WP_User $user User to generate address for
	 *      * @param Falcon_Message $message Message being sent
	 *      * @return string Reply email address
	 *      * /
	 *     function my_handler( WP_User $user, Falcon_Message $message );
	 *
	 * @param callable $handler Handler function (passed WP_User instance and Falcon_Message handler)
	 */
	public function set_reply_address_handler( $handler ) {
		$this->reply_address_handler = $handler;
	}

	/**
	 * Get the text content of the email
	 *
	 * @return string Plain text content
	 */
	public function get_text() {
		return $this->text;
	}

	/**
	 * Set the text content of the email
	 *
	 * This should include any footer to be sent.
	 *
	 * @param stirng $text Text content
	 */
	public function set_text( $text ) {
		$this->text = $text;
	}

	/**
	 * Get the HTML content of the email
	 *
	 * @return string HTML content
	 */
	public function get_html() {
		return $this->html;
	}

	/**
	 * Set the HTML content of the email
	 *
	 * @param string $html HTML content
	 */
	public function set_html( $html ) {
		$this->html = $html;
	}

	/**
	 * Get the options for this message
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Set the options for this message
	 *
	 * @param array $options {
	 *     @param int $id Post ID to use for hash
	 *     @param string $author Author name (leave blank for no name)
	 * }
	 */
	public function set_options( $options ) {
		$this->options = $options;
	}
}
