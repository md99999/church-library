<?php
/**
 * Reports. Each report returns one or more sections of
 * [ 'title' => string, 'columns' => [key => label], 'rows' => [ [key => value] ] ]
 * which are rendered as HTML tables or exported as CSV.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Reports {

	public static function reports() {
		return array(
			'summary'     => __( 'Collection Summary', 'church-library' ),
			'checked_out' => __( 'Currently Checked Out', 'church-library' ),
			'overdue'     => __( 'Overdue', 'church-library' ),
			'history'     => __( 'Circulation History', 'church-library' ),
			'inventory'   => __( 'Inventory / Shelf List', 'church-library' ),
		);
	}

	/** Parse and sanitize report filters from a request array. */
	public static function args( array $src ) {
		$today = current_time( 'Y-m-d' );
		$from  = CLIB_Util::date( isset( $src['from'] ) ? $src['from'] : '' );
		$to    = CLIB_Util::date( isset( $src['to'] ) ? $src['to'] : '' );
		return array(
			'from'          => $from ? $from : ( new DateTime( $today ) )->modify( '-90 days' )->format( 'Y-m-d' ),
			'to'            => $to ? $to : $today,
			'church_id'     => CLIB_Util::int( isset( $src['church_id'] ) ? $src['church_id'] : 0 ),
			'building_id'   => CLIB_Util::int( isset( $src['building_id'] ) ? $src['building_id'] : 0 ),
			'media_type_id' => CLIB_Util::int( isset( $src['media_type_id'] ) ? $src['media_type_id'] : 0 ),
			'status'        => CLIB_Util::enum( isset( $src['status'] ) ? $src['status'] : '', CLIB_Util::STATUSES, '' ),
		);
	}

	public static function run( $key, array $args ) {
		switch ( $key ) {
			case 'summary':
				return self::summary();
			case 'checked_out':
				return array( self::loans_section( __( 'Currently Checked Out', 'church-library' ), CLIB_Loans::active( false ) ) );
			case 'overdue':
				return array( self::loans_section( __( 'Overdue', 'church-library' ), CLIB_Loans::active( true ) ) );
			case 'history':
				return array( self::history( $args ) );
			case 'inventory':
				return array( self::inventory( $args ) );
		}
		return array();
	}

	private static function summary() {
		global $wpdb;
		$items    = CLIB_DB::table( 'items' );
		$lov      = CLIB_DB::table( 'lov' );
		$sections = array();
		$cols     = array(
			'label'  => __( 'Value', 'church-library' ),
			'items'  => __( 'Items', 'church-library' ),
			'copies' => __( 'Copies', 'church-library' ),
		);

		$groups = array(
			'media_type_id' => __( 'By Media Type', 'church-library' ),
			'church_id'     => __( 'By Church', 'church-library' ),
			'building_id'   => __( 'By Building', 'church-library' ),
		);
		foreach ( $groups as $col => $title ) {
			// $col is from the fixed array above.
			$rows = $wpdb->get_results( "SELECT COALESCE(l.label, '(none)') AS label, COUNT(*) AS items, SUM(i.copies) AS copies FROM $items i LEFT JOIN $lov l ON l.id = i.$col GROUP BY l.label ORDER BY items DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sections[] = array(
				'title'   => $title,
				'columns' => $cols,
				'rows'    => $rows,
			);
		}

		foreach ( array(
			'storage_status' => array( __( 'By Status', 'church-library' ), CLIB_Util::status_labels() ),
			'item_condition' => array( __( 'By Condition', 'church-library' ), CLIB_Util::condition_labels() ),
		) as $col => $def ) {
			$rows = $wpdb->get_results( "SELECT $col AS label, COUNT(*) AS items, SUM(copies) AS copies FROM $items GROUP BY $col ORDER BY items DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as &$r ) {
				$r['label'] = '' === $r['label'] ? __( '(none)', 'church-library' ) : CLIB_Util::label( $def[1], $r['label'] );
			}
			unset( $r );
			$sections[] = array(
				'title'   => $def[0],
				'columns' => $cols,
				'rows'    => $rows,
			);
		}

		$value      = $wpdb->get_row( "SELECT COUNT(*) AS items, SUM(copies) AS copies, SUM(cost * copies) AS value FROM $items", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sections[] = array(
			'title'   => __( 'Totals', 'church-library' ),
			'columns' => array(
				'items'  => __( 'Items', 'church-library' ),
				'copies' => __( 'Copies', 'church-library' ),
				'value'  => __( 'Recorded cost', 'church-library' ),
			),
			'rows'    => array(
				array(
					'items'  => (int) $value['items'],
					'copies' => (int) $value['copies'],
					'value'  => number_format_i18n( (float) $value['value'], 2 ),
				),
			),
		);
		return $sections;
	}

	private static function loans_section( $title, $loans ) {
		$today = current_time( 'Y-m-d' );
		$rows  = array();
		foreach ( $loans as $l ) {
			$rows[] = array(
				'item_code'   => $l->item_code,
				'title'       => $l->title,
				'church'      => $l->church_label,
				'borrower'    => $l->borrower_name,
				'contact'     => $l->borrower_contact,
				'out'         => CLIB_Util::fmt_date( $l->checked_out_at ),
				'due'         => CLIB_Util::fmt_date( $l->due_date ),
				'days_over'   => ( $l->due_date && $l->due_date < $today ) ? (int) ( ( strtotime( $today ) - strtotime( $l->due_date ) ) / DAY_IN_SECONDS ) : '',
				'_loan_id'    => (int) $l->id,
				'_item_id'    => (int) $l->item_id,
			);
		}
		return array(
			'title'   => $title,
			'columns' => array(
				'item_code' => __( 'Item ID', 'church-library' ),
				'title'     => __( 'Title', 'church-library' ),
				'church'    => __( 'Church', 'church-library' ),
				'borrower'  => __( 'Checked Out By', 'church-library' ),
				'contact'   => __( 'Contact', 'church-library' ),
				'out'       => __( 'Checked Out', 'church-library' ),
				'due'       => __( 'Due', 'church-library' ),
				'days_over' => __( 'Days Overdue', 'church-library' ),
			),
			'rows'    => $rows,
		);
	}

	private static function history( array $args ) {
		global $wpdb;
		$l    = CLIB_DB::table( 'loans' );
		$i    = CLIB_DB::table( 'items' );
		$res  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, i.item_code, i.title FROM $l l JOIN $i i ON i.id = l.item_id WHERE l.checked_out_at >= %s AND l.checked_out_at < %s ORDER BY l.checked_out_at DESC LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args['from'] . ' 00:00:00',
				( new DateTime( $args['to'] ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00'
			)
		);
		$rows = array();
		foreach ( $res as $r ) {
			$rows[] = array(
				'item_code' => $r->item_code,
				'title'     => $r->title,
				'borrower'  => $r->borrower_name,
				'out'       => CLIB_Util::fmt_date( $r->checked_out_at ),
				'due'       => CLIB_Util::fmt_date( $r->due_date ),
				'returned'  => $r->returned_at ? CLIB_Util::fmt_date( $r->returned_at ) : __( 'Still out', 'church-library' ),
				'condition' => CLIB_Util::label( CLIB_Util::condition_labels(), $r->return_condition ),
			);
		}
		return array(
			/* translators: 1: from date, 2: to date */
			'title'   => sprintf( __( 'Circulation %1$s to %2$s', 'church-library' ), $args['from'], $args['to'] ),
			'columns' => array(
				'item_code' => __( 'Item ID', 'church-library' ),
				'title'     => __( 'Title', 'church-library' ),
				'borrower'  => __( 'Borrower', 'church-library' ),
				'out'       => __( 'Checked Out', 'church-library' ),
				'due'       => __( 'Due', 'church-library' ),
				'returned'  => __( 'Returned', 'church-library' ),
				'condition' => __( 'Return Condition', 'church-library' ),
			),
			'rows'    => $rows,
		);
	}

	private static function inventory( array $args ) {
		list( $items ) = CLIB_Items::search(
			array(
				'church_id'     => $args['church_id'],
				'building_id'   => $args['building_id'],
				'media_type_id' => $args['media_type_id'],
				'status'        => $args['status'],
				'orderby'       => 'location',
				'per_page'      => 500,
			)
		);
		$status = CLIB_Util::status_labels();
		$cond   = CLIB_Util::condition_labels();
		$rows   = array();
		foreach ( $items as $it ) {
			$rows[] = array(
				'item_code' => $it->item_code,
				'title'     => $it->title,
				'creator'   => $it->creator,
				'media'     => $it->media_type_label,
				'church'    => $it->church_label,
				'building'  => $it->building_label,
				'room'      => $it->room,
				'location'  => $it->location_code,
				'call'      => $it->call_number,
				'copies'    => (int) $it->copies,
				'status'    => CLIB_Util::label( $status, $it->storage_status ),
				'condition' => CLIB_Util::label( $cond, $it->item_condition ),
			);
		}
		return array(
			'title'   => __( 'Inventory / Shelf List (first 500, sorted by location)', 'church-library' ),
			'columns' => array(
				'item_code' => __( 'Item ID', 'church-library' ),
				'title'     => __( 'Title', 'church-library' ),
				'creator'   => __( 'Creator/Author', 'church-library' ),
				'media'     => __( 'Media Type', 'church-library' ),
				'church'    => __( 'Church', 'church-library' ),
				'building'  => __( 'Building', 'church-library' ),
				'room'      => __( 'Room', 'church-library' ),
				'location'  => __( 'Location ID', 'church-library' ),
				'call'      => __( 'Call #', 'church-library' ),
				'copies'    => __( 'Copies', 'church-library' ),
				'status'    => __( 'Status', 'church-library' ),
				'condition' => __( 'Condition', 'church-library' ),
			),
			'rows'    => $rows,
		);
	}

	/** Stream sections as CSV (formula-injection safe). */
	public static function send_csv( $key, array $sections ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="library-' . sanitize_file_name( $key ) . '-' . current_time( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		foreach ( $sections as $n => $s ) {
			if ( count( $sections ) > 1 ) {
				if ( $n > 0 ) {
					fputcsv( $out, array(), ',', '"', '\\' );
				}
				fputcsv( $out, array( CLIB_Util::csv_safe( $s['title'] ) ), ',', '"', '\\' );
			}
			fputcsv( $out, array_map( array( 'CLIB_Util', 'csv_safe' ), array_values( $s['columns'] ) ), ',', '"', '\\' );
			foreach ( $s['rows'] as $row ) {
				$line = array();
				foreach ( array_keys( $s['columns'] ) as $col ) {
					$line[] = CLIB_Util::csv_safe( isset( $row[ $col ] ) ? $row[ $col ] : '' );
				}
				fputcsv( $out, $line, ',', '"', '\\' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
