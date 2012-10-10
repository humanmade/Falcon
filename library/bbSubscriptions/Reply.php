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
		$template = get_option('bbsub_replyto');

		// Hack to make ungreedy
		$template = str_replace('%2$s', '%2$[a-zA-Z0-9]', $template);
		$result = sscanf($address, $template);

		if (empty($result) || empty($result[0]) || empty($result[1])) {
			throw new Exception('Reply-to not formatted correctly');
		}
		return $result;
	}
}
