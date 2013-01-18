<?php
/**
 * HTML to Text converter
 *
 * @package bbSubscriptions
 * @subpackage Parsing
 */

/**
 * HTML to Text converter
 *
 * @package bbSubscriptions
 * @subpackage Parsing
 */
class bbSubscriptions_Converter {
	/**
	 * HTML fragment
	 * @var string
	 */
	protected $html = '';

	/**
	 * Textual representation of the HTML
	 * @var string
	 */
	protected $text = '';

	/**
	 * DOM document
	 *
	 * This may be needed to export an element as HTML
	 * @var DOMDocument
	 */
	protected $document;

	/**
	 * List of URLs in the document
	 * @var array
	 */
	protected $links = array();

	/**
	 * Stack of list indexes
	 *
	 * -1 represents an unordered list, while all other integers represent the
	 * current index of the ordered list
	 *
	 * @var array
	 */
	protected $list_stack = array();

	/**
	 * Constructor
	 *
	 * @param string $html HTML fragment to convert
	 */
	public function __construct($html) {
		$this->html = $html;
	}

	/**
	 * Convert HTML into a textual representation
	 *
	 * @return string Text representation
	 */
	public function convert() {
		if (!empty($this->text))
			return $this->text;

		$html = $this->preprocess($this->html);
		$this->document = new DOMDocument();
		$this->document->loadHTML($html);


		// Remove the DOCTYPE
		// Seems to cause segfaulting if we don't do this
		if ($this->document->firstChild instanceof DOMDocumentType) {
			$this->document->removeChild($this->document->firstChild);
		}

		$this->text = $this->parse_children($this->document->getElementsByTagName('body')->item(0));
		$this->text = preg_replace("#\n{3,}#", "\n\n", $this->text);
		$this->text = $this->wrap($this->text, 80);

		$this->text .= $this->generate_links();

		return $this->text;
	}

	/**
	 * Preprocess HTML
	 *
	 * Sanitizes the HTML for use with DOMDocument, including giving it a
	 * DOCTYPE, which forces standards mode
	 *
	 * @param string $html HTML fragment
	 * @return string Processed HTML document
	 */
	protected function preprocess($html) {
		$content_type = 'application/html';

		$ret = '<!DOCTYPE html>';
		$ret .= '<html><head>';
		$ret .= '<meta http-equiv="Content-Type" content="' . $content_type . '; charset=utf-8" />';
		$ret .= '</head><body>' . str_replace("\r", '', $html) . '</body></html>';
		return $ret;
	}

	/**
	 * Parse a node's children
	 *
	 * @param DOMNode $list Node to parse
	 * @return string Text representation of the node's children
	 */
	protected function parse_children($list) {
		$text = '';
		foreach ($list->childNodes as $element) {
			$text .= $this->get_text($element);
		}
		return rtrim($text, "\n");
	}

	/**
	 * Convert a node to text
	 *
	 * Handles the various types of DOM nodes
	 *
	 * @param DOMNode $node Node to convert
	 * @return string Text representation
	 */
	protected function get_text($node) {
		switch (get_class($node)) {
			case 'DOMText':
				$text = $node->wholeText;
				$text = trim($text, "\t");
				return $text;
			case 'DOMElement':
				return $this->parse_element($node);
		}
	}

	/**
	 * Parse an element into text
	 *
	 * This is a huge glorified switch statement, which is the easiest way to
	 * handle giant numbers of elements like this. Some elements have their
	 * handlers split off into separate methods.
	 *
	 * @param DOMElement $element Element to parse
	 * @return string Text representation of the element
	 */
	protected function parse_element($element) {
		switch ($element->tagName) {
			// Outlining
			case 'section':
			case 'article':
			case 'aside':
			case 'div':
			case 'span':
				return $this->parse_children($element);

			// Block
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return "\n" . $this->heading($element) . "\n";
			case 'blockquote':
				return $this->blockquote($element) . "\n";
			case 'dt':
				return "\n\n" . $this->parse_children($element) . "\n";
			case 'dd':
				return '- ' . $this->parse_children($element) . "\n";
			case 'dl':
				return $this->parse_children($element) . "\n";
			case 'p':
				return $this->parse_children($element) . "\n";
			case 'pre':
				return "\n" . $this->indent($this->parse_children($element)) . "\n";
			case 'address':
				return "--\n" . $this->parse_children($element) . "\n--\n\n";

			// Lists
			case 'ul':
				array_push($this->list_stack, -1);
				$text = $this->indent($this->parse_children($element)) . "\n";
				array_pop($this->list_stack);
				return $text;
			case 'ol':
				array_push($this->list_stack, 0);
				$text = $this->indent($this->parse_children($element)) . "\n";
				array_pop($this->list_stack);
				return $text;
			case 'li':
				// Ordered list
				if (end($this->list_stack) >= 0) {
					$this->list_stack[ count($this->list_stack) - 1 ]++;
					return $this->list_stack[ count($this->list_stack) - 1 ] . '. '
						. trim($this->parse_children($element));
				}
				else {
					return '* '
						. trim($this->parse_children($element));
				}

			// Tables
			case 'table':
				$table = $this->table($element);
				return $table;
			case 'tbody':
			case 'thead':
			case 'tr':
			case 'td':
			case 'th':
				return $this->parse_children($element);


			// Inline
			case 'a':
				$number = count($this->links);
				$this->links[] = $element->getAttribute('href');
				return $this->parse_children($element) . ' [' . $number . ']';
			case 'acronym':
			case 'abbr':
				return $this->parse_children($element) . ' (' . $element->getAttribute('title') . ')';
			case 'cite':
				return '-- ' . $this->parse_children($element);
			case 'code':
			case 'kbd':
			case 'tt':
			case 'var':
				return '`' . $this->parse_children($element) . '`';
			case 'em':
			case 'i':
				return '*' . $this->parse_children($element) . '*';
			case 'strong':
			case 'b':
				return '**' . $this->parse_children($element) . '**';
			case 'u':
			case 'ins':
				return '_' . $this->parse_children($element) . '_';
			case 'q':
				return '"' . $this->parse_children($element) . '"';
			case 'sub':
				return '_' . $this->parse_children($element);
			case 'sup':
				return '^' . $this->parse_children($element);
			case 'del':
			case 'strike':
				return '~~' . $this->parse_children($element) . '~~';

			// Ignored inline tags
			case 'small':
			case 'big':
				return $this->parse_children($element);

			// Visual
			case 'br':
				return '';
			case 'hr':
				return '----';
		}
		return $this->document->saveHTML($element);
	}

