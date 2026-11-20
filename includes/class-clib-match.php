<?php
/**
 * Fuzzy title / author matching (pure functions, no database or network).
 *
 * Used to rank lookup results against what was typed on the item form and to
 * find possible duplicate titles in the catalog. Every function works on
 * plain strings, caps its input length and never throws, so hostile or
 * oversized remote data cannot cause excessive work.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Match {

	/** Longest string compared (characters); longer input is truncated first. */
	const MAX_LEN = 255;

	/** Leading articles ignored when comparing titles. */
	const ARTICLES = array( 'the', 'a', 'an' );

	/**
	 * Lower-case, accent-free, punctuation-free, single-spaced text.
	 * "The Hobbit: or, There and Back Again" -> "the hobbit or there and back again".
	 */
	public static function normalize( $s ) {
		if ( ! is_scalar( $s ) ) {
			return '';
		}
		$s = CLIB_Util::cap( (string) $s, self::MAX_LEN );
		$s = wp_check_invalid_utf8( $s, true );
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = str_replace( '&', ' and ', $s );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return null === $s ? '' : trim( substr( trim( $s ), 0, self::MAX_LEN ) ); // Byte cap keeps levenshtein() within its PHP 7 limit.
	}

	/** Title key: normalized, without a leading article. Used for duplicate grouping. */
	public static function title_key( $title ) {
		$words = self::words( self::normalize( $title ) );
		if ( count( $words ) > 1 && in_array( $words[0], self::ARTICLES, true ) ) {
			array_shift( $words );
		}
		return implode( ' ', $words );
	}

	/** Split normalized text into words. */
	private static function words( $normalized ) {
		return '' === $normalized ? array() : explode( ' ', $normalized );
	}

	/**
	 * Similarity of two titles, 0.0 - 1.0.
	 * Exact key match scores 1. Otherwise a blend of word overlap (how many of the
	 * wanted words appear) and character similarity, so "Hobbit" still ranks
	 * "The Hobbit, or There and Back Again" above "The Hobbit Companion".
	 */
	public static function title_score( $wanted, $candidate ) {
		$a = self::title_key( $wanted );
		$b = self::title_key( $candidate );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return 1.0;
		}
		$wa      = array_unique( self::words( $a ) );
		$wb      = array_unique( self::words( $b ) );
		$common  = count( array_intersect( $wa, $wb ) );
		$recall  = $common / max( 1, count( $wa ) );               // Wanted words found.
		$precise = $common / max( 1, count( $wb ) );               // Extra words penalised.
		similar_text( $a, $b, $pct );
		$score = 0.45 * $recall + 0.25 * $precise + 0.30 * ( $pct / 100 );
		// A candidate that starts with the whole wanted title is a strong match.
		if ( 0 === strpos( $b, $a . ' ' ) ) {
			$score = max( $score, 0.85 );
		}
		return round( min( 0.99, max( 0.0, $score ) ), 4 );
	}

	/**
	 * Name tokens, order-independent. Handles "First Last", "Last, First",
	 * initials ("J.R.R. Tolkien" -> j r r tolkien) and several authors in one
	 * field. Order does not matter, so "Tolkien, J. R. R." and "J.R.R. Tolkien"
	 * give the same tokens.
	 */
	public static function name_tokens( $name ) {
		if ( ! is_scalar( $name ) ) {
			return array();
		}
		// Split run-together initials before normalising: "J.R.R." -> "J. R. R.".
		$name  = preg_replace( '/(\p{Lu})\.(?=\p{Lu})/u', '$1. ', CLIB_Util::cap( (string) $name, self::MAX_LEN ) );
		$words = self::words( self::normalize( (string) $name ) );
		$skip  = array( 'and', 'by', 'ed', 'eds', 'editor', 'jr', 'sr', 'dr', 'rev', 'mr', 'mrs', 'ms', 'phd' );
		return array_values( array_diff( $words, $skip ) );
	}

	/**
	 * Similarity of two author strings, 0.0 - 1.0, order-independent.
	 * Each wanted token counts as found when the candidate has the same word, or
	 * when one side is an initial of the other ("j" vs "john"). The longest
	 * wanted word (usually the surname) must match for a high score.
	 */
	public static function author_score( $wanted, $candidate ) {
		$wa = self::name_tokens( $wanted );
		$wb = self::name_tokens( $candidate );
		if ( ! $wa || ! $wb ) {
			return 0.0;
		}
		$found   = 0.0;
		$longest = '';
		foreach ( $wa as $w ) {
			if ( strlen( $w ) > strlen( $longest ) ) {
				$longest = $w;
			}
			if ( in_array( $w, $wb, true ) ) {
				$found += 1;
				continue;
			}
			foreach ( $wb as $c ) {
				$initial = ( 1 === strlen( $w ) && 0 === strpos( $c, $w ) ) || ( 1 === strlen( $c ) && 0 === strpos( $w, $c ) );
				if ( $initial ) {
					$found += 0.6;
					break;
				}
				// Small spelling differences in longer names ("Lewis" vs "Louis").
				if ( strlen( $w ) >= 5 && strlen( $c ) >= 5 && levenshtein( $w, $c ) <= 1 ) {
					$found += 0.8;
					break;
				}
			}
		}
		$score = $found / count( $wa );
		if ( strlen( $longest ) > 1 && ! in_array( $longest, $wb, true ) ) {
			$score *= 0.5; // Surname missing: probably a different person.
		}
		return round( min( 1.0, $score ), 4 );
	}

	/**
	 * Overall match score (0-100) for a lookup result. Title only when no author
	 * was typed; otherwise 60% title, 40% author.
	 */
	public static function result_score( $want_title, $want_author, $title, $creator ) {
		$t = self::title_score( $want_title, $title );
		if ( '' === trim( (string) $want_author ) ) {
			return (int) round( $t * 100 );
		}
		$a = self::author_score( $want_author, $creator );
		return (int) round( ( 0.6 * $t + 0.4 * $a ) * 100 );
	}

	/**
	 * Are two titles close enough to be possible duplicates?
	 * Exact title keys, or (when $close is true) keys within a small edit
	 * distance relative to their length.
	 */
	public static function is_duplicate_title( $a, $b, $close = true ) {
		$ka = self::title_key( $a );
		$kb = self::title_key( $b );
		if ( '' === $ka || '' === $kb ) {
			return false;
		}
		if ( $ka === $kb ) {
			return true;
		}
		return $close && self::keys_close( $ka, $kb );
	}

	/** Edit-distance test on two title keys (both already normalized). */
	public static function keys_close( $ka, $kb ) {
		$la = strlen( $ka );
		$lb = strlen( $kb );
		if ( $la < 6 || $lb < 6 || abs( $la - $lb ) > 3 ) {
			return false;
		}
		$max = min( 3, max( 1, (int) floor( min( $la, $lb ) / 10 ) ) );
		return levenshtein( $ka, $kb ) <= $max;
	}
}
