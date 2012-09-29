<?php

class bbSubscriptions_Reply {
	public $from;
	public $subject;
	public $body;
	public $nonce;
	public $topic;

	public function __construct() {
	}

	public function insert() {
		$user = get_user_by_email($this->from);

		if ($this->nonce !== bbSubscriptions::get_hash($this->topic, $user)) {
			return false;
		}

		$email = new \EmailReplyParser\Email();
		$parsed = &$email->read($this->body);

		$content = '';
		foreach ($parsed as &$fragment) {
			if ($fragment->isSignature()) {
				continue;
			}

			$content .= $fragment->getContent();
		}

		$reply = array(
			'post_parent'   => $this->topic, // topic ID
			'post_author'   => $user->ID,
			'post_content'  => $content,
			'post_title'    => $this->subject,
		);
		$meta = array(
			'author_ip' => '127.0.0.1', // we could parse Received, but it's a pain, and inaccurate
			'forum_id' => bbp_get_topic_forum_id($this->topic),
			'topic_id' => $this->topic
		);

		return bbp_insert_reply($reply, $meta);
	}

	public static function parse_to($address) {
		preg_match('#(?P<user>[^\+@]+)(?:\+(?P<plus>[^@]+))?@(?P<domain>.+)#i', $address, $matches);

		if (empty($matches['plus'])) {
			throw new Exception('Plus part empty');
		}

		// 'plus' => sprintf( 'bbsub-%s-%s', $topic, $user_nonce )
		$plus = explode('-', $matches['plus']);
		if (count($plus) < 3) {
			throw new Exception('Plus part not formatted correctly');
		}
		list($prefix, $topic, $nonce) = $plus;
		return compact('prefix', 'topic', 'nonce');

		// maybe we'll do something with $prefix later
		// such as multiple blogs per address or something
	}
}
