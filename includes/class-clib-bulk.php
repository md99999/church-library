<?php
/**
 * Bulk edit of location fields (Church, Building, Room) for many items.
 *
 * Two steps: prepare() validates the selection and requested changes and
 * stores them server-side under a random token for the current user;
 * apply() re-validates everything from that stored request and updates the
 * items with prepared statements, then writes one audit entry.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Bulk {

	const MAX_ITEMS = 5000;
	const NO_CHANGE = '';
	const CLEAR     = 'clear';

	/** Transient key for a user's pending bulk edit. */
	private static function key( $token ) {
		return 'clib_bulk_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * Parse one "church" / "building" choice: '' = no change, 'clear' = empty, or a list id.
	 *
	 * @return array|WP_Error [ 'mode' => 'keep'|'clear'|'set', 'id' => int ]
	 */
	private static function parse_choice( $raw, $type ) {
		$raw = is_scalar( $raw ) ? (string) wp_unslash( $raw ) : '';
		if ( self::NO_CHANGE === $raw ) {
			return array( 'mode' => 'keep', 'id' => 0 );
		}
		if ( self::CLEAR === $raw ) {
			return array( 'mode' => 'clear', 'id' => 0 );
		}
		$id = CLIB_Util::int( $raw );
		if ( ! $id || ! CLIB_LOV::in_list( $id, $type ) ) {
			return new WP_Error( 'clib_bulk', 'church' === $type ? __( 'Invalid church.', 'church-library' ) : __( 'Invalid building.', 'church-library' ) );
		}
		return array( 'mode' => 'set', 'id' => $id );
	}

	/**
	 * Validate a bulk request.
	 *
	 * @param array $src Raw POST data.
	 * @return array|WP_Error Normalised request.
	 */
	public static function validate( array $src ) {
		// Which items.
		$scope = CLIB_Util::enum( isset( $src['bulk_scope'] ) ? $src['bulk_scope'] : '', array( 'selected', 'filter' ), 'selected' );
		if ( 'filter' === $scope ) {
			$filter = array(
				'q'             => CLIB_Util::text( isset( $src['f_s'] ) ? $src['f_s'] : '', 100 ),
				'media_type_id' => CLIB_Util::int( isset( $src['f_media_type_id'] ) ? $src['f_media_type_id'] : 0 ),
				'church_id'     => CLIB_Util::int( isset( $src['f_church_id'] ) ? $src['f_church_id'] : 0 ),
				'building_id'   => CLIB_Util::int( isset( $src['f_building_id'] ) ? $src['f_building_id'] : 0 ),
				'status'        => CLIB_Util::enum( isset( $src['f_status'] ) ? $src['f_status'] : '', CLIB_Util::STATUSES, '' ),
				'ids_only'      => self::MAX_ITEMS + 1,
			);
			$ids = CLIB_Items::search( $filter );
		} else {
			$raw = isset( $src['ids'] ) && is_array( $src['ids'] ) ? $src['ids'] : array();
			$ids = array();
			foreach ( $raw as $v ) {
				$v = CLIB_Util::int( $v );
				if ( $v > 0 ) {
					$ids[ $v ] = $v;
				}
			}
			$ids = array_values( $ids );
		}
		if ( ! $ids ) {
			return new WP_Error( 'clib_bulk', __( 'Select at least one item.', 'church-library' ) );
		}
		if ( count( $ids ) > self::MAX_ITEMS ) {
			/* translators: %d: maximum */
			return new WP_Error( 'clib_bulk', sprintf( __( 'Bulk edit is limited to %d items at a time. Narrow the filter and try again.', 'church-library' ), self::MAX_ITEMS ) );
		}
		$ids = self::existing_ids( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'clib_bulk', __( 'None of the selected items exist.', 'church-library' ) );
		}

		// What changes.
		$church = self::parse_choice( isset( $src['bulk_church'] ) ? $src['bulk_church'] : '', 'church' );
		if ( is_wp_error( $church ) ) {
			return $church;
		}
		$building = self::parse_choice( isset( $src['bulk_building'] ) ? $src['bulk_building'] : '', 'building' );
		if ( is_wp_error( $building ) ) {
			return $building;
		}
		$room_on = ! empty( $src['bulk_room_on'] );
		$room    = $room_on ? CLIB_Util::text( isset( $src['bulk_room'] ) ? $src['bulk_room'] : '', 100 ) : '';

		if ( 'keep' === $church['mode'] && 'keep' === $building['mode'] && ! $room_on ) {
			return new WP_Error( 'clib_bulk', __( 'Choose at least one change: Church, Building or Room.', 'church-library' ) );
		}

		// A building that belongs to a church fixes the church too.
		if ( 'set' === $building['mode'] ) {
			$b = CLIB_LOV::get( $building['id'] );
			if ( $b->parent_id ) {
				if ( 'keep' === $church['mode'] ) {
					$church = array( 'mode' => 'set', 'id' => (int) $b->parent_id );
				} elseif ( 'clear' === $church['mode'] || (int) $b->parent_id !== $church['id'] ) {
					return new WP_Error( 'clib_bulk', __( 'That building belongs to a different church.', 'church-library' ) );
				}
			}
		}

		return array(
			'ids'      => $ids,
			'church'   => $church,
			'building' => $building,
			'room_on'  => $room_on,
			'room'     => $room,
		);
	}

	/** Keep only ids that exist (chunked, prepared). */
	private static function existing_ids( array $ids ) {
		global $wpdb;
		$t   = CLIB_DB::table( 'items' );
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql = "SELECT id FROM $t WHERE id IN (" . CLIB_Util::in_placeholders( $chunk ) . ')';
			$out = array_merge( $out, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		sort( $out );
		return $out;
	}

	/** Step 1: validate and store; returns the token. */
	public static function prepare( array $src ) {
		$req = self::validate( $src );
		if ( is_wp_error( $req ) ) {
			return $req;
		}
		$token = wp_generate_password( 20, false, false );
		set_transient( self::key( $token ), $req, 15 * MINUTE_IN_SECONDS );
		return $token;
	}

	/** Load a pending request for the current user (token is [A-Za-z0-9]). */
	public static function load( $token ) {
		$token = is_string( $token ) ? preg_replace( '/[^A-Za-z0-9]/', '', $token ) : '';
		if ( 20 !== strlen( $token ) ) {
			return null;
		}
		$req = get_transient( self::key( $token ) );
		return is_array( $req ) ? $req : null;
	}

	public static function discard( $token ) {
		$token = is_string( $token ) ? preg_replace( '/[^A-Za-z0-9]/', '', $token ) : '';
		if ( '' !== $token ) {
			delete_transient( self::key( $token ) );
		}
	}

	/** Human-readable list of the changes. */
	public static function describe( array $req ) {
		$out = array();
		if ( 'set' === $req['church']['mode'] ) {
			/* translators: %s: church */
			$out[] = sprintf( __( 'Church Name → %s', 'church-library' ), CLIB_LOV::label( $req['church']['id'] ) );
		} elseif ( 'clear' === $req['church']['mode'] ) {
			$out[] = __( 'Church Name → (cleared)', 'church-library' );
		}
		if ( 'set' === $req['building']['mode'] ) {
			/* translators: %s: building */
			$out[] = sprintf( __( 'Building → %s', 'church-library' ), CLIB_LOV::label( $req['building']['id'] ) );
		} elseif ( 'clear' === $req['building']['mode'] ) {
			$out[] = __( 'Building → (cleared)', 'church-library' );
		} elseif ( 'keep' !== $req['church']['mode'] ) {
			$out[] = __( 'Building → cleared where it belongs to a different church', 'church-library' );
		}
		if ( $req['room_on'] ) {
			/* translators: %s: room */
			$out[] = '' === $req['room'] ? __( 'Room → (cleared)', 'church-library' ) : sprintf( __( 'Room → %s', 'church-library' ), $req['room'] );
		}
		return $out;
	}

	/** First few items of a request, for the confirmation screen. */
	public static function sample( array $req, $limit = 15 ) {
		global $wpdb;
		$t     = CLIB_DB::table( 'items' );
		$chunk = array_slice( $req['ids'], 0, $limit );
		$sql   = "SELECT id, item_code, title FROM $t WHERE id IN (" . CLIB_Util::in_placeholders( $chunk ) . ') ORDER BY title';
		return $wpdb->get_results( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Step 2: apply a stored request.
	 *
	 * @return int|WP_Error Number of items updated.
	 */
	public static function apply( $token ) {
		global $wpdb;
		$req = self::load( $token );
		if ( ! $req ) {
			return new WP_Error( 'clib_bulk', __( 'This bulk edit has expired. Please select the items again.', 'church-library' ) );
		}
		self::discard( $token );

		// Re-validate the stored values against the current lists and items.
		foreach ( array( 'church', 'building' ) as $type ) {
			if ( 'set' === $req[ $type ]['mode'] && ! CLIB_LOV::in_list( (int) $req[ $type ]['id'], $type ) ) {
				return new WP_Error( 'clib_bulk', __( 'A list value was changed or removed in the meantime. Please start again.', 'church-library' ) );
			}
		}
		$ids = self::existing_ids( array_map( 'intval', (array) $req['ids'] ) );
		if ( ! $ids ) {
			return new WP_Error( 'clib_bulk', __( 'None of the selected items exist.', 'church-library' ) );
		}

		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$now   = CLIB_Util::now();
		$user  = get_current_user_id();

		$sets = array( 'updated_at = %s', 'updated_by = %d' );
		$vals = array( $now, $user );
		foreach ( array( 'church' => 'church_id', 'building' => 'building_id' ) as $type => $col ) {
			if ( 'set' === $req[ $type ]['mode'] ) {
				$sets[] = "$col = %d"; // $col from fixed map.
				$vals[] = (int) $req[ $type ]['id'];
			} elseif ( 'clear' === $req[ $type ]['mode'] ) {
				$sets[] = "$col = NULL";
			}
		}
		if ( $req['room_on'] ) {
			$sets[] = 'room = %s';
			$vals[] = CLIB_Util::cap( (string) $req['room'], 100 );
		}

		$wpdb->query( 'START TRANSACTION' );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql = "UPDATE $items SET " . implode( ', ', $sets ) . ' WHERE id IN (' . CLIB_Util::in_placeholders( $chunk ) . ')';
			if ( false === $wpdb->query( $wpdb->prepare( $sql, array_merge( $vals, $chunk ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'clib_bulk', __( 'The update failed; nothing was changed.', 'church-library' ) );
			}
			// Church changed but building kept: drop buildings that belong to another church.
			if ( 'keep' === $req['building']['mode'] && 'keep' !== $req['church']['mode'] ) {
				$new_church = 'set' === $req['church']['mode'] ? (int) $req['church']['id'] : 0;
				$fix        = "UPDATE $items i JOIN $lov b ON b.id = i.building_id SET i.building_id = NULL
					WHERE b.parent_id IS NOT NULL AND b.parent_id <> %d AND i.id IN (" . CLIB_Util::in_placeholders( $chunk ) . ')';
				$wpdb->query( $wpdb->prepare( $fix, array_merge( array( $new_church ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		$wpdb->query( 'COMMIT' );

		CLIB_Audit::log(
			'bulk_edit',
			'item',
			0,
			sprintf( '%d items: %s. IDs: %s', count( $ids ), implode( '; ', self::describe( $req ) ), implode( ',', array_slice( $ids, 0, 100 ) ) . ( count( $ids ) > 100 ? ',...' : '' ) )
		);
		return count( $ids );
	}
}
