<?php

use \EmailReplyParser\Parser\EmailParser;

class Falcon_Reply {
	public $from;
	public $subject;
	public $body;
	public $nonce;
	public $post;
	public $site;

	public function __construct() {
	}

	public function parse_body() {
		// Parse the body and remove signatures, and reformat
		$parts = array();
		$parser = new EmailParser();
		$email = $parser->parse($this->body);
		foreach ($email->getFragments() as $fragment) {
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
		return get_user_by( 'id', $this->user );
	}

	public function is_valid() {
		$user = $this->get_user();

		return $this->nonce === Falcon::get_hash($this->post, $user, $this->site);
	}

	public function insert() {
		if ( is_multisite() ) {
			switch_to_blog( $this->site );
		}

		$result = apply_filters( 'falcon.reply.insert', null, $this );

		if ( is_multisite() ) {
			restore_current_blog();
		}

		return $result;
	}

	public static function parse_to($address) {
		$template = Falcon::get_option('bbsub_replyto');

		// No plus address in saved, parse via splitting
		$has_match = preg_match( '/\+(\w+)-(\d+)-(\d+)-(\w+)\@.*/i', $address, $matches );
		if ( ! $has_match ) {
			throw new Exception(__('Reply-to not formatted correctly', 'bbsub'));
		}
		return array( $matches[1], $matches[2], $matches[3], $matches[4] );
	}
}
