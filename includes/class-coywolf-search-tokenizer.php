<?php
/**
 * Text cleaning and tokenization.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one pipeline that turns text into terms.
 *
 * Index time and query time both run through this class. If they ever diverge
 * — different casing, a different split rule, a different length clamp — a
 * query silently stops matching what the indexer wrote, so there is
 * deliberately no second implementation anywhere in the plugin.
 */
final class Coywolf_Search_Tokenizer {

	/**
	 * Terms are stored in a varchar(64); truncation happens here, once, so
	 * index-time and query-time terms stay byte-identical.
	 */
	const MAX_TERM_LENGTH = 64;

	/**
	 * Minimum token length.
	 *
	 * @var int
	 */
	private $min_length;

	/**
	 * Stopwords as a lookup map.
	 *
	 * @var array<string,bool>
	 */
	private $stopwords;

	/**
	 * Whether to stem.
	 *
	 * @var bool
	 */
	private $stemming;

	/**
	 * Build a tokenizer from explicit rules.
	 *
	 * @param int                $min_length Minimum token length.
	 * @param array<string,bool> $stopwords  Stopword lookup map.
	 * @param bool               $stemming   Whether to stem.
	 */
	public function __construct( $min_length, array $stopwords, $stemming ) {
		$this->min_length = max( 1, (int) $min_length );
		$this->stopwords  = $stopwords;
		$this->stemming   = (bool) $stemming;
	}

	/**
	 * Build a tokenizer configured from the current settings.
	 *
	 * @return Coywolf_Search_Tokenizer
	 */
	public static function from_settings() {
		return new self(
			(int) Coywolf_Search_Settings::get( 'min_token_length' ),
			Coywolf_Search_Settings::stopwords(),
			(bool) Coywolf_Search_Settings::get( 'stemming' )
		);
	}

	/**
	 * Reduce post content to a plain-text projection.
	 *
	 * Block markup is stripped as markup — `do_blocks()` is deliberately not
	 * called, because rendering dynamic blocks at index time is slow and can
	 * fire side effects that have no business running during a reindex.
	 *
	 * @param string $html Raw post content.
	 * @return string
	 */
	public function clean( $html ) {
		$text = (string) $html;

		if ( '' === trim( $text ) ) {
			return '';
		}

		// Block delimiters and their JSON attributes carry no prose.
		$text = preg_replace( '#<!--\s*/?wp:.*?-->#s', ' ', $text );
		// Other HTML comments likewise.
		$text = preg_replace( '/<!--.*?-->/s', ' ', (string) $text );
		// Script and style bodies are not content.
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $text );

		$text = strip_shortcodes( (string) $text );

		// Replace tags with a space rather than deleting them, so markup that
		// separates words ("<p>one</p><p>two</p>") doesn't glue them together.
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = wp_strip_all_tags( (string) $text );

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Tokenize a plain-text string into term frequencies.
	 *
	 * @param string $text Plain text (already cleaned, if it came from HTML).
	 * @return array{terms:array<string,int>,length:int} Term => frequency, plus the token count.
	 */
	public function tokenize( $text ) {
		$tokens = $this->split( $text );

		$terms  = array();
		$length = 0;

		foreach ( $tokens as $token ) {
			$term = $this->normalize( $token );
			if ( null === $term ) {
				continue;
			}
			++$length;
			if ( isset( $terms[ $term ] ) ) {
				++$terms[ $term ];
			} else {
				$terms[ $term ] = 1;
			}
		}

		return array(
			'terms'  => $terms,
			'length' => $length,
		);
	}

	/**
	 * Tokenize a search query into an ordered list of distinct terms.
	 *
	 * Order matters: the last term is the one prefix expansion applies to, so
	 * typing feels responsive mid-word.
	 *
	 * @param string $text Raw query string.
	 * @return string[]
	 */
	public function tokenize_query( $text ) {
		$out = array();
		foreach ( $this->split( $text ) as $token ) {
			$term = $this->normalize( $token );
			if ( null !== $term && ! in_array( $term, $out, true ) ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Split text on Unicode whitespace, punctuation, and control characters.
	 *
	 * The same rule the bundled client-side index uses, so a term that matches
	 * in the browser also matches on the server.
	 *
	 * @param string $text Input text.
	 * @return string[]
	 */
	private function split( $text ) {
		$text = remove_accents( (string) $text );
		$text = Coywolf_Search_Settings::lowercase( $text );

		$parts = preg_split( '/[\p{Z}\p{P}\p{C}\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Apply length, stopword, stemming, and clamping rules to one token.
	 *
	 * @param string $token Raw token.
	 * @return string|null The term, or null if the token is dropped.
	 */
	private function normalize( $token ) {
		if ( '' === $token ) {
			return null;
		}

		if ( $this->char_length( $token ) < $this->min_length ) {
			return null;
		}

		if ( isset( $this->stopwords[ $token ] ) ) {
			return null;
		}

		if ( $this->stemming ) {
			$token = Coywolf_Search_Stemmer::stem( $token );
			if ( '' === $token || $this->char_length( $token ) < $this->min_length ) {
				return null;
			}
		}

		return $this->clamp( $token );
	}

	/**
	 * Multibyte-safe character count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function char_length( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
	}

	/**
	 * Truncate a term to the stored column width.
	 *
	 * Truncating by bytes could split a multibyte character, so this clamps by
	 * characters first and then, if the result is still over the byte width of
	 * the column, by bytes on a character boundary.
	 *
	 * @param string $term Term.
	 * @return string
	 */
	private function clamp( $term ) {
		if ( function_exists( 'mb_substr' ) ) {
			$term = mb_substr( $term, 0, self::MAX_TERM_LENGTH, 'UTF-8' );
			while ( strlen( $term ) > self::MAX_TERM_LENGTH && '' !== $term ) {
				$term = mb_substr( $term, 0, mb_strlen( $term, 'UTF-8' ) - 1, 'UTF-8' );
			}
			return $term;
		}
		return substr( $term, 0, self::MAX_TERM_LENGTH );
	}
}
