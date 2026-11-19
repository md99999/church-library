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

	/** When true, per-item audit entries are suppressed (bulk import logs one summary). */
	public static $quiet = false;

	/** Sanitized values from the last validate() call (used to refill the form after an error). */
	public static $last_clean = array();

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
			'difficulty'        => CLIB_Util::enum( $g( 'difficulty' ), CLIB_Util::DIFFICULTIES, '' ),
			'item_condition'    => CLIB_Util::enum( $g( 'item_condition' ), CLIB_Util::CONDITIONS, '' ),
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
			self::update( $id, $data );
			$notices[] = array( 'success', __( 'Item updated.', 'church-library' ) );
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

	/** Generate the next item code for a media type, e.g. BOOK-000123. */
	public static function next_code( $media_type_id ) {
		global $wpdb;
		$mt     = CLIB_LOV::get( $media_type_id );
		$prefix = $mt ? CLIB_Util::code( $mt->code, 10 ) : '';
		if ( '' === $prefix ) {
			$prefix = 'ITEM';
		}
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

	public static function update( $id, array $d ) {
		global $wpdb;
		$d['updated_at'] = CLIB_Util::now();
		$d['updated_by'] = get_current_user_id();
		unset( $d['item_code'], $d['created_at'], $d['created_by'] ); // Immutable.
		$d  = array_intersect_key( $d, self::formats() );
		$ok = false !== $wpdb->update( CLIB_DB::table( 'items' ), $d, array( 'id' => (int) $id ), self::fmt_for( $d ), array( '%d' ) );
		if ( $ok ) {
			self::refresh_status( $id );
			CLIB_Audit::log( 'item_update', 'item', $id, isset( $d['title'] ) ? $d['title'] : '' );
		}
		return $ok;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'items' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Find by item code or barcode (for circulation desk). */
	public static function find_by_code( $code ) {
		global $wpdb;
		$code = CLIB_Util::text( $code, 64 );
		if ( '' === $code ) {
			return null;
		}
		$t   = CLIB_DB::table( 'items' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE item_code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE barcode = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		$wpdb->delete( CLIB_DB::table( 'loans' ), array( 'item_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( CLIB_DB::table( 'items' ), array( 'id' => (int) $id ), array( '%d' ) );
		CLIB_Audit::log( 'item_delete', 'item', $id, $item->item_code . ' ' . $item->title );
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
				'orderby'           => 'title',
				'order'             => 'asc',
				'page'              => 1,
				'per_page'          => 25,
			)
		);

		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$where = array( '1=1' );
		$vals  = array();

		if ( '' !== $args['q'] ) {
			$like    = '%' . $wpdb->esc_like( $args['q'] ) . '%';
			$where[] = '(i.title LIKE %s OR i.creator LIKE %s OR i.identifier LIKE %s OR i.barcode LIKE %s OR i.item_code LIKE %s OR i.publisher LIKE %s)';
			array_push( $vals, $like, $like, $like, $like, $like, $like );
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
		$_FILES[ $field ]['size'] = (int) filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
		$check = wp_check_filetype_and_ext( $path, $name, self::image_mimes() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], self::image_mimes(), true ) ) {
			return new WP_Error( 'clib_upload', __( 'Only JPG, PNG, GIF or WebP images are allowed.', 'church-library' ) );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || ! in_array( $info['mime'], self::image_mimes(), true ) ) {
			return new WP_Error( 'clib_upload', __( 'The file is not a valid image.', 'church-library' ) );
		}
		return true;
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
		$err = self::validate_image_file( $tmp, $name );
		if ( ! is_wp_error( $err ) ) {
			$err = self::reencode_image( $tmp );
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
