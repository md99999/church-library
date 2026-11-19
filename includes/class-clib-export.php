<?php
/**
 * Full catalog CSV export. The headings match what CLIB_Import recognises,
 * so an exported file can be edited in Excel and imported back (with
 * "Update items whose Item ID already exists" ticked).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Export {

	const MAX_ITEMS = 100000;

	/** Heading => callable( $item ) returning the cell value. */
	public static function columns() {
		$lov  = array( 'CLIB_LOV', 'label' );
		$cond = CLIB_Util::condition_labels();
		$diff = CLIB_Util::difficulty_labels();
		$stat = CLIB_Util::status_labels();
		return array(
			'Item ID'            => function ( $i ) { return $i->item_code; },
			'Title'              => function ( $i ) { return $i->title; },
			'Creator/Author'     => function ( $i ) { return $i->creator; },
			'Publisher'          => function ( $i ) { return $i->publisher; },
			'Media Type'         => function ( $i ) use ( $lov ) { return call_user_func( $lov, $i->media_type_id ); },
			'Music Sub-category' => function ( $i ) use ( $lov ) { return call_user_func( $lov, $i->subcategory_id ); },
			'Difficulty Level'   => function ( $i ) use ( $diff ) { return CLIB_Util::label( $diff, $i->difficulty ); },
			'Classification'     => function ( $i ) use ( $lov ) { return call_user_func( $lov, $i->classification_id ); },
			'Liturgical Use'     => function ( $i ) { return $i->liturgical_use; },
			'Condition'          => function ( $i ) use ( $cond ) { return CLIB_Util::label( $cond, $i->item_condition ); },
			'ISBN or Identifier' => function ( $i ) { return $i->identifier; },
			'Barcode'            => function ( $i ) { return $i->barcode; },
			'Call #'             => function ( $i ) { return $i->call_number; },
			'Owned By'           => function ( $i ) { return $i->owned_by; },
			'Church Name'        => function ( $i ) use ( $lov ) { return call_user_func( $lov, $i->church_id ); },
			'Building'           => function ( $i ) use ( $lov ) { return call_user_func( $lov, $i->building_id ); },
			'Room'               => function ( $i ) { return $i->room; },
			'Location ID'        => function ( $i ) { return $i->location_code; },
			'Number of Copies'   => function ( $i ) { return (int) $i->copies; },
			'Storage Status'     => function ( $i ) use ( $stat ) { return CLIB_Util::label( $stat, $i->storage_status ); },
			'Cost'               => function ( $i ) { return null === $i->cost ? '' : $i->cost; },
			'Acquired Date'      => function ( $i ) { return $i->acquired_date ? $i->acquired_date : ''; },
			'Description'        => function ( $i ) { return $i->description; },
			'Notes'              => function ( $i ) { return $i->notes; },
			'Inventory Notes'    => function ( $i ) { return $i->inventory_notes; },
		);
	}

	/** Sanitized filters from a request array. */
	public static function filters( array $src ) {
		return array(
			'media_type_id' => CLIB_Util::int( isset( $src['media_type_id'] ) ? $src['media_type_id'] : 0 ),
			'church_id'     => CLIB_Util::int( isset( $src['church_id'] ) ? $src['church_id'] : 0 ),
			'building_id'   => CLIB_Util::int( isset( $src['building_id'] ) ? $src['building_id'] : 0 ),
			'status'        => CLIB_Util::enum( isset( $src['status'] ) ? $src['status'] : '', CLIB_Util::STATUSES, '' ),
			'music_only'    => ! empty( $src['music_only'] ),
		);
	}

	/** Ids of items to export. "Music only" covers every media type flagged as music. */
	private static function ids( array $filters ) {
		$music = ! empty( $filters['music_only'] );
		unset( $filters['music_only'] );
		if ( ! $music ) {
			return CLIB_Items::search( array_merge( $filters, array( 'ids_only' => self::MAX_ITEMS ) ) );
		}
		$ids = array();
		foreach ( CLIB_LOV::all( 'media_type' ) as $mt ) {
			if ( (int) $mt->is_music && ( empty( $filters['media_type_id'] ) || (int) $filters['media_type_id'] === (int) $mt->id ) ) {
				$ids = array_merge( $ids, CLIB_Items::search( array_merge( $filters, array( 'media_type_id' => (int) $mt->id, 'ids_only' => self::MAX_ITEMS ) ) ) );
			}
		}
		sort( $ids );
		return $ids;
	}

	/** Columns in the blank music template. */
	public static function music_template_columns() {
		return array( 'Title', 'Creator/Author', 'Publisher', 'Media Type', 'Music Sub-category', 'Difficulty Level', 'Classification', 'Liturgical Use', 'Condition', 'ISBN or Identifier', 'Church Name', 'Building', 'Room', 'Location ID', 'Number of Copies', 'Owned By', 'Notes' );
	}

	/** Stream a header-only CSV template for music items. */
	public static function send_music_template() {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="library-music-template.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, self::music_template_columns(), ',', '"', '\\' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Stream the CSV and exit. Every cell is formula-neutralised. */
	public static function send( array $filters ) {
		global $wpdb;
		$ids = self::ids( $filters );
		$t   = CLIB_DB::table( 'items' );
		$col = self::columns();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="library-' . ( ! empty( $filters['music_only'] ) ? 'music' : 'catalog' ) . '-' . current_time( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads accents.
		fputcsv( $out, array_keys( $col ), ',', '"', '\\' );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql   = "SELECT * FROM $t WHERE id IN (" . CLIB_Util::in_placeholders( $chunk ) . ') ORDER BY item_code';
			$items = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $items as $item ) {
				$line = array();
				foreach ( $col as $fn ) {
					$line[] = CLIB_Util::csv_safe( (string) call_user_func( $fn, $item ) );
				}
				fputcsv( $out, $line, ',', '"', '\\' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		CLIB_Audit::log( 'catalog_export', 'item', 0, sprintf( '%d items', count( $ids ) ) );
		exit;
	}
}
