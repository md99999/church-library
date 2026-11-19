<?php
/**
 * CSV import (e.g. the "Library List of Books" spreadsheet saved as CSV).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Import {

	const MAX_BYTES = 5242880;
	const MAX_ROWS  = 5000;

	/** Header aliases (lower-case, punctuation stripped) => field. */
	public static function header_map() {
		return array(
			'title'           => 'title',
			'authorsname'     => 'creator',
			'author'          => 'creator',
			'authors'         => 'creator',
			'creator'         => 'creator',
			'creatorauthor'   => 'creator',
			'publisher'       => 'publisher',
			'description'     => 'description',
			'notes'           => 'notes',
			'barcode'         => 'barcode',
			'call'            => 'call_number',
			'callnumber'      => 'call_number',
			'classification'  => 'classification',
			'cost'            => 'cost',
			'costin2007'      => 'cost',
			'price'           => 'cost',
			'isbn'            => 'identifier',
			'identifier'      => 'identifier',
			'isbnoridentifier' => 'identifier',
			'mediatype'       => 'media_type',
			'church'          => 'church',
			'churchname'      => 'church',
			'building'        => 'building',
			'room'            => 'room',
			'location'        => 'location_code',
			'locationid'      => 'location_code',
			'copies'          => 'copies',
			'numberofcopies'  => 'copies',
			'condition'       => 'item_condition',
			'ownedby'         => 'owned_by',
			'liturgicaluse'   => 'liturgical_use',
			'inventorynotes'  => 'inventory_notes',
			'itemid'          => 'item_code',
			'itemcode'        => 'item_code',
			'musicsubcategory' => 'subcategory',
			'subcategory'     => 'subcategory',
			'difficulty'      => 'difficulty',
			'difficultylevel' => 'difficulty',
			'storagestatus'   => 'storage_status',
			'status'          => 'storage_status',
			'acquireddate'    => 'acquired_date',
			'acquired'        => 'acquired_date',
		);
	}

	private static function norm_header( $h ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $h ) );
	}

	/**
	 * Undo the export's formula neutralisation: "'=..." becomes "=...". The value is
	 * still only ever stored as text and escaped on output.
	 */
	private static function unneutralise( $s ) {
		if ( strlen( $s ) > 1 && "'" === $s[0] && in_array( $s[1], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return substr( $s, 1 );
		}
		return $s;
	}

	/** Per-row error for a value that is not in the List of Values. */
	private static function unknown( $column, $value ) {
		/* translators: 1: column name 2: value */
		return new WP_Error( 'clib_import', sprintf( __( '%1$s "%2$s" is not in Library - List of Values. Add it there or correct the cell.', 'church-library' ), $column, CLIB_Util::cap( sanitize_text_field( (string) $value ), 60 ) ) );
	}

	/** Map a label or key (any case) to a whitelisted key, or $default. */
	private static function key_from_label( $value, array $labels, $default = '' ) {
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return $default;
		}
		foreach ( $labels as $k => $label ) {
			if ( $v === $k || strtolower( $label ) === $v ) {
				return $k;
			}
		}
		return $default;
	}

	/** Find a building by label, preferring one that belongs to $church_id. */
	private static function find_building( $label, $church_id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'lov' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE list_type = 'building' AND label = %s ORDER BY (parent_id = %d) DESC, parent_id IS NULL DESC LIMIT 1", $label, (int) $church_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function to_utf8( $s ) {
		$s = (string) $s;
		if ( '' === $s || seems_utf8( $s ) ) {
			return $s;
		}
		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' ) : wp_check_invalid_utf8( $s, true );
	}

	/**
	 * Run an import from the uploaded file field.
	 *
	 * @param string $field    $_FILES key.
	 * @param array  $defaults media_type_id, church_id, building_id, skip_dupes, add_classes, dry_run.
	 * @return array|WP_Error  Summary.
	 */
	public static function run( $field, array $defaults ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
			return new WP_Error( 'clib_import', __( 'Choose a CSV file.', 'church-library' ) );
		}
		$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $file['error'] ) || is_array( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'clib_import', __( 'The upload failed.', 'church-library' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'clib_import', __( 'Invalid upload.', 'church-library' ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'clib_import', __( 'CSV files must be 5 MB or smaller.', 'church-library' ) );
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'clib_import', __( 'Only .csv files can be imported. In Excel use File > Save As > CSV UTF-8.', 'church-library' ) );
		}
		// Reject binary content (e.g. an .xlsx renamed to .csv).
		$head = file_get_contents( $file['tmp_name'], false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $head || false !== strpos( $head, "\0" ) || 0 === strpos( $head, 'PK' ) ) {
			return new WP_Error( 'clib_import', __( 'That file is not a plain-text CSV.', 'church-library' ) );
		}

		return self::run_file( $file['tmp_name'], $name, $defaults );
	}

	/**
	 * Import from a local file path (already validated as an upload).
	 * The temp file is read in place; it is never moved into a web-accessible folder.
	 */
	public static function run_file( $path, $name, array $defaults ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'clib_import', __( 'Could not read the file.', 'church-library' ) );
		}

		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'clib_import', __( 'The file is empty.', 'church-library' ) );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$map       = self::header_map();
		$cols      = array();
		foreach ( $header as $idx => $h ) {
			$key = self::norm_header( $h );
			if ( isset( $map[ $key ] ) && ! in_array( $map[ $key ], $cols, true ) ) {
				$cols[ $idx ] = $map[ $key ];
			}
		}
		// A Title column is required, except for update-only files keyed by Item ID.
		if ( ! in_array( 'title', $cols, true ) && ! ( ! empty( $defaults['update_existing'] ) && in_array( 'item_code', $cols, true ) ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'clib_import', __( 'The CSV needs a "Title" column (or an "Item ID" column with "Update items whose Item ID already exists" ticked).', 'church-library' ) );
		}

		$summary = array(
			'rows'     => 0,
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'dry_run'  => ! empty( $defaults['dry_run'] ),
			'columns'  => array_values( $cols ),
		);

		$line                = 1;
		CLIB_Items::$quiet   = true;
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( $summary['rows'] >= self::MAX_ROWS ) {
				/* translators: %d: max rows */
				$summary['errors'][] = sprintf( __( 'Stopped after %d rows.', 'church-library' ), self::MAX_ROWS );
				break;
			}
			if ( ! is_array( $row ) || ( 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) ) ) ) {
				continue;
			}
			++$summary['rows'];

			$raw = array();
			foreach ( $cols as $idx => $fieldname ) {
				$raw[ $fieldname ] = isset( $row[ $idx ] ) ? self::unneutralise( self::to_utf8( $row[ $idx ] ) ) : '';
			}
			$result = self::import_row( $raw, $defaults );
			if ( is_wp_error( $result ) ) {
				++$summary['skipped'];
				if ( count( $summary['errors'] ) < 50 ) {
					/* translators: 1: line number, 2: error */
					$summary['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'church-library' ), $line, $result->get_error_message() );
				}
			} elseif ( 'dupe' === $result ) {
				++$summary['skipped'];
			} elseif ( 'updated' === $result ) {
				++$summary['updated'];
			} else {
				++$summary['imported'];
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		CLIB_Items::$quiet = false;

		if ( ! $summary['dry_run'] ) {
			CLIB_Audit::log( 'import', 'item', 0, sprintf( '%s: %d imported, %d updated, %d skipped', sanitize_file_name( $name ), $summary['imported'], $summary['updated'], $summary['skipped'] ) );
		}
		return $summary;
	}

	/** @return int|string|WP_Error item id, 'dupe', 'updated' or error. */
	private static function import_row( array $raw, array $defaults ) {
		global $wpdb;

		// Update mode: a row whose Item ID already exists updates that item. Columns
		// missing from the file keep their current values.
		$existing = null;
		if ( ! empty( $defaults['update_existing'] ) && ! empty( $raw['item_code'] ) ) {
			$code = CLIB_Util::token( trim( $raw['item_code'] ), 32 );
			if ( '' !== $code ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CLIB_DB::table( 'items' ) . ' WHERE item_code = %s', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		// The validators unslash, so start from slashed values.
		$in = array();
		if ( $existing ) {
			foreach ( (array) $existing as $k => $v ) {
				$in[ $k ] = wp_slash( null === $v ? '' : (string) $v );
			}
		}

		$media = $existing ? (int) $existing->media_type_id : (int) $defaults['media_type_id'];
		if ( isset( $raw['media_type'] ) && '' !== trim( $raw['media_type'] ) ) {
			$m = CLIB_LOV::find_by_label( 'media_type', CLIB_Util::cap( trim( $raw['media_type'] ), 100 ) );
			if ( ! $m ) {
				return self::unknown( __( 'Media Type', 'church-library' ), $raw['media_type'] );
			}
			$media = (int) $m->id;
		}
		$in['media_type_id'] = $media;

		$church = $existing ? (int) $existing->church_id : (int) $defaults['church_id'];
		if ( isset( $raw['church'] ) ) {
			$label = CLIB_Util::cap( trim( $raw['church'] ), 100 );
			if ( '' === $label ) {
				$church = $existing ? 0 : $church;
			} else {
				$c = CLIB_LOV::find_by_label( 'church', $label );
				if ( ! $c ) {
					return self::unknown( __( 'Church Name', 'church-library' ), $label );
				}
				$church = (int) $c->id;
			}
		}
		$in['church_id'] = $church;

		$building = $existing ? (int) $existing->building_id : (int) $defaults['building_id'];
		if ( isset( $raw['building'] ) ) {
			$label = CLIB_Util::cap( trim( $raw['building'] ), 100 );
			if ( '' === $label ) {
				$building = $existing ? 0 : $building;
			} else {
				$b = self::find_building( $label, $church );
				if ( ! $b ) {
					return self::unknown( __( 'Building', 'church-library' ), $label );
				}
				$building = (int) $b->id;
			}
		}
		$in['building_id'] = $building;

		if ( isset( $raw['subcategory'] ) ) {
			$label                = CLIB_Util::cap( trim( $raw['subcategory'] ), 100 );
			$sc = '' === $label ? null : CLIB_LOV::find_by_label( 'music_subcategory', $label );
			if ( '' !== $label && ! $sc ) {
				return self::unknown( __( 'Music Sub-category', 'church-library' ), $label );
			}
			$in['subcategory_id'] = $sc ? (int) $sc->id : 0;
		}

		if ( isset( $raw['classification'] ) ) {
			$label = CLIB_Util::text( wp_slash( $raw['classification'] ), 100 );
			$cl    = '' === $label ? null : CLIB_LOV::find_by_label( 'classification', $label );
			if ( ! $cl && ! empty( $defaults['add_classes'] ) && '' !== $label && empty( $defaults['dry_run'] ) ) {
				$in['classification_id'] = CLIB_LOV::insert(
					array(
						'list_type'  => 'classification',
						'label'      => $label,
						'sort_order' => 1000,
					)
				);
			} elseif ( $cl ) {
				$in['classification_id'] = (int) $cl->id;
			} elseif ( '' === $label ) {
				$in['classification_id'] = 0;
			}
		}

		if ( isset( $raw['item_condition'] ) ) {
			$in['item_condition'] = self::key_from_label( $raw['item_condition'], CLIB_Util::condition_labels() );
			if ( '' === $in['item_condition'] && '' !== trim( $raw['item_condition'] ) ) {
				return self::unknown( __( 'Condition', 'church-library' ), $raw['item_condition'] );
			}
		}
		if ( isset( $raw['difficulty'] ) ) {
			$in['difficulty'] = self::key_from_label( $raw['difficulty'], CLIB_Util::difficulty_labels() );
			if ( '' === $in['difficulty'] && '' !== trim( $raw['difficulty'] ) ) {
				return self::unknown( __( 'Difficulty Level', 'church-library' ), $raw['difficulty'] );
			}
		}
		// Music-only columns on a non-music row would be silently dropped: say so instead.
		if ( ! CLIB_LOV::is_music_type( $media ) && ( ( isset( $raw['subcategory'] ) && '' !== trim( $raw['subcategory'] ) ) || ( isset( $raw['difficulty'] ) && '' !== trim( $raw['difficulty'] ) ) ) ) {
			return new WP_Error( 'clib_import', __( 'Music Sub-category and Difficulty Level only apply to music media types. Set Media Type to a music type or clear those cells.', 'church-library' ) );
		}
		if ( isset( $raw['storage_status'] ) ) {
			// Checked In / Checked Out follow loans; only "Not Available" is set by hand.
			$in['storage_status'] = 'not_available' === self::key_from_label( $raw['storage_status'], CLIB_Util::status_labels() ) ? 'not_available' : 'available';
		}
		if ( isset( $raw['acquired_date'] ) ) {
			$ts                  = '' === trim( $raw['acquired_date'] ) ? false : strtotime( $raw['acquired_date'] );
			$in['acquired_date'] = $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}

		if ( isset( $raw['room'] ) && '' !== trim( $raw['room'] ) && ! CLIB_LOV::find_room( CLIB_Util::cap( trim( $raw['room'] ), 100 ), $building ) ) {
			return self::unknown( __( 'Room', 'church-library' ), $raw['room'] );
		}
		foreach ( array( 'title', 'creator', 'publisher', 'description', 'notes', 'barcode', 'call_number', 'identifier', 'room', 'location_code', 'copies', 'owned_by', 'liturgical_use', 'inventory_notes', 'cost' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$in[ $k ] = wp_slash( $raw[ $k ] );
			}
		}

		$data = CLIB_Items::validate( $in );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $existing ) {
			if ( ! empty( $defaults['dry_run'] ) ) {
				return 'updated';
			}
			return CLIB_Items::update( (int) $existing->id, $data ) ? 'updated' : new WP_Error( 'clib_import', __( 'Could not update the item.', 'church-library' ) );
		}

		if ( ! empty( $defaults['skip_dupes'] ) ) {
			$t = CLIB_DB::table( 'items' );
			if ( '' !== $data['barcode'] ) {
				$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE barcode = %s LIMIT 1", $data['barcode'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE title = %s AND creator = %s LIMIT 1", $data['title'], $data['creator'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $dupe ) {
				return 'dupe';
			}
		}

		if ( ! empty( $defaults['dry_run'] ) ) {
			return 0;
		}
		return CLIB_Items::create( $data );
	}
}
