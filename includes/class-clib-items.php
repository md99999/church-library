<?php
/**
 * Catalog items: validation, CRUD, ID generation, search, images.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Items {

	/** Max image upload size in bytes. */
	const MAX_IMAGE_BYTES = 5242880;

	/** Pixel limits for uploaded / imported images. */
	const MAX_PIXELS_SIDE = 8000;
	const MAX_PIXELS_AREA = 40000000;

	/** When true, per-item audit entries are suppressed (bulk import logs one summary). */
	public static $quiet = false;

	/** Sanitized values from the last validate() call (used to refill the form after an error). */
	public static $last_clean = array();

	/** Item id => [ old code, new code ] for IDs regenerated during this request. */
	public static $recoded = array();

	/** Allowed image types (extension regex => mime). */
	public static function image_mimes() {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);
	}

	/** Sortable columns (whitelist: request value => SQL column). */
	public static function sort_columns() {
		return array(
			'item_code'      => 'i.item_code',
			'title'          => 'i.title',
			'creator'        => 'i.creator',
			'media_type'     => 'mt.label',
			'church'         => 'ch.label',
			'location'       => 'i.location_code',
			'storage_status' => 'i.storage_status',
			'updated_at'     => 'i.updated_at',
		);
	}

	/**
	 * Validate raw POST data into a clean row.
	 *
	 * @param array $raw  Raw (slashed) request data.
	 * @return array|WP_Error
	 */
	public static function validate( array $raw ) {
		$g = function ( $k ) use ( $raw ) {
			return isset( $raw[ $k ] ) ? $raw[ $k ] : '';
		};

		$d = array(
			'title'             => CLIB_Util::text( $g( 'title' ), 255 ),
			'description'       => CLIB_Util::textarea( $g( 'description' ), 10000 ),
			'notes'             => CLIB_Util::textarea( $g( 'notes' ), 5000 ),
			'creator'           => CLIB_Util::text( $g( 'creator' ), 255 ),
			'publisher'         => CLIB_Util::text( $g( 'publisher' ), 255 ),
			'media_type_id'     => CLIB_Util::id_or_null( $g( 'media_type_id' ) ),
			'subcategory_id'    => CLIB_Util::id_or_null( $g( 'subcategory_id' ) ),
			'difficulty'        => CLIB_Util::enum( $g( 'difficulty' ), array_map( 'strval', array_keys( CLIB_Util::difficulty_labels() ) ), '' ),
			'item_condition'    => CLIB_Util::enum( $g( 'item_condition' ), array_map( 'strval', array_keys( CLIB_Util::condition_labels() ) ), '' ),
			'liturgical_use'    => CLIB_Util::text( $g( 'liturgical_use' ), 255 ),
			'classification_id' => CLIB_Util::id_or_null( $g( 'classification_id' ) ),
			'owned_by'          => CLIB_Util::text( $g( 'owned_by' ), 150 ),
			'identifier'        => CLIB_Util::text( $g( 'identifier' ), 64 ),
			'barcode'           => CLIB_Util::text( $g( 'barcode' ), 64 ),
			'call_number'       => CLIB_Util::text( $g( 'call_number' ), 64 ),
			'church_id'         => CLIB_Util::id_or_null( $g( 'church_id' ) ),
			'building_id'       => CLIB_Util::id_or_null( $g( 'building_id' ) ),
			'room'              => CLIB_Util::text( $g( 'room' ), 100 ),
			'location_code'     => CLIB_Util::text( $g( 'location_code' ), 64 ),
			'copies'            => CLIB_Util::int( '' === $g( 'copies' ) ? 1 : $g( 'copies' ), 1, 9999 ),
			'storage_status'    => CLIB_Util::enum( $g( 'storage_status' ), CLIB_Util::STATUSES, 'available' ),
			'inventory_notes'   => CLIB_Util::textarea( $g( 'inventory_notes' ), 5000 ),
			'cost'              => CLIB_Util::money( $g( 'cost' ) ),
			'acquired_date'     => CLIB_Util::date( $g( 'acquired_date' ) ),
		);

		$errors = new WP_Error();
		if ( '' === $d['title'] ) {
			$errors->add( 'title', __( 'Title is required.', 'church-library' ) );
		}
		if ( ! $d['media_type_id'] || ! CLIB_LOV::in_list( $d['media_type_id'], 'media_type' ) ) {
			$errors->add( 'media_type_id', __( 'Choose a valid media type.', 'church-library' ) );
		}
		if ( $d['classification_id'] && ! CLIB_LOV::in_list( $d['classification_id'], 'classification' ) ) {
			$errors->add( 'classification_id', __( 'Invalid classification.', 'church-library' ) );
		}
		if ( $d['church_id'] && ! CLIB_LOV::in_list( $d['church_id'], 'church' ) ) {
			$errors->add( 'church_id', __( 'Invalid church.', 'church-library' ) );
		}
		if ( $d['building_id'] ) {
			$b = CLIB_LOV::get( $d['building_id'] );
			if ( ! $b || 'building' !== $b->list_type ) {
				$errors->add( 'building_id', __( 'Invalid building.', 'church-library' ) );
			} elseif ( $b->parent_id && (int) $b->parent_id !== (int) $d['church_id'] ) {
				$errors->add( 'building_id', __( 'That building belongs to a different church.', 'church-library' ) );
			}
		}

		// Room comes from Library - List of Values; stored by name.
		if ( '' !== $d['room'] ) {
			$room = CLIB_LOV::find_room( $d['room'], (int) $d['building_id'] );
			if ( ! $room ) {
				$errors->add( 'room', __( 'Room is not in Library - List of Values.', 'church-library' ) );
			} elseif ( $room->parent_id && (int) $room->parent_id !== (int) $d['building_id'] ) {
				$errors->add( 'room', __( 'That room belongs to a different building.', 'church-library' ) );
			} else {
				$d['room'] = $room->label;
			}
		}

		// Music-only fields.
		if ( CLIB_LOV::is_music_type( $d['media_type_id'] ) ) {
			if ( $d['subcategory_id'] && ! CLIB_LOV::in_list( $d['subcategory_id'], 'music_subcategory' ) ) {
				$errors->add( 'subcategory_id', __( 'Invalid music sub-category.', 'church-library' ) );
			}
		} else {
			$d['subcategory_id'] = null;
			$d['difficulty']     = '';
		}

		// Status "checked_out" is driven by loans, not typed in.
		if ( 'checked_out' === $d['storage_status'] ) {
			$d['storage_status'] = 'available';
		}

		self::$last_clean = $d;
		return $errors->has_errors() ? $errors : $d;
	}

	/**
	 * Full save from a submitted item form (admin or front end): validate,
	 * handle the image (upload > lookup cover > remove) and create/update.
	 * Callers must verify the nonce and clib_manage_items first.
	 *
	 * @param array $post Raw (slashed) request data.
	 * @param int   $id   Existing item id or 0.
	 * @return array|WP_Error [ 'id' => int, 'notices' => [ [type, message] ] ]
	 */
	public static function save_from_request( array $post, $id ) {
		$id   = (int) $id;
		$item = $id ? self::get( $id ) : null;
		if ( $id && ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		$data = self::validate( $post );
		if ( is_wp_error( $data ) ) {
			set_transient( 'clib_form_' . get_current_user_id(), self::$last_clean, 5 * MINUTE_IN_SECONDS );
			return $data;
		}
		$notices = array();

		$image = self::handle_image_upload( 'clib_image' );
		if ( is_wp_error( $image ) ) {
			$notices[] = array( 'error', $image->get_error_message() );
			$image     = 0;
		}
		if ( ! $image ) {
			$provider = isset( $post['cover_provider'] ) && is_string( $post['cover_provider'] ) ? sanitize_key( $post['cover_provider'] ) : '';
			$ref      = CLIB_Util::text( isset( $post['cover_ref'] ) ? $post['cover_ref'] : '', 100 );
			if ( '' !== $provider && '' !== $ref ) {
				$image = CLIB_Lookup::import_cover( $provider, $ref, $data['title'] );
				if ( is_wp_error( $image ) ) {
					$notices[] = array( 'warning', __( 'The cover image could not be imported; you can upload one instead.', 'church-library' ) );
					$image     = 0;
				}
			}
		}
		if ( $image ) {
			$data['image_id'] = (int) $image;
		} elseif ( $item && ! empty( $post['remove_image'] ) ) {
			$data['image_id'] = null;
		}

		if ( $item ) {
			if ( ! self::update( $id, $data ) ) {
				return new WP_Error( 'clib_db', __( 'Could not save the item.', 'church-library' ) );
			}
			$notices[] = array( 'success', __( 'Item updated.', 'church-library' ) );
			if ( isset( self::$recoded[ $id ] ) ) {
				/* translators: 1: old item id 2: new item id */
				$notices[] = array( 'warning', sprintf( __( 'The media type changed, so the Item ID changed from %1$s to %2$s. Reprint the label if it shows the old ID.', 'church-library' ), self::$recoded[ $id ][0], self::$recoded[ $id ][1] ) );
			} elseif ( (int) $item->media_type_id !== (int) $data['media_type_id'] && ! self::code_matches( self::get( $id )->item_code, $data['media_type_id'] ) ) {
				$notices[] = array( 'error', __( 'The media type changed but a new Item ID could not be assigned. Use Library - Data Tools > Item ID check to fix it.', 'church-library' ) );
			}
			return array( 'id' => $id, 'notices' => $notices );
		}
		$new_id = self::create( $data );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		/* translators: %s: item id */
		$notices[] = array( 'success', sprintf( __( 'Item %s added.', 'church-library' ), self::get( $new_id )->item_code ) );
		return array( 'id' => $new_id, 'notices' => $notices );
	}

	/** Item ID prefix for a media type: its List of Values code, or ITEM when it has none. */
	public static function prefix_for( $media_type_id ) {
		$mt     = $media_type_id ? CLIB_LOV::get( (int) $media_type_id ) : null;
		$prefix = ( $mt && 'media_type' === $mt->list_type ) ? CLIB_Util::code( $mt->code, 10 ) : '';
		return '' === $prefix ? 'ITEM' : $prefix;
	}

	/** Prefix part of an item code ("DVD-000012" -> "DVD"), or '' when the code is not in PREFIX-NUMBER form. */
	public static function code_prefix( $code ) {
		return preg_match( '/^([A-Z0-9]{1,10})-[0-9]{1,12}$/', (string) $code, $m ) ? $m[1] : '';
	}

	/** Does an item code carry the prefix of this media type? */
	public static function code_matches( $code, $media_type_id ) {
		return self::code_prefix( $code ) === self::prefix_for( $media_type_id );
	}

	/** Generate the next item code for a media type, e.g. BOOK-000123. */
	public static function next_code( $media_type_id ) {
		global $wpdb;
		$prefix = self::prefix_for( $media_type_id );
		$seq = CLIB_DB::table( 'sequences' );
		// Atomic increment: LAST_INSERT_ID(expr) is connection-scoped, so two
		// simultaneous saves can never receive the same number.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $seq (prefix, next_val) VALUES (%s, 0)", $prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE $seq SET next_val = LAST_INSERT_ID(next_val + 1) WHERE prefix = %s", $prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		return sprintf( '%s-%06d', $prefix, $n );
	}

	private static function formats() {
		return array(
			'item_code'         => '%s',
			'title'             => '%s',
			'description'       => '%s',
			'notes'             => '%s',
			'creator'           => '%s',
			'publisher'         => '%s',
			'media_type_id'     => '%d',
			'subcategory_id'    => '%d',
			'difficulty'        => '%s',
			'item_condition'    => '%s',
			'liturgical_use'    => '%s',
			'classification_id' => '%d',
			'owned_by'          => '%s',
			'identifier'        => '%s',
			'barcode'           => '%s',
			'call_number'       => '%s',
			'church_id'         => '%d',
			'building_id'       => '%d',
			'room'              => '%s',
			'location_code'     => '%s',
			'copies'            => '%d',
			'storage_status'    => '%s',
			'inventory_notes'   => '%s',
			'image_id'          => '%d',
			'cost'              => '%s',
			'acquired_date'     => '%s',
			'is_archived'       => '%d',
			'archived_at'       => '%s',
			'created_at'        => '%s',
			'updated_at'        => '%s',
			'created_by'        => '%d',
			'updated_by'        => '%d',
		);
	}

	private static function fmt_for( array $data ) {
		$all = self::formats();
		$out = array();
		foreach ( array_keys( $data ) as $k ) {
			$out[] = $all[ $k ];
		}
		return $out;
	}

	/** @return int|WP_Error New item id. */
	public static function create( array $d ) {
		global $wpdb;
		$d['created_at'] = CLIB_Util::now();
		$d['updated_at'] = $d['created_at'];
		$d['created_by'] = get_current_user_id();
		$d['updated_by'] = $d['created_by'];
		$d               = array_intersect_key( $d, self::formats() );

		for ( $try = 0; $try < 3; $try++ ) {
			$d['item_code'] = self::next_code( $d['media_type_id'] );
			if ( $wpdb->insert( CLIB_DB::table( 'items' ), $d, self::fmt_for( $d ) ) ) {
				$id = (int) $wpdb->insert_id;
				if ( ! self::$quiet ) {
					CLIB_Audit::log( 'item_create', 'item', $id, $d['item_code'] . ' ' . $d['title'] );
				}
				return $id;
			}
		}
		return new WP_Error( 'clib_db', __( 'Could not save the item.', 'church-library' ) );
	}

	/**
	 * Update an item. When the media type changes to one with a different
	 * prefix, the item gets a new Item ID from that prefix's sequence.
	 */
	public static function update( $id, array $d ) {
		global $wpdb;
		$old = self::get( $id );
		if ( ! $old ) {
			return false;
		}
		$d['updated_at'] = CLIB_Util::now();
		$d['updated_by'] = get_current_user_id();
		// item_code changes only through recode(); archive state only through archive() / restore().
		unset( $d['item_code'], $d['created_at'], $d['created_by'], $d['is_archived'], $d['archived_at'] );
		$d  = array_intersect_key( $d, self::formats() );
		$ok = false !== $wpdb->update( CLIB_DB::table( 'items' ), $d, array( 'id' => (int) $id ), self::fmt_for( $d ), array( '%d' ) );
		if ( $ok ) {
			self::refresh_status( $id );
			CLIB_Audit::log( 'item_update', 'item', $id, isset( $d['title'] ) ? $d['title'] : '' );
			if ( isset( $d['media_type_id'] ) && (int) $d['media_type_id'] !== (int) $old->media_type_id ) {
				self::recode( $id ); // Errors are reported by the caller (code still mismatched).
			}
		}
		return $ok;
	}

	/**
	 * Give an item a new Item ID that matches its media type, if it does not
	 * already. The UPDATE only succeeds while the row still has the old code
	 * (optimistic lock), and a clash with an existing code is retried with the
	 * next number.
	 *
	 * @return string|WP_Error The item's (new or unchanged) code.
	 */
	public static function recode( $id ) {
		global $wpdb;
		$t    = CLIB_DB::table( 'items' );
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		if ( self::code_matches( $item->item_code, $item->media_type_id ) ) {
			return $item->item_code;
		}
		$old = $item->item_code;
		for ( $try = 0; $try < 3; $try++ ) {
			$code = self::next_code( $item->media_type_id );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE item_code = %s", $code ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue; // Number already used (e.g. sequence reset); take the next one.
			}
			$rows = $wpdb->query( $wpdb->prepare( "UPDATE $t SET item_code = %s, updated_at = %s, updated_by = %d WHERE id = %d AND item_code = %s", $code, CLIB_Util::now(), get_current_user_id(), (int) $id, $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 === $rows ) {
				self::$recoded[ (int) $id ] = array( $old, $code );
				CLIB_Audit::log( 'item_recode', 'item', $id, $old . ' -> ' . $code );
				return $code;
			}
			if ( 0 === $rows ) {
				return new WP_Error( 'clib_changed', __( 'The item was changed by someone else. Reload and try again.', 'church-library' ) );
			}
			// false: most likely the code already exists (unique key); try the next number.
		}
		return new WP_Error( 'clib_db', __( 'A new Item ID could not be assigned.', 'church-library' ) );
	}

	/** Archive: hidden from the catalog, reports, lookups and circulation; can be restored. */
	public static function archive( $id ) {
		global $wpdb;
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		if ( (int) $item->is_archived ) {
			return true;
		}
		if ( CLIB_Loans::active_count( $id ) > 0 ) {
			return new WP_Error( 'clib_loaned', __( 'Check in every copy before archiving the item.', 'church-library' ) );
		}
		$now = CLIB_Util::now();
		$ok  = $wpdb->update(
			CLIB_DB::table( 'items' ),
			array(
				'is_archived' => 1,
				'archived_at' => $now,
				'updated_at'  => $now,
				'updated_by'  => get_current_user_id(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'clib_db', __( 'The item could not be archived.', 'church-library' ) );
		}
		CLIB_Audit::log( 'item_archive', 'item', $id, $item->item_code . ' ' . $item->title );
		return true;
	}

	/** Restore an archived item to the catalog. */
	public static function restore( $id ) {
		global $wpdb;
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		$t  = CLIB_DB::table( 'items' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET is_archived = 0, archived_at = NULL, updated_at = %s, updated_by = %d WHERE id = %d", CLIB_Util::now(), get_current_user_id(), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $ok ) {
			return new WP_Error( 'clib_db', __( 'The item could not be restored.', 'church-library' ) );
		}
		CLIB_Audit::log( 'item_restore', 'item', $id, $item->item_code . ' ' . $item->title );
		return true;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'items' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Find by item code or barcode (circulation desk, public item page). Archived items are not found. */
	public static function find_by_code( $code ) {
		global $wpdb;
		$code = CLIB_Util::text( $code, 64 );
		if ( '' === $code ) {
			return null;
		}
		$t   = CLIB_DB::table( 'items' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE item_code = %s AND is_archived = 0 LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE barcode = %s AND is_archived = 0 LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $row;
	}

	public static function delete( $id ) {
		global $wpdb;
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		if ( CLIB_Loans::active_count( $id ) > 0 ) {
			return new WP_Error( 'clib_loaned', __( 'Check the item in before deleting it.', 'church-library' ) );
		}
		// Loan history and the item go together or not at all.
		$wpdb->query( 'START TRANSACTION' );
		$loans = $wpdb->delete( CLIB_DB::table( 'loans' ), array( 'item_id' => (int) $id ), array( '%d' ) );
		$items = false === $loans ? false : $wpdb->delete( CLIB_DB::table( 'items' ), array( 'id' => (int) $id ), array( '%d' ) );
		if ( false === $loans || 1 !== $items ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'clib_db', __( 'The item could not be deleted; nothing was changed.', 'church-library' ) );
		}
		$wpdb->query( 'COMMIT' );
		CLIB_Audit::log( 'item_delete', 'item', $id, $item->item_code . ' ' . $item->title . ( $loans ? sprintf( ' (%d loan records)', (int) $loans ) : '' ) );
		return true;
	}

	/** Recalculate storage_status from active loans (Not Available is sticky). */
	public static function refresh_status( $id ) {
		global $wpdb;
		$item = self::get( $id );
		if ( ! $item || 'not_available' === $item->storage_status ) {
			return;
		}
		$out    = CLIB_Loans::active_count( $id );
		$status = ( $out >= (int) $item->copies ) ? 'checked_out' : 'available';
		if ( $status !== $item->storage_status ) {
			$wpdb->update( CLIB_DB::table( 'items' ), array( 'storage_status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Search the catalog.
	 *
	 * @param array $args q, media_type_id, church_id, building_id, status, orderby, order, page, per_page.
	 * @return array [rows, total]
	 */
	public static function search( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'q'                 => '',
				'media_type_id'     => 0,
				'church_id'         => 0,
				'building_id'       => 0,
				'classification_id' => 0,
				'status'            => '',
				'archived'          => 0, // 0 = catalog (default), 1 = archived items only.
				'orderby'           => 'title',
				'order'             => 'asc',
				'page'              => 1,
				'per_page'          => 25,
				'search_barcode'    => true, // false on the public catalog: barcodes are staff-only data.
			)
		);

		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$where = array( empty( $args['archived'] ) ? 'i.is_archived = 0' : 'i.is_archived = 1' );
		$vals  = array();

		$q = is_string( $args['q'] ) ? $args['q'] : '';
		if ( '' !== $q ) {
			// The term is only ever a bound %s value; esc_like() makes % _ \ literal.
			$cols = array( 'i.title', 'i.creator', 'i.identifier', 'i.item_code', 'i.publisher' );
			if ( ! empty( $args['search_barcode'] ) ) {
				$cols[] = 'i.barcode';
			}
			$like  = '%' . $wpdb->esc_like( $q ) . '%';
			$parts = array();
			foreach ( $cols as $col ) { // Fixed column list above, never input.
				$parts[] = "$col LIKE %s";
				$vals[]  = $like;
			}
			$where[] = '(' . implode( ' OR ', $parts ) . ')';
		}
		foreach ( array( 'media_type_id', 'church_id', 'building_id', 'classification_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "i.$col = %d"; // Column names come from the fixed list above.
				$vals[]  = (int) $args[ $col ];
			}
		}
		if ( in_array( $args['status'], CLIB_Util::STATUSES, true ) ) {
			$where[] = 'i.storage_status = %s';
			$vals[]  = $args['status'];
		}

		$sorts   = self::sort_columns();
		$orderby = isset( $sorts[ $args['orderby'] ] ) ? $sorts[ $args['orderby'] ] : 'i.title';
		$order   = ( 'desc' === strtolower( (string) $args['order'] ) ) ? 'DESC' : 'ASC';

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$join = "LEFT JOIN $lov mt ON mt.id = i.media_type_id LEFT JOIN $lov ch ON ch.id = i.church_id LEFT JOIN $lov bl ON bl.id = i.building_id";
		$wsql = implode( ' AND ', $where );

		if ( ! empty( $args['ids_only'] ) ) {
			$limit   = max( 1, min( 100000, (int) $args['ids_only'] ) );
			$ids_sql = "SELECT i.id FROM $items i $join WHERE $wsql ORDER BY i.id ASC LIMIT %d";
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $vals, array( $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$count_sql = "SELECT COUNT(*) FROM $items i $join WHERE $wsql";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT i.*, mt.label AS media_type_label, ch.label AS church_label, bl.label AS building_label
			FROM $items i $join WHERE $wsql ORDER BY $orderby $order, i.id ASC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array( $rows, $total );
	}

	/**
	 * Handle the image upload field. Returns attachment id, 0 when no file, or WP_Error.
	 */
	public static function handle_image_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
			return 0;
		}
		$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'clib_upload', __( 'Invalid upload.', 'church-library' ) );
		}
		if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return 0;
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'clib_upload', __( 'The image upload failed.', 'church-library' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'clib_upload', __( 'Invalid upload.', 'church-library' ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'clib_upload', __( 'Images must be 5 MB or smaller.', 'church-library' ) );
		}

		$err = self::validate_image_file( $file['tmp_name'], isset( $file['name'] ) ? $file['name'] : '' );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		self::require_media();
		$err = self::reencode_image( $file['tmp_name'] );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		clearstatcache( true, $file['tmp_name'] );
		$new_name = self::random_image_name( $file['tmp_name'] );
		$err      = self::validate_image_file( $file['tmp_name'], $new_name ); // Check the re-encoded output too.
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$_FILES[ $field ]['size'] = (int) filesize( $file['tmp_name'] );
		$_FILES[ $field ]['name'] = $new_name; // The visitor's file name is never used on disk.
		$_FILES[ $field ]['type'] = wp_check_filetype( $new_name, self::image_mimes() )['type'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::protect_upload_dir();
		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		// wp_handle_upload() (inside media_handle_upload) sanitizes the file name,
		// strips any path component and guarantees a unique name inside uploads.
		$id = media_handle_upload(
			$field,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => self::image_mimes(),
			)
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		return $id;
	}

	/** Content-based image validation (extension, real MIME and image header). */
	public static function validate_image_file( $path, $name ) {
		$name = (string) $name;
		// Names: no null bytes or control characters, and no executable/script
		// extension anywhere in the name (blocks "photo.php.jpg" tricks).
		if ( preg_match( '/[\x00-\x1F]/', $name ) || preg_match( '/\.(php\d*|phtml|pht|phar|inc|pl|py|cgi|sh|bash|exe|dll|bat|cmd|com|msi|jar|js|mjs|html?|xhtml|shtml|svgz?|xml|asp|aspx|jsp|htaccess)(\.|$)/i', $name ) ) {
			return new WP_Error( 'clib_upload', __( 'That file name is not allowed. Rename the photo (for example cover.jpg) and try again.', 'church-library' ) );
		}
		// Content must start with a real image signature (magic bytes).
		$head = file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $head || ! self::magic_mime( $head ) ) {
			return new WP_Error( 'clib_upload', __( 'The file is not a JPG, PNG, GIF or WebP image.', 'church-library' ) );
		}
		$check = wp_check_filetype_and_ext( $path, $name, self::image_mimes() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], self::image_mimes(), true ) ) {
			return new WP_Error( 'clib_upload', __( 'Only JPG, PNG, GIF or WebP images are allowed.', 'church-library' ) );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || ! in_array( $info['mime'], self::image_mimes(), true ) || self::magic_mime( $head ) !== $info['mime'] ) {
			return new WP_Error( 'clib_upload', __( 'The file is not a valid image.', 'church-library' ) );
		}
		// Pixel caps stop "decompression bomb" images from exhausting memory.
		if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] > self::MAX_PIXELS_SIDE || (int) $info[1] > self::MAX_PIXELS_SIDE || (int) $info[0] * (int) $info[1] > self::MAX_PIXELS_AREA ) {
			return new WP_Error( 'clib_upload', __( 'The image is too large. Use a photo under 8,000 pixels on each side.', 'church-library' ) );
		}
		return true;
	}

	/** MIME type from the file's first bytes, or '' if it is not an allowed image. */
	public static function magic_mime( $head ) {
		$head = (string) $head;
		if ( 0 === strncmp( $head, "\xFF\xD8\xFF", 3 ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'image/png';
		}
		if ( 0 === strncmp( $head, 'GIF87a', 6 ) || 0 === strncmp( $head, 'GIF89a', 6 ) ) {
			return 'image/gif';
		}
		if ( 0 === strncmp( $head, 'RIFF', 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		return '';
	}

	/** Random server-side file name with the extension forced from the real type. */
	public static function random_image_name( $path ) {
		$head = file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ext  = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$mime = self::magic_mime( $head );
		return 'clib-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . ( isset( $ext[ $mime ] ) ? $ext[ $mime ] : 'jpg' );
	}

	/**
	 * Re-encode an image in place through the WordPress image editor.
	 *
	 * This strips EXIF/GPS metadata from phone photos, drops anything appended
	 * after the image data (polyglot / "string appended" payloads) and caps
	 * the size at 2000px.
	 */
	public static function reencode_image( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info ) {
			return new WP_Error( 'clib_upload', __( 'The file is not a valid image.', 'church-library' ) );
		}
		$mime = $info['mime'];
		$ext  = array_search( $mime, array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ), true );
		if ( false === $ext ) {
			return new WP_Error( 'clib_upload', __( 'Only JPG, PNG, GIF or WebP images are allowed.', 'church-library' ) );
		}
		$editor = wp_get_image_editor( $path, array( 'mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'clib_upload', __( 'The image could not be processed.', 'church-library' ) );
		}
		$editor->resize( 2000, 2000, false );
		$out   = $path . '-clean.' . $ext;
		$saved = $editor->save( $out, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return new WP_Error( 'clib_upload', __( 'The image could not be processed.', 'church-library' ) );
		}
		$ok = copy( $saved['path'], $path ); // Overwrite the temp file's contents so is_uploaded_file() still holds.
		wp_delete_file( $saved['path'] );
		return $ok ? true : new WP_Error( 'clib_upload', __( 'The image could not be processed.', 'church-library' ) );
	}

	/** Drop index.php + .htaccess (no script execution) into uploads/church-library. */
	public static function protect_upload_dir() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'church-library';
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => CLIB_DB::uploads_htaccess(),
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Download an image from a URL that was built and host-checked server-side
	 * (see CLIB_Lookup::import_cover) and add it to the media library.
	 */
	public static function sideload_image( $url, $name, $title ) {
		self::require_media();
		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$size = (int) filesize( $tmp );
		if ( $size > self::MAX_IMAGE_BYTES || $size < 200 ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'clib_upload', __( 'No usable cover image was found.', 'church-library' ) );
		}
		$err = self::validate_image_file( $tmp, 'cover.jpg' );
		if ( ! is_wp_error( $err ) ) {
			$err = self::reencode_image( $tmp );
		}
		if ( ! is_wp_error( $err ) ) {
			clearstatcache( true, $tmp );
			$name = self::random_image_name( $tmp );
			$err  = self::validate_image_file( $tmp, $name );
		}
		if ( is_wp_error( $err ) ) {
			wp_delete_file( $tmp );
			return $err;
		}
		self::protect_upload_dir();
		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			CLIB_Util::text( $title, 200 )
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
		}
		return $id;
	}

	/** Store library images in uploads/church-library/YYYY/MM. */
	public static function upload_dir( $dirs ) {
		$sub            = '/church-library' . $dirs['subdir'];
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		$dirs['subdir'] = $sub;
		return $dirs;
	}

	private static function require_media() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
