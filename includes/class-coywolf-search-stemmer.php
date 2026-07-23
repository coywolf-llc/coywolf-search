<?php
/**
 * Porter stemmer.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Porter stemming algorithm (Porter, 1980) in pure PHP.
 *
 * Implemented here rather than pulled from Composer so the shipped plugin has
 * no runtime dependencies. Operates on lowercase ASCII words; the tokenizer
 * has already lowercased and transliterated by the time this runs.
 */
final class Coywolf_Search_Stemmer {

	/**
	 * Memo so repeated terms in one request are stemmed once.
	 *
	 * @var array<string,string>
	 */
	private static $memo = array();

	/**
	 * Reduce a word to its stem.
	 *
	 * @param string $word Lowercase word.
	 * @return string
	 */
	public static function stem( $word ) {
		if ( isset( self::$memo[ $word ] ) ) {
			return self::$memo[ $word ];
		}

		$original = $word;

		if ( strlen( $word ) < 3 || ! preg_match( '/^[a-z]+$/', $word ) ) {
			self::$memo[ $original ] = $word;
			return $word;
		}

		$word = self::step1ab( $word );
		$word = self::step1c( $word );
		$word = self::step2( $word );
		$word = self::step3( $word );
		$word = self::step4( $word );
		$word = self::step5( $word );

		// Keep the memo from growing without bound on pathological content.
		if ( count( self::$memo ) > 20000 ) {
			self::$memo = array();
		}
		self::$memo[ $original ] = $word;

		return $word;
	}

	/**
	 * Plurals and -ed / -ing.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step1ab( $word ) {
		if ( 's' === substr( $word, -1 ) ) {
			if ( self::ends( $word, 'sses' ) || self::ends( $word, 'ies' ) ) {
				$word = substr( $word, 0, -2 );
			} elseif ( 'ss' !== substr( $word, -2 ) ) {
				$word = substr( $word, 0, -1 );
			}
		}

		if ( self::ends( $word, 'eed' ) ) {
			if ( self::measure( substr( $word, 0, -3 ) ) > 0 ) {
				$word = substr( $word, 0, -1 );
			}
			return $word;
		}

		$stem = null;
		if ( self::ends( $word, 'ed' ) ) {
			$candidate = substr( $word, 0, -2 );
			if ( self::has_vowel( $candidate ) ) {
				$stem = $candidate;
			}
		} elseif ( self::ends( $word, 'ing' ) ) {
			$candidate = substr( $word, 0, -3 );
			if ( self::has_vowel( $candidate ) ) {
				$stem = $candidate;
			}
		}

		if ( null === $stem ) {
			return $word;
		}

		if ( self::ends( $stem, 'at' ) || self::ends( $stem, 'bl' ) || self::ends( $stem, 'iz' ) ) {
			return $stem . 'e';
		}
		if ( self::double_consonant( $stem ) && ! preg_match( '/[lsz]$/', $stem ) ) {
			return substr( $stem, 0, -1 );
		}
		if ( 1 === self::measure( $stem ) && self::cvc( $stem ) ) {
			return $stem . 'e';
		}
		return $stem;
	}

	/**
	 * Terminal y to i.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step1c( $word ) {
		if ( self::ends( $word, 'y' ) && self::has_vowel( substr( $word, 0, -1 ) ) ) {
			return substr( $word, 0, -1 ) . 'i';
		}
		return $word;
	}

	/**
	 * Double suffixes to single ones.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step2( $word ) {
		$map = array(
			'ational' => 'ate',
			'tional'  => 'tion',
			'enci'    => 'ence',
			'anci'    => 'ance',
			'izer'    => 'ize',
			'bli'     => 'ble',
			'alli'    => 'al',
			'entli'   => 'ent',
			'eli'     => 'e',
			'ousli'   => 'ous',
			'ization' => 'ize',
			'ation'   => 'ate',
			'ator'    => 'ate',
			'alism'   => 'al',
			'iveness' => 'ive',
			'fulness' => 'ful',
			'ousness' => 'ous',
			'aliti'   => 'al',
			'iviti'   => 'ive',
			'biliti'  => 'ble',
			'logi'    => 'log',
		);
		return self::replace_suffix( $word, $map, 0 );
	}

	/**
	 * -ic-, -full, -ness etc.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step3( $word ) {
		$map = array(
			'icate' => 'ic',
			'ative' => '',
			'alize' => 'al',
			'iciti' => 'ic',
			'ical'  => 'ic',
			'ful'   => '',
			'ness'  => '',
		);
		return self::replace_suffix( $word, $map, 0 );
	}

	/**
	 * -ant, -ence etc., in context <c>vcvc<v>.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step4( $word ) {
		$suffixes = array(
			'al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 'ement',
			'ment', 'ent', 'ou', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize',
		);

		foreach ( $suffixes as $suffix ) {
			if ( ! self::ends( $word, $suffix ) ) {
				continue;
			}
			$stem = substr( $word, 0, -strlen( $suffix ) );
			if ( self::measure( $stem ) > 1 ) {
				return $stem;
			}
			return $word;
		}

		// "ion" only survives after s or t.
		if ( self::ends( $word, 'ion' ) ) {
			$stem = substr( $word, 0, -3 );
			if ( self::measure( $stem ) > 1 && preg_match( '/[st]$/', $stem ) ) {
				return $stem;
			}
		}

		return $word;
	}

	/**
	 * Tidy up terminal e and doubled l.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function step5( $word ) {
		if ( self::ends( $word, 'e' ) ) {
			$stem    = substr( $word, 0, -1 );
			$measure = self::measure( $stem );
			if ( $measure > 1 || ( 1 === $measure && ! self::cvc( $stem ) ) ) {
				$word = $stem;
			}
		}

		if ( self::ends( $word, 'l' ) && self::double_consonant( $word ) && self::measure( $word ) > 1 ) {
			$word = substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Replace the first matching suffix when the remaining stem is long enough.
	 *
	 * @param string                $word          Word.
	 * @param array<string,string>  $map           Suffix => replacement.
	 * @param int                   $min_measure   Minimum measure of the stem.
	 * @return string
	 */
	private static function replace_suffix( $word, $map, $min_measure ) {
		foreach ( $map as $suffix => $replacement ) {
			if ( ! self::ends( $word, $suffix ) ) {
				continue;
			}
			$stem = substr( $word, 0, -strlen( $suffix ) );
			if ( self::measure( $stem ) > $min_measure ) {
				return $stem . $replacement;
			}
			return $word;
		}
		return $word;
	}

