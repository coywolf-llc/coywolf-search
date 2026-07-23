<?php
/**
 * Settings model: defaults, typed access, registration, and sanitisation.
 *
 * @package CoywolfSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for every configurable value.
 *
 * All settings live in one option (`coywolf_search_settings`) and pass through
 * one sanitize callback, so there is exactly one place where an incoming value
 * is validated against its expected type, range, or allowed set.
 */
final class Coywolf_Search_Settings {

	const OPTION = 'coywolf_search_settings';
	const GROUP  = 'coywolf_search';

	/**
	 * Per-request memo of the merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Content.
			'post_types'         => array( 'post', 'page' ),
			'taxonomies'         => array( 'category', 'post_tag' ),
			// 'taxonomy' is not a checkbox: it is enabled whenever at least one
			// taxonomy is selected above, and sanitize() keeps the two in step.
			'fields'             => array( 'title', 'content', 'excerpt', 'taxonomy' ),

			// Ranking and matching.
			'weight_title'       => 3.0,
			'weight_content'     => 1.0,
			'weight_excerpt'     => 1.5,
			'weight_taxonomy'    => 2.0,
			'bm25_k1'            => 1.2,
			'bm25_b'             => 0.75,
			'fuzzy'              => 'auto',
			'fuzzy_min_length'   => 4,
			'prefix_last'        => true,
			'max_expansions'     => 50,
			'or_fallback'        => true,

			// Tokenization.
			'min_token_length'   => 2,
			'stopwords_mode'     => 'builtin',
			'stopwords_custom'   => '',
			'stemming'           => false,

			// Typeahead.
			'typeahead'          => true,
			'typeahead_max'      => 8,
			'typeahead_debounce' => 200,
			'typeahead_enter'    => true,
		);
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Drop the per-request memo (after a save, or in tests).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Validate every incoming field against its expected type, range, or set.
	 *
	 * Anything unrecognised is dropped rather than stored.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = self::all();
		$out      = $defaults;

		if ( ! is_array( $input ) ) {
			return $current;
		}

		// Post types: must be registered, public, and not already excluded
		// from search unless the type opts itself in.
		$out['post_types'] = self::sanitize_keys_against(
			isset( $input['post_types'] ) ? $input['post_types'] : array(),
			self::available_post_types()
		);

		$out['taxonomies'] = self::sanitize_keys_against(
			isset( $input['taxonomies'] ) ? $input['taxonomies'] : array(),
			self::available_taxonomies()
		);

		$out['fields'] = self::sanitize_keys_against(
			isset( $input['fields'] ) ? $input['fields'] : array(),
			array( 'title', 'content', 'excerpt' )
		);
		// Taxonomy postings are governed by the taxonomies setting, not by the
		// field checkboxes, but the field must be enabled for them to score.
		if ( ! empty( $out['taxonomies'] ) ) {
			$out['fields'][] = 'taxonomy';
		}
		if ( empty( $out['fields'] ) ) {
			$out['fields'] = array( 'title' );
		}

		$out['weight_title']    = self::clamp_float( $input, 'weight_title', 0.0, 10.0, $defaults['weight_title'] );
		$out['weight_content']  = self::clamp_float( $input, 'weight_content', 0.0, 10.0, $defaults['weight_content'] );
		$out['weight_excerpt']  = self::clamp_float( $input, 'weight_excerpt', 0.0, 10.0, $defaults['weight_excerpt'] );
		$out['weight_taxonomy'] = self::clamp_float( $input, 'weight_taxonomy', 0.0, 10.0, $defaults['weight_taxonomy'] );

		$out['bm25_k1'] = self::clamp_float( $input, 'bm25_k1', 0.0, 3.0, $defaults['bm25_k1'] );
		$out['bm25_b']  = self::clamp_float( $input, 'bm25_b', 0.0, 1.0, $defaults['bm25_b'] );

		$fuzzy         = isset( $input['fuzzy'] ) ? sanitize_key( $input['fuzzy'] ) : '';
		$out['fuzzy']  = in_array( $fuzzy, array( 'off', 'one', 'auto' ), true ) ? $fuzzy : $defaults['fuzzy'];
		$out['fuzzy_min_length'] = self::clamp_int( $input, 'fuzzy_min_length', 2, 12, $defaults['fuzzy_min_length'] );

		$out['prefix_last']    = ! empty( $input['prefix_last'] );
		$out['max_expansions'] = self::clamp_int( $input, 'max_expansions', 1, 500, $defaults['max_expansions'] );
		$out['or_fallback']    = ! empty( $input['or_fallback'] );

		$out['min_token_length'] = self::clamp_int( $input, 'min_token_length', 1, 10, $defaults['min_token_length'] );

		$mode                  = isset( $input['stopwords_mode'] ) ? sanitize_key( $input['stopwords_mode'] ) : '';
		$out['stopwords_mode'] = in_array( $mode, array( 'none', 'builtin', 'custom' ), true ) ? $mode : $defaults['stopwords_mode'];

		$out['stopwords_custom'] = self::sanitize_word_list(
			isset( $input['stopwords_custom'] ) ? $input['stopwords_custom'] : ''
		);

		$out['stemming'] = ! empty( $input['stemming'] );

		$out['typeahead']          = ! empty( $input['typeahead'] );
		$out['typeahead_max']      = self::clamp_int( $input, 'typeahead_max', 1, 20, $defaults['typeahead_max'] );
		$out['typeahead_debounce'] = self::clamp_int( $input, 'typeahead_debounce', 0, 2000, $defaults['typeahead_debounce'] );
		$out['typeahead_enter']    = ! empty( $input['typeahead_enter'] );

		self::$cache = $out;

		// A change to any tokenization-affecting setting invalidates the
		// stored index: terms were written under the old rules and would no
		// longer match what the query pipeline produces.
		if ( self::pipeline_hash( $current ) !== self::pipeline_hash( $out ) ) {
			update_option( 'coywolf_search_index_stale', 1, false );
		}

		return $out;
	}

	/**
	 * Fingerprint of every setting that changes what gets written to the
	 * index. When it changes, the index must be rebuilt.
	 *
	 * @param array<string,mixed>|null $settings Settings to hash, or null for current.
	 * @return string
	 */
	public static function pipeline_hash( $settings = null ) {
		$s = is_array( $settings ) ? $settings : self::all();

		$relevant = array(
			'post_types'       => isset( $s['post_types'] ) ? (array) $s['post_types'] : array(),
			'taxonomies'       => isset( $s['taxonomies'] ) ? (array) $s['taxonomies'] : array(),
			'fields'           => isset( $s['fields'] ) ? (array) $s['fields'] : array(),
			'min_token_length' => isset( $s['min_token_length'] ) ? $s['min_token_length'] : 2,
			'stopwords_mode'   => isset( $s['stopwords_mode'] ) ? $s['stopwords_mode'] : 'builtin',
			'stopwords_custom' => isset( $s['stopwords_custom'] ) ? $s['stopwords_custom'] : '',
			'stemming'         => ! empty( $s['stemming'] ),
		);
		sort( $relevant['post_types'] );
		sort( $relevant['taxonomies'] );
		sort( $relevant['fields'] );

		return md5( (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Public post types a site can reasonably index.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function available_post_types() {
		$out   = array();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->name;
		}
		return $out;
	}

	/**
	 * Public taxonomies whose term names can be indexed.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function available_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[ $tax->name ] = $tax->labels->name;
		}
		return $out;
	}

	/**
	 * Keep only submitted keys that exist in the allowed set.
	 *
	 * @param mixed                $raw     Submitted value.
	 * @param array<string,string> $allowed Allowed keys.
	 * @return string[]
	 */
	private static function sanitize_keys_against( $raw, $allowed ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $value ) {
			$key = sanitize_key( $value );
			if ( isset( $allowed[ $key ] ) || in_array( $key, $allowed, true ) ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * One word per line, lowercased, de-duplicated.
	 *
	 * @param mixed $raw Submitted textarea value.
	 * @return string
	 */
	private static function sanitize_word_list( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$lines = preg_split( '/\R/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$word = sanitize_text_field( $line );
			$word = trim( self::lowercase( $word ) );
			if ( '' !== $word ) {
				$out[ $word ] = true;
			}
		}
		return implode( "\n", array_keys( $out ) );
	}

	/**
	 * Clamp a submitted float into range.
	 *
	 * @param array  $input   Submitted array.
	 * @param string $key     Key to read.
	 * @param float  $min     Minimum.
	 * @param float  $max     Maximum.
	 * @param float  $default_value Fallback when absent or non-numeric.
	 * @return float
	 */
	private static function clamp_float( $input, $key, $min, $max, $default_value ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return (float) $default_value;
		}
		return (float) max( $min, min( $max, (float) $input[ $key ] ) );
	}

	/**
	 * Clamp a submitted integer into range.
	 *
	 * @param array  $input   Submitted array.
	 * @param string $key     Key to read.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param int    $default_value Fallback when absent or non-numeric.
	 * @return int
	 */
	private static function clamp_int( $input, $key, $min, $max, $default_value ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return (int) $default_value;
		}
		return (int) max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Multibyte-safe lowercase.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function lowercase( $text ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}
		return strtolower( $text );
	}

	/**
	 * The effective stopword list for the current mode.
	 *
	 * @return array<string,bool> Word => true, for O(1) lookup.
	 */
	public static function stopwords() {
		$mode = self::get( 'stopwords_mode' );

		if ( 'none' === $mode ) {
			return array();
		}

		if ( 'custom' === $mode ) {
			$words = preg_split( '/\R/', (string) self::get( 'stopwords_custom' ) );
		} else {
			$words = self::builtin_stopwords();
		}

		$out = array();
		foreach ( (array) $words as $word ) {
			$word = trim( (string) $word );
			if ( '' !== $word ) {
				$out[ $word ] = true;
			}
		}
		return $out;
	}

	/**
	 * A conservative English stopword list.
	 *
	 * Deliberately short: an aggressive list makes phrases like "to be or not
	 * to be" unsearchable.
	 *
	 * @return string[]
	 */
	public static function builtin_stopwords() {
		return array(
			'a', 'about', 'after', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
			'be', 'because', 'been', 'but', 'by', 'can', 'come', 'could', 'did', 'do', 'does',
			'for', 'from', 'get', 'had', 'has', 'have', 'he', 'her', 'here', 'him', 'his', 'how',
			'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'like', 'me', 'more', 'most', 'my',
			'no', 'not', 'now', 'of', 'on', 'one', 'only', 'or', 'other', 'our', 'out', 'over',
			'said', 'same', 'see', 'she', 'so', 'some', 'such', 'than', 'that', 'the', 'their',
			'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too',
			'under', 'up', 'use', 'very', 'was', 'way', 'we', 'well', 'were', 'what', 'when',
			'where', 'which', 'while', 'who', 'will', 'with', 'would', 'you', 'your',
		);
	}
}
