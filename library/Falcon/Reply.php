<?php

use \EmailReplyParser\EmailReplyParser;

class Falcon_Reply {
	public $from;
	public $subject;
	public $body;
	public $nonce;
	public $post;

	public function __construct() {
	}

	public function parse_body() {
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

		return $content;
	}

	public function get_user() {
		return get_user_by( 'email', $this->from );
	}

	public function is_valid() {
		$user = $this->get_user();

		return $this->nonce === Falcon::get_hash($this->post, $user);
	}

	public function insert() {
		return apply_filters( 'falcon.reply.insert', null, $this );
	}

	public static function parse_to($address) {
		$template = get_option('bbsub_replyto');

		// Hack to make ungreedy
		$template = str_replace('%2$s', '%2$[a-zA-Z0-9]', $template);
		$result = sscanf($address, $template);

		if (empty($result) || empty($result[0]) || empty($result[1])) {
			throw new Exception(__('Reply-to not formatted correctly', 'bbsub'));
		}
		return $result;
	}
}
