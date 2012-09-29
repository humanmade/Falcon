<?php

use \EmailReplyParser\EmailReplyParser;

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

		// Parse the body and remove signatures, and reformat
		$parts = array();
		$fragments = EmailReplyParser::read($this->body);
		foreach ($fragments as $fragment) {
			// We don't care about hidden parts (signatures, eg)
			if ($fragment->isHidden()) {
				continue;
			}
			elseif ($fragment->isQuoted()) {
				// Remove leading quote symbols
				$quoted = preg_replace('/^> */m', '', $fragment->getContent());

				// Reparse to ensure that we strip signatures from here too
				$subfragments = EmailReplyParser::read($quoted);
				$subparts = array();
				foreach ($subfragments as $subfrag) {
					if ($subfrag->isHidden()) {
						continue;
					}

					$subparts[] = $subfrag->getContent();
				}

				$parts[] = '<blockquote>' . implode("\n", $subparts) . '</blockquote>';
			}
			else {
				$parts[] = $fragment->getContent();
			}
		}
		$content = implode("\n", $parts);

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

		// 'plus' => sprintf( '%s-%s', $topic, $user_nonce )
		$plus = explode('-', $matches['plus']);
		if (count($plus) < 2) {
			throw new Exception('Plus part not formatted correctly');
		}
		return $plus;
	}
}