	/**
	 * Parse heading elements into a string
	 *
	 * Handles H1-H6, with special handling for H1 and H2
	 *
	 * @param DOMElement $element Heading element
	 * @return string Text representation
	 */
	protected function heading($element) {
		$type = (int) substr($element->tagName, 1);

		switch ($type) {
			case 1:
				$text = trim($this->parse_children($element));
				$text .= "\n" . str_repeat("=", strlen($text));
				return $text;
			case 2:
				$text = trim($this->parse_children($element));
				$text .= "\n" . str_repeat("-", strlen($text));
				return $text;
			default:
				return str_repeat('#', $type) . ' ' . $this->parse_children($element);
		}
	}

	/**
	 * Parse blockquote elements into a string
	 *
	 * @param DOMElement $element Blockquote element
	 * @return string Text representation
	 */
	protected function blockquote($element) {
		$text = $this->parse_children($element);

		$text = "> " . str_replace("\n", "\n> ", $text);
		return $text;
	}

	/**
	 * Parse table elements into a string
	 *
	 * @todo Assess performance issues
	 *
	 * @param DOMElement $element Table element
	 * @return string Text representation
	 */
	protected function table($element) {
		$rows = array();
		foreach ($element->childNodes as $node) {
			if ($node instanceof DOMText || $node->tagName === 'tfoot')
				continue;

			if ($node->tagName === 'thead' || $node->tagName === 'tbody')
				$rows = $rows + $this->table_section($node);
			elseif ($node->tagName === 'tr')
				$rows[] = $this->table_row($node);
		}

		$cols[] = array();
		foreach ($rows as $row => $cells) {
			foreach ($cells as $col => $content) {
				$cols[$col][$row] = $content;
			}
		}

		$rows = array();
		foreach ($cols as $col => $cells) {
			$length = max(array_map('strlen', $cells));
			var_dump($length);

			foreach ($cells as $row => $content) {
				$rows[$row][$col] = str_pad($content, $length);
			}
		}

		$text = '';
		$row_length = 0;
		foreach ($rows as $num => $row) {
			$text .= '|';
			foreach ($row as $cell) {
				$text .= ' ' . $cell . ' |';
			}
			if ($num === 0) {
				$row_length = strlen($text) - 2;
				$text = '+' . str_repeat('-', $row_length) . "+\n"
					. $text . "\n"
					. '+' . str_repeat('-', $row_length) . '+';
			}
			$text .= "\n";
		}
		$text .= '+' . str_repeat('-', $row_length) . "+\n";
		#var_dump($text);

		return $text;
	}

	/**
	 * Parse a table section element
	 *
	 * @param DOMElement $element THEAD or TBODY element
	 * @return array Rows in the section
	 */
	protected function table_section($element) {
		$rows = array();
		foreach ($element->childNodes as $node) {
			if ($node instanceof DOMText)
				continue;

			if ($node->tagName !== 'tr')
				continue;

			$rows[] = $this->table_row($node);
		}
		return $rows;
	}

	/**
	 * Parse a table row element
	 *
	 * @param DOMElement $element TR element
	 * @return array Cells in the row
	 */
	protected function table_row($element) {
		$row = array();
		foreach ($element->childNodes as $node) {
			if ($node instanceof DOMText)
				continue;

			if ($node->tagName !== 'td' && $node->tagName !== 'th')
				continue;

			$row[] = $this->get_text($node);
		}
		return $row;
	}

	/**
	 * Indent a section of text
	 *
	 * @todo Make this not be shit
	 *
	 * @param string $text Text to indent
	 * @return string Indented text
	 */
	protected function indent($text) {
		return "\t" . str_replace("\n", "\n\t", $text);
	}

	/**
	 * Wrap a string to a certain number of characters
	 *
	 * Has special handling for some text elements (blockquotes and tables)
	 *
	 * @param string $str Text to wrap
	 * @return string Word-wrapped text
	 */
	protected function wrap($str) {
		$lines = explode("\n", $str);
		$text = '';
		foreach ($lines as $line) {
			if (strlen($line) > 78) {
				if ($line[0] === '>') {
					$wrapped = wordwrap($line, 76);
					$text .= str_replace("\n", "\n> ", $wrapped) . "\n";
				}
				elseif ($line[0] === '+' || $line[0] === '|') {
					$text .= $line . "\n";
				}
				else {
					$text .= wordwrap($line, 78) . "\n";
				}
			}
			else {
				$text .= $line . "\n";
			}
		}
		return $text;
	}

	protected function generate_links() {
		if (empty($this->links))
			return '';

		$text = "\n\n----\n\n";
		foreach ($this->links as $num => $url) {
			$text .= '[' . $num . ']: ' . $url . "\n";
		}
		return $text;
	}
}