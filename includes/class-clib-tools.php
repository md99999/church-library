<?php
/**
 * Data tools: Item ID / media type mismatch check and repair, and finding and
 * merging possible duplicate titles within the same media type.
 *
 * Callers (admin handlers) verify the nonce and capability first. Everything
 * here re-validates the ids it is given against the database, and every change
 * runs inside a transaction that is rolled back on any failure.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Tools {

	/** Most rows read by a scan (protects memory on very large catalogs). */
	const MAX_SCAN = 50000;

	/** Most items fixed in one request (keeps each request well inside PHP time limits). */
	const MAX_FIX = 500;

	/** Most records merged into one in a single step. */
	const MAX_MERGE = 20;

	/** Most pair comparisons for close (not exact) title matches. */
	const MAX_COMPARISONS = 1500000;

	/** Highest copies value the items table accepts (see CLIB_Items::validate). */
	const MAX_COPIES = 9999;

	/* ---------------------------------------------------------------------
	 * Item ID check.
	 * ------------------------------------------------------------------ */

	/**
	 * Items whose Item ID prefix does not match their media type's prefix
	 * (including archived items, so their IDs are right if they are restored).
	 *
	 * @return array[] [ id, item_code, title, media_type, is_archived, expected ]
	 */
	public static function id_mismatches() {
		global $wpdb;
		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.item_code, i.title, i.media_type_id, i.is_archived, mt.label AS media_label FROM $items i LEFT JOIN $lov mt ON mt.id = i.media_type_id ORDER BY i.item_code LIMIT %d", self::MAX_SCAN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		$want  = array();
		foreach ( (array) $rows as $r ) {
			$mid = (int) $r->media_type_id;
			if ( ! isset( $want[ $mid ] ) ) {
				$want[ $mid ] = CLIB_Items::prefix_for( $mid );
			}
			if ( CLIB_Items::code_prefix( $r->item_code ) !== $want[ $mid ] ) {
				$out[] = array(
					'id'          => (int) $r->id,
					'item_code'   => (string) $r->item_code,
					'title'       => (string) $r->title,
					'media_type'  => (string) $r->media_label,
					'is_archived' => (int) $r->is_archived,
					'expected'    => $want[ $mid ],
				);
			}
		}
		return $out;
	}

	/**
	 * Give each listed item a new Item ID that matches its media type.
	 * Only items that are still mismatched are changed. All or nothing.
	 *
	 * @param int[] $ids Item ids chosen on the preview screen.
	 * @return array|WP_Error [ 'fixed' => [ [old, new], ... ], 'skipped' => int ]
	 */
	public static function fix_ids( array $ids ) {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::MAX_FIX );
		if ( ! $ids ) {
			return new WP_Error( 'clib_tools', __( 'Tick at least one item to fix.', 'church-library' ) );
		}
		$items   = CLIB_DB::table( 'items' );
		$fixed   = array();
		$skipped = 0;

		CLIB_Items::$recoded = array();
		$wpdb->query( 'START TRANSACTION' );
		// Lock the rows so nobody edits them while their IDs change.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_code, media_type_id FROM $items WHERE id IN (" . CLIB_Util::in_placeholders( $ids ) . ') ORDER BY id FOR UPDATE', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %d list from in_placeholders().
		foreach ( (array) $rows as $r ) {
			if ( CLIB_Items::code_matches( $r->item_code, $r->media_type_id ) ) {
				++$skipped; // Already fixed (for example by someone else in the meantime).
				continue;
			}
			$res = CLIB_Items::recode( (int) $r->id );
			if ( is_wp_error( $res ) ) {
				$wpdb->query( 'ROLLBACK' );
				CLIB_Items::$recoded = array();
				/* translators: 1: item id 2: error message */
				return new WP_Error( 'clib_tools', sprintf( __( 'Stopped at %1$s: %2$s Nothing was changed.', 'church-library' ), $r->item_code, $res->get_error_message() ) );
			}
			$fixed[] = array( (string) $r->item_code, $res );
		}
		$skipped += count( $ids ) - count( (array) $rows ); // Ids that no longer exist.
		$wpdb->query( 'COMMIT' );
		if ( $fixed ) {
			CLIB_Audit::log(
				'item_id_fix',
				'item',
				0,
				CLIB_Util::cap(
					sprintf( '%d items: ', count( $fixed ) ) . implode(
						', ',
						array_map(
							function ( $p ) {
								return $p[0] . '->' . $p[1];
							},
							$fixed
						)
					),
					1000
				)
			);
		}
		return array(
			'fixed'   => $fixed,
			'skipped' => $skipped,
		);
	}

	/* ---------------------------------------------------------------------
	 * Duplicates.
	 * ------------------------------------------------------------------ */

	/**
	 * Groups of catalog items (not archived) that look like the same title in
	 * the same media type. Exact matches compare titles ignoring case, accents,
	 * punctuation and a leading "The / A / An". Close matches also allow a small
	 * spelling difference ("Hobbit" vs "Hobit").
	 *
	 * @param bool $close       Include close matches.
	 * @param int  $media_type  Only this media type (0 = all).
	 * @return array [ 'groups' => [ [ 'media_type' => label, 'items' => rows[] ] ], 'truncated' => bool ]
	 */
	public static function duplicate_groups( $close = false, $media_type = 0 ) {
		global $wpdb;
		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$where = 'i.is_archived = 0';
		$vals  = array();
		if ( $media_type ) {
			$where .= ' AND i.media_type_id = %d';
			$vals[] = (int) $media_type;
		}
		$vals[] = self::MAX_SCAN;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.item_code, i.title, i.creator, i.publisher, i.identifier, i.barcode, i.copies, i.room, i.location_code, i.media_type_id, i.storage_status, mt.label AS media_label, ch.label AS church_label FROM $items i LEFT JOIN $lov mt ON mt.id = i.media_type_id LEFT JOIN $lov ch ON ch.id = i.church_id WHERE $where ORDER BY i.id LIMIT %d", $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		// Bucket by media type + title key: exact duplicates share a bucket.
		$buckets = array();
		$byid    = array();
		foreach ( (array) $rows as $r ) {
			$key = CLIB_Match::title_key( $r->title );
			if ( '' === $key ) {
				continue;
			}
			$r->title_key                                   = $key;
			$byid[ (int) $r->id ]                           = $r;
			$buckets[ (int) $r->media_type_id ][ $key ][] = (int) $r->id;
		}

		// Union-find over item ids; exact buckets are joined first.
		$parent = array();
		$find   = function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		$union  = function ( $a, $b ) use ( &$parent, $find ) {
			$ra = $find( $a );
			$rb = $find( $b );
			if ( $ra !== $rb ) {
				$parent[ max( $ra, $rb ) ] = min( $ra, $rb );
			}
		};
		foreach ( $byid as $id => $r ) {
			$parent[ $id ] = $id;
		}
		foreach ( $buckets as $keys ) {
			foreach ( $keys as $ids ) {
				for ( $n = 1, $c = count( $ids ); $n < $c; $n++ ) {
					$union( $ids[0], $ids[ $n ] );
				}
			}
		}

		$truncated = false;
		if ( $close ) {
			// Compare distinct keys within a media type, only against keys that
			// share their first three letters (cheap pre-filter), up to a cap.
			$done = 0;
			foreach ( $buckets as $keys ) {
				$by_start = array();
				foreach ( array_keys( $keys ) as $k ) {
					$by_start[ substr( $k, 0, 3 ) ][] = $k;
				}
				foreach ( $by_start as $list ) {
					for ( $a = 0, $c = count( $list ); $a < $c; $a++ ) {
						for ( $b = $a + 1; $b < $c; $b++ ) {
							if ( ++$done > self::MAX_COMPARISONS ) {
								$truncated = true;
								break 4;
							}
							if ( CLIB_Match::keys_close( (string) $list[ $a ], (string) $list[ $b ] ) ) {
								$union( $keys[ $list[ $a ] ][0], $keys[ $list[ $b ] ][0] );
							}
						}
					}
				}
			}
		}

		$groups = array();
		foreach ( $byid as $id => $r ) {
			$groups[ $find( $id ) ][] = $r;
		}
		$out = array();
		foreach ( $groups as $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			$out[] = array(
				'media_type' => (string) $members[0]->media_label,
				'items'      => $members,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['media_type'] . $a['items'][0]->title_key, $b['media_type'] . $b['items'][0]->title_key );
			}
		);
		return array(
			'groups'    => $out,
			'truncated' => $truncated,
		);
	}

	/**
	 * Merge duplicate records into one.
	 *
	 * The kept record's quantity (copies) becomes the total of all merged
	 * records; its empty descriptive fields are filled from the others; loan
	 * history moves to it; a note of the merged Item IDs and barcodes is added
	 * to its inventory notes; the other records are deleted. All or nothing.
	 *
	 * @param int   $keep_id  Record to keep.
	 * @param int[] $ids      Records to merge into it (may include $keep_id).
	 * @return array|WP_Error [ 'item_code' => string, 'copies' => int, 'merged' => string[] ]
	 */
	public static function merge( $keep_id, array $ids ) {
		global $wpdb;
		$keep_id = (int) $keep_id;
		$ids     = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $ids ) ) ), array( $keep_id ) ) );
		if ( ! $keep_id || ! $ids ) {
			return new WP_Error( 'clib_merge', __( 'Choose the record to keep and at least one other record to merge into it.', 'church-library' ) );
		}
		if ( count( $ids ) >= self::MAX_MERGE ) {
			/* translators: %d: maximum */
			return new WP_Error( 'clib_merge', sprintf( __( 'Merge at most %d records at a time.', 'church-library' ), self::MAX_MERGE ) );
		}
		$all   = array_merge( array( $keep_id ), $ids );
		$items = CLIB_DB::table( 'items' );
		$loans = CLIB_DB::table( 'loans' );
		$fail  = function ( $msg ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'clib_merge', $msg . ' ' . __( 'Nothing was changed.', 'church-library' ) );
		};

		$wpdb->query( 'START TRANSACTION' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $items WHERE id IN (" . CLIB_Util::in_placeholders( $all ) . ') FOR UPDATE', $all ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %d list from in_placeholders().
		$byid = array();
		foreach ( (array) $rows as $r ) {
			$byid[ (int) $r->id ] = $r;
		}
		if ( count( $byid ) !== count( $all ) ) {
			return $fail( __( 'One of the records no longer exists. Reload the page and try again.', 'church-library' ) );
		}
		$keep = $byid[ $keep_id ];
		foreach ( $ids as $id ) {
			$r = $byid[ $id ];
			if ( (int) $r->is_archived || (int) $keep->is_archived ) {
				return $fail( __( 'Archived items cannot be merged.', 'church-library' ) );
			}
			if ( (int) $r->media_type_id !== (int) $keep->media_type_id ) {
				return $fail( __( 'Only records with the same media type can be merged.', 'church-library' ) );
			}
			if ( ! CLIB_Match::is_duplicate_title( $keep->title, $r->title, true ) ) {
				/* translators: 1: title 2: title */
				return $fail( sprintf( __( '"%1$s" and "%2$s" are not close enough to be the same title.', 'church-library' ), $keep->title, $r->title ) );
			}
		}

		// Quantity on hand: the sum of every merged record.
		$copies = 0;
		foreach ( $all as $id ) {
			$copies += max( 1, (int) $byid[ $id ]->copies );
		}
		if ( $copies > self::MAX_COPIES ) {
			/* translators: %d: maximum copies */
			return $fail( sprintf( __( 'The total number of copies would be more than %d.', 'church-library' ), self::MAX_COPIES ) );
		}

		// Fill the kept record's empty fields from the others (first non-empty wins).
		$fill    = array( 'creator', 'publisher', 'description', 'identifier', 'barcode', 'call_number', 'classification_id', 'liturgical_use', 'image_id', 'cost', 'acquired_date', 'owned_by' );
		$formats = array(
			'classification_id' => '%d',
			'image_id'          => '%d',
		);
		$set     = array();
		foreach ( $fill as $col ) {
			if ( null !== $keep->$col && '' !== (string) $keep->$col && '0' !== (string) $keep->$col ) {
				continue;
			}
			foreach ( $ids as $id ) {
				$v = $byid[ $id ]->$col;
				if ( null !== $v && '' !== (string) $v && '0' !== (string) $v ) {
					$set[ $col ] = $v;
					break;
				}
			}
		}

		$merged = array();
		foreach ( $ids as $id ) {
			$r        = $byid[ $id ];
			$merged[] = $r->item_code . ( '' !== (string) $r->barcode ? ' (' . __( 'barcode', 'church-library' ) . ' ' . $r->barcode . ')' : '' );
		}
		/* translators: 1: date 2: list of item ids */
		$note = sprintf( __( '%1$s: merged from %2$s.', 'church-library' ), current_time( 'Y-m-d' ), implode( ', ', $merged ) );

		$set['copies']          = $copies;
		$set['inventory_notes'] = CLIB_Util::cap( trim( (string) $keep->inventory_notes . "\n" . $note ), 5000 );
		$set['updated_at']      = CLIB_Util::now();
		$set['updated_by']      = get_current_user_id();
		$fmt                    = array();
		foreach ( array_keys( $set ) as $col ) {
			$fmt[] = isset( $formats[ $col ] ) || in_array( $col, array( 'copies', 'updated_by' ), true ) ? '%d' : '%s';
		}
		if ( false === $wpdb->update( $items, $set, array( 'id' => $keep_id ), $fmt, array( '%d' ) ) ) {
			return $fail( __( 'The kept record could not be updated.', 'church-library' ) );
		}

		// Loan history (and any copies currently out) now belong to the kept record.
		$in = CLIB_Util::in_placeholders( $ids );
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE $loans SET item_id = %d WHERE item_id IN ($in)", array_merge( array( $keep_id ), $ids ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			return $fail( __( 'Loan history could not be moved.', 'church-library' ) );
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $items WHERE id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- %d list from in_placeholders().
		if ( count( $ids ) !== (int) $deleted ) {
			return $fail( __( 'The merged records could not be removed.', 'church-library' ) );
		}
		$wpdb->query( 'COMMIT' );

		CLIB_Items::refresh_status( $keep_id );
		CLIB_Audit::log( 'item_merge', 'item', $keep_id, CLIB_Util::cap( sprintf( '%s %s: copies=%d; merged %s', $keep->item_code, $keep->title, $copies, implode( ', ', $merged ) ), 1000 ) );
		return array(
			'item_code' => (string) $keep->item_code,
			'copies'    => $copies,
			'merged'    => $merged,
		);
	}
}