	/**
	 * Whether the word ends with the given suffix.
	 *
	 * @param string $word   Word.
	 * @param string $suffix Suffix.
	 * @return bool
	 */
	private static function ends( $word, $suffix ) {
		$len = strlen( $suffix );
		return strlen( $word ) >= $len && substr( $word, -$len ) === $suffix;
	}

	/**
	 * Porter's "measure": the number of vowel-consonant sequences in the stem.
	 *
	 * @param string $stem Stem.
	 * @return int
	 */
	private static function measure( $stem ) {
		$form = self::consonant_vowel_form( $stem );
		// Strip a leading run of consonants and a trailing run of vowels; what
		// remains is a run of "vc" pairs, and m is how many.
		$form = preg_replace( '/^c+/', '', $form );
		$form = preg_replace( '/v+$/', '', $form );
		return (int) floor( strlen( (string) $form ) / 2 );
	}

	/**
	 * Whether the stem contains a vowel.
	 *
	 * @param string $stem Stem.
	 * @return bool
	 */
	private static function has_vowel( $stem ) {
		return false !== strpos( self::consonant_vowel_form( $stem ), 'v' );
	}

	/**
	 * Whether the stem ends in a doubled consonant.
	 *
	 * @param string $stem Stem.
	 * @return bool
	 */
	private static function double_consonant( $stem ) {
		$len = strlen( $stem );
		if ( $len < 2 || $stem[ $len - 1 ] !== $stem[ $len - 2 ] ) {
			return false;
		}
		return 'c' === substr( self::consonant_vowel_form( $stem ), -1 );
	}

	/**
	 * Whether the stem ends consonant-vowel-consonant, where the final
	 * consonant is not w, x, or y.
	 *
	 * @param string $stem Stem.
	 * @return bool
	 */
	private static function cvc( $stem ) {
		if ( strlen( $stem ) < 3 ) {
			return false;
		}
		if ( 'cvc' !== substr( self::consonant_vowel_form( $stem ), -3 ) ) {
			return false;
		}
		return ! preg_match( '/[wxy]$/', $stem );
	}

	/**
	 * Map a word to a string of c/v, treating y as a vowel only when it
	 * follows a consonant.
	 *
	 * @param string $stem Stem.
	 * @return string
	 */
	private static function consonant_vowel_form( $stem ) {
		$form = '';
		$len  = strlen( $stem );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $stem[ $i ];
			if ( false !== strpos( 'aeiou', $char ) ) {
				$form .= 'v';
			} elseif ( 'y' === $char ) {
				$form .= ( '' === $form || 'v' === substr( $form, -1 ) ) ? 'c' : 'v';
			} else {
				$form .= 'c';
			}
		}
		return $form;
	}
}
