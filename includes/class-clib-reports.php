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
			'checklist'   => __( 'Inventory Checklist', 'church-library' ),
			'missing'     => __( 'Missing Data Audit', 'church-library' ),
		);
	}

	/** Most rows a single checklist or audit run returns (keeps memory and page size bounded). */
	const MAX_ROWS = 5000;

	/** Request keys carried between the filter form, the CSV export and the print view. */
	const FILTER_KEYS = array( 'from', 'to', 'church_id', 'building_id', 'room', 'media_type_id', 'status', 'sort_by', 'page_break', 'missing' );

	/** Reports that show the item filter bar (church / building / media type ...). */
	const ITEM_FILTER_REPORTS = array( 'inventory', 'checklist', 'missing' );

	/** Inventory checklist sort orders: key => label. The SQL lives in checklist_order(). */
	public static function checklist_sorts() {
		return array(
			'location'  => __( 'Location (building, room, cat #)', 'church-library' ),
			'call'      => __( 'Cat #', 'church-library' ),
			'title'     => __( 'Title', 'church-library' ),
			'item_code' => __( 'Item ID', 'church-library' ),
		);
	}

	/**
	 * Fields the Missing Data Audit checks: key => [ label, SQL test that is true when missing ].
	 * The SQL fragments are fixed strings (no request data ever reaches them) and use the
	 * aliases from missing_from(): i = items, p = attachment post, cl/ch/bl = list values.
	 */
	private static function missing_fields() {
		return array(
			'image'          => array( __( 'Image', 'church-library' ), 'p.ID IS NULL' ),
			'creator'        => array( __( 'Creator/Author', 'church-library' ), "TRIM(i.creator) = ''" ),
			'publisher'      => array( __( 'Publisher', 'church-library' ), "TRIM(i.publisher) = ''" ),
			'identifier'     => array( __( 'ISBN or Identifier', 'church-library' ), "TRIM(i.identifier) = ''" ),
			'description'    => array( __( 'Description', 'church-library' ), "(i.description IS NULL OR TRIM(i.description) = '')" ),
			'classification' => array( __( 'Classification', 'church-library' ), 'cl.id IS NULL' ),
			'church'         => array( __( 'Church Name', 'church-library' ), 'ch.id IS NULL' ),
			'building'       => array( __( 'Building', 'church-library' ), 'bl.id IS NULL' ),
			'room'           => array( __( 'Room', 'church-library' ), "TRIM(i.room) = ''" ),
			'call_number'    => array( __( 'Cat #', 'church-library' ), "TRIM(i.call_number) = ''" ),
			'barcode'        => array( __( 'Barcode', 'church-library' ), "TRIM(i.barcode) = ''" ),
			'condition'      => array( __( 'Condition', 'church-library' ), "i.item_condition = ''" ),
		);
	}

	/** Missing Data Audit field choices for the filter: key => label. */
	public static function missing_choices() {
		return wp_list_pluck( self::missing_fields(), 0 );
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
			'room'          => CLIB_Util::text( isset( $src['room'] ) ? $src['room'] : '', 100 ),
			'media_type_id' => CLIB_Util::int( isset( $src['media_type_id'] ) ? $src['media_type_id'] : 0 ),
			'status'        => CLIB_Util::enum( isset( $src['status'] ) ? $src['status'] : '', CLIB_Util::STATUSES, '' ),
			'sort_by'       => CLIB_Util::enum( isset( $src['sort_by'] ) ? $src['sort_by'] : '', array_keys( self::checklist_sorts() ), 'location' ),
			'page_break'    => isset( $src['page_break'] ) && is_scalar( $src['page_break'] ) && '1' === (string) $src['page_break'] ? 1 : 0,
			'missing'       => CLIB_Util::enum( isset( $src['missing'] ) ? $src['missing'] : '', array_keys( self::missing_fields() ), '' ),
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
			case 'checklist':
				return self::checklist( $args );
			case 'missing':
				return self::missing( $args );
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
			$rows = $wpdb->get_results( "SELECT COALESCE(l.label, '(none)') AS label, COUNT(*) AS items, SUM(i.copies) AS copies FROM $items i LEFT JOIN $lov l ON l.id = i.$col WHERE i.is_archived = 0 GROUP BY l.label ORDER BY items DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			$rows = $wpdb->get_results( "SELECT $col AS label, COUNT(*) AS items, SUM(copies) AS copies FROM $items WHERE is_archived = 0 GROUP BY $col ORDER BY items DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		$value      = $wpdb->get_row( "SELECT COUNT(*) AS items, SUM(copies) AS copies, SUM(cost * copies) AS value FROM $items WHERE is_archived = 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	/**
	 * Borrower email and contact are personal data: only staff who run
	 * circulation see them. Other report viewers get the columns removed.
	 */
	private static function limit_pii( array $section ) {
		if ( current_user_can( 'clib_circulate' ) ) {
			return $section;
		}
		foreach ( array( 'email', 'contact' ) as $k ) {
			unset( $section['columns'][ $k ] );
			foreach ( $section['rows'] as $i => $row ) {
				unset( $section['rows'][ $i ][ $k ] );
			}
		}
		return $section;
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
				'email'       => $l->borrower_email,
				'contact'     => $l->borrower_contact,
				'out'         => CLIB_Util::fmt_date( $l->checked_out_at ),
				'due'         => CLIB_Util::fmt_date( $l->due_date ),
				'days_over'   => ( $l->due_date && $l->due_date < $today ) ? (int) ( ( strtotime( $today ) - strtotime( $l->due_date ) ) / DAY_IN_SECONDS ) : '',
				'_loan_id'    => (int) $l->id,
				'_item_id'    => (int) $l->item_id,
			);
		}
		return self::limit_pii(
			array(
			'title'   => $title,
			'columns' => array(
				'item_code' => __( 'Item ID', 'church-library' ),
				'title'     => __( 'Title', 'church-library' ),
				'church'    => __( 'Church', 'church-library' ),
				'borrower'  => __( 'Checked Out By', 'church-library' ),
				'email'     => __( 'Email', 'church-library' ),
				'contact'   => __( 'Contact', 'church-library' ),
				'out'       => __( 'Checked Out', 'church-library' ),
				'due'       => __( 'Due', 'church-library' ),
				'days_over' => __( 'Days Overdue', 'church-library' ),
			),
			'rows'    => $rows,
			)
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
				'email'     => $r->borrower_email,
				'out'       => CLIB_Util::fmt_date( $r->checked_out_at ),
				'due'       => CLIB_Util::fmt_date( $r->due_date ),
				'returned'  => $r->returned_at ? CLIB_Util::fmt_date( $r->returned_at ) : __( 'Still out', 'church-library' ),
				'condition' => CLIB_Util::label( CLIB_Util::condition_labels(), $r->return_condition ),
			);
		}
		return self::limit_pii(
			array(
			/* translators: 1: from date, 2: to date */
			'title'   => sprintf( __( 'Circulation %1$s to %2$s', 'church-library' ), $args['from'], $args['to'] ),
			'columns' => array(
				'item_code' => __( 'Item ID', 'church-library' ),
				'title'     => __( 'Title', 'church-library' ),
				'borrower'  => __( 'Borrower', 'church-library' ),
				'email'     => __( 'Email', 'church-library' ),
				'out'       => __( 'Checked Out', 'church-library' ),
				'due'       => __( 'Due', 'church-library' ),
				'returned'  => __( 'Returned', 'church-library' ),
				'condition' => __( 'Return Condition', 'church-library' ),
			),
			'rows'    => $rows,
			)
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
				'call'      => __( 'Cat #', 'church-library' ),
				'copies'    => __( 'Copies', 'church-library' ),
				'status'    => __( 'Status', 'church-library' ),
				'condition' => __( 'Condition', 'church-library' ),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Shared WHERE clause for the item filters (church, building, room, media type, status).
	 * Archived items are always excluded. Returns [ sql, values ] for $wpdb->prepare().
	 */
	private static function item_where( array $args, $use_status = true ) {
		$where = array( 'i.is_archived = 0' );
		$vals  = array();
		foreach ( array( 'church_id', 'building_id', 'media_type_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "i.$col = %d"; // Column names come from the fixed list above.
				$vals[]  = (int) $args[ $col ];
			}
		}
		if ( '' !== $args['room'] ) {
			$where[] = 'i.room = %s';
			$vals[]  = $args['room'];
		}
		if ( $use_status && in_array( $args['status'], CLIB_Util::STATUSES, true ) ) {
			$where[] = 'i.storage_status = %s';
			$vals[]  = $args['status'];
		}
		return array( implode( ' AND ', $where ), $vals );
	}

	/** Run a SELECT with optional placeholders. $sql only ever contains fixed SQL and %-placeholders. */
	private static function query( $sql, array $vals ) {
		global $wpdb;
		$prepared = $vals ? $wpdb->prepare( $sql, $vals ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			CLIB_Audit::log( 'report_error', 'report', 0, CLIB_Util::cap( $wpdb->last_error, 200 ) );
			return null;
		}
		return is_array( $rows ) ? $rows : array();
	}

	/** Human-readable list of the active filters, for the printed header. */
	private static function filter_summary( array $args, $use_status = true ) {
		$parts = array();
		$named = array(
			'church_id'     => __( 'Church', 'church-library' ),
			'building_id'   => __( 'Building', 'church-library' ),
			'media_type_id' => __( 'Media Type', 'church-library' ),
		);
		foreach ( $named as $k => $label ) {
			if ( ! empty( $args[ $k ] ) ) {
				$parts[] = $label . ': ' . CLIB_LOV::label( (int) $args[ $k ] );
			}
		}
		if ( '' !== $args['room'] ) {
			$parts[] = __( 'Room', 'church-library' ) . ': ' . $args['room'];
		}
		if ( $use_status && '' !== $args['status'] ) {
			$parts[] = __( 'Status', 'church-library' ) . ': ' . CLIB_Util::label( CLIB_Util::status_labels(), $args['status'] );
		}
		return $parts ? implode( ' | ', $parts ) : __( 'All items', 'church-library' );
	}

	/** Section returned when a query fails: the cause goes to the audit log, not the screen. */
	private static function error_section( $title ) {
		return array(
			'title'   => $title,
			'note'    => __( 'The report could not be run. The error was recorded in the Audit Log.', 'church-library' ),
			'columns' => array( 'msg' => '' ),
			'rows'    => array(),
		);
	}

	/** ORDER BY for the checklist. Only fixed strings come back from here. */
	private static function checklist_order( $sort ) {
		$call = "(i.call_number = ''), i.call_number, i.title";
		switch ( $sort ) {
			case 'call':
				return "$call, i.id";
			case 'title':
				return 'i.title, i.id';
			case 'item_code':
				return 'i.item_code';
			default:
				return "(bl.label IS NULL), bl.label, (i.room = ''), i.room, $call, i.id";
		}
	}

	/**
	 * Inventory Checklist: one row per item with an empty "Found" box to tick on paper.
	 * With "new page for each room" the rows are split into one section per building/room,
	 * and each section starts on a new printed page.
	 */
	private static function checklist( array $args ) {
		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$title = __( 'Inventory Checklist', 'church-library' );
		list( $wsql, $vals ) = self::item_where( $args );
		$order  = self::checklist_order( $args['sort_by'] );
		$vals[] = self::MAX_ROWS + 1;
		$res    = self::query(
			"SELECT i.id, i.item_code, i.title, i.creator, i.call_number, i.room, i.copies, i.barcode,
				mt.label AS media_type_label, bl.label AS building_label
			FROM $items i
			LEFT JOIN $lov mt ON mt.id = i.media_type_id
			LEFT JOIN $lov bl ON bl.id = i.building_id
			WHERE $wsql ORDER BY $order LIMIT %d",
			$vals
		);
		if ( null === $res ) {
			return array( self::error_section( $title ) );
		}
		$truncated = count( $res ) > self::MAX_ROWS;
		if ( $truncated ) {
			$res = array_slice( $res, 0, self::MAX_ROWS );
		}

		$columns = array(
			'item_code' => __( 'Item ID', 'church-library' ),
			'title'     => __( 'Title', 'church-library' ),
			'creator'   => __( 'Creator/Author', 'church-library' ),
			'media'     => __( 'Media Type', 'church-library' ),
			'call'      => __( 'Cat #', 'church-library' ),
			'building'  => __( 'Building', 'church-library' ),
			'room'      => __( 'Room', 'church-library' ),
			'copies'    => __( 'Copies', 'church-library' ),
			'barcode'   => __( 'Barcode', 'church-library' ),
			'found'     => __( 'Found', 'church-library' ),
		);

		$groups = array();
		$copies = 0;
		foreach ( $res as $it ) {
			$row     = array(
				'item_code' => $it->item_code,
				'title'     => $it->title,
				'creator'   => $it->creator,
				'media'     => $it->media_type_label,
				'call'      => $it->call_number,
				'building'  => $it->building_label,
				'room'      => $it->room,
				'copies'    => (int) $it->copies,
				'barcode'   => $it->barcode,
				'found'     => '',
				'_item_id'  => (int) $it->id,
			);
			$copies += (int) $it->copies;
			$gkey    = '';
			if ( $args['page_break'] ) {
				$gkey = ( null === $it->building_label || '' === $it->building_label ? __( '(no building)', 'church-library' ) : $it->building_label )
					. ' / ' . ( '' === $it->room ? __( '(no room)', 'church-library' ) : $it->room );
			}
			$groups[ $gkey ][] = $row;
		}
		if ( ! $groups ) {
			$groups[''] = array();
		}

		/* translators: 1: number of items, 2: number of copies, 3: date printed */
		$counts = sprintf( __( '%1$s items, %2$s copies. Printed %3$s.', 'church-library' ), number_format_i18n( count( $res ) ), number_format_i18n( $copies ), CLIB_Util::fmt_date( current_time( 'Y-m-d' ) ) );
		$note   = self::filter_summary( $args ) . '. ' . $counts;
		if ( $truncated ) {
			/* translators: %s: maximum number of rows */
			$note .= ' ' . sprintf( __( 'Only the first %s items are shown. Narrow the filters to see the rest.', 'church-library' ), number_format_i18n( self::MAX_ROWS ) );
		}

		$sections = array();
		$first    = true;
		foreach ( $groups as $gkey => $rows ) {
			$sec_copies = array_sum( wp_list_pluck( $rows, 'copies' ) );
			$sections[] = array(
				'title'      => '' === $gkey ? $title : $title . ': ' . $gkey,
				/* translators: 1: number of items, 2: number of copies */
				'note'       => $first ? $note : sprintf( __( '%1$s items, %2$s copies.', 'church-library' ), number_format_i18n( count( $rows ) ), number_format_i18n( $sec_copies ) ),
				'class'      => 'clib-checklist' . ( $first ? '' : ' clib-page-break' ),
				'signoff'    => true,
				'columns'    => $columns,
				'rows'       => $rows,
			);
			$first = false;
		}
		return $sections;
	}

	/**
	 * Missing Data Audit: items missing an image or catalog details, with a count per field.
	 * Image counts as missing when no image is set or the media file was deleted.
	 */
	private static function missing( array $args ) {
		global $wpdb;
		$items  = CLIB_DB::table( 'items' );
		$lov    = CLIB_DB::table( 'lov' );
		$fields = self::missing_fields();
		$title  = __( 'Missing Data Audit', 'church-library' );
		$from   = "FROM $items i
			LEFT JOIN {$wpdb->posts} p ON p.ID = i.image_id AND p.post_type = 'attachment'
			LEFT JOIN $lov mt ON mt.id = i.media_type_id
			LEFT JOIN $lov cl ON cl.id = i.classification_id
			LEFT JOIN $lov ch ON ch.id = i.church_id
			LEFT JOIN $lov bl ON bl.id = i.building_id";

		list( $wsql, $vals ) = self::item_where( $args, false );

		// Per-field totals over the filtered items (before the "missing" filter).
		$sums = array();
		foreach ( $fields as $k => $f ) {
			$sums[] = "SUM(CASE WHEN {$f[1]} THEN 1 ELSE 0 END) AS m_$k"; // Fixed keys and SQL from missing_fields().
		}
		$tot = self::query( 'SELECT COUNT(*) AS n, ' . implode( ', ', $sums ) . " $from WHERE $wsql", $vals );
		if ( null === $tot ) {
			return array( self::error_section( $title ) );
		}
		$tot     = $tot ? $tot[0] : null;
		$checked = $tot ? (int) $tot->n : 0;
		$sum_rows = array();
		foreach ( $fields as $k => $f ) {
			$n          = $tot ? (int) $tot->{'m_' . $k} : 0;
			$sum_rows[] = array(
				'field'   => $f[0],
				'missing' => number_format_i18n( $n ),
				'pct'     => $checked ? number_format_i18n( 100 * $n / $checked, 1 ) . '%' : '',
			);
		}

		// Item list.
		$tests = array();
		$flags = array();
		foreach ( $fields as $k => $f ) {
			$tests[ $k ] = '(' . $f[1] . ')';
			$flags[]     = "(CASE WHEN {$f[1]} THEN 1 ELSE 0 END) AS m_$k";
		}
		$match  = '' !== $args['missing'] ? $tests[ $args['missing'] ] : '(' . implode( ' OR ', $tests ) . ')';
		$nmiss  = '(' . implode( ' + ', array_map( function ( $t ) { return "(CASE WHEN $t THEN 1 ELSE 0 END)"; }, $tests ) ) . ')';
		$lvals  = array_merge( $vals, array( self::MAX_ROWS + 1 ) );
		$res    = self::query(
			'SELECT i.id, i.item_code, i.title, mt.label AS media_type_label, ' . implode( ', ', $flags ) . ", $nmiss AS n_missing
			$from WHERE $wsql AND $match ORDER BY n_missing DESC, i.item_code ASC LIMIT %d",
			$lvals
		);
		if ( null === $res ) {
			return array( self::error_section( $title ) );
		}
		$truncated = count( $res ) > self::MAX_ROWS;
		if ( $truncated ) {
			$res = array_slice( $res, 0, self::MAX_ROWS );
		}
		$rows = array();
		foreach ( $res as $r ) {
			$list = array();
			foreach ( $fields as $k => $f ) {
				if ( (int) $r->{'m_' . $k} ) {
					$list[] = $f[0];
				}
			}
			$rows[] = array(
				'item_code' => $r->item_code,
				'title'     => $r->title,
				'media'     => $r->media_type_label,
				'count'     => count( $list ),
				'fields'    => implode( ', ', $list ),
				'edit'      => '',
				'_item_id'  => (int) $r->id,
			);
		}

		$scope = self::filter_summary( $args, false );
		/* translators: 1: filter description, 2: number of items checked */
		$sum_note = sprintf( __( '%1$s. %2$s items checked (archived items are not included).', 'church-library' ), $scope, number_format_i18n( $checked ) );
		/* translators: %s: field name */
		$list_title = '' !== $args['missing'] ? sprintf( __( 'Items missing: %s', 'church-library' ), $fields[ $args['missing'] ][0] ) : __( 'Items missing any of the fields above', 'church-library' );
		/* translators: %s: number of items */
		$list_note = sprintf( __( '%s items, most missing first.', 'church-library' ), number_format_i18n( count( $rows ) ) );
		if ( $truncated ) {
			/* translators: %s: maximum number of rows */
			$list_note .= ' ' . sprintf( __( 'Only the first %s items are shown. Narrow the filters to see the rest.', 'church-library' ), number_format_i18n( self::MAX_ROWS ) );
		}

		return array(
			array(
				'title'   => __( 'Missing Data by Field', 'church-library' ),
				'note'    => $sum_note,
				'class'   => 'clib-missing-summary',
				'columns' => array(
					'field'   => __( 'Field', 'church-library' ),
					'missing' => __( 'Items Missing It', 'church-library' ),
					'pct'     => __( 'Percent', 'church-library' ),
				),
				'rows'    => $sum_rows,
			),
			array(
				'title'    => $list_title,
				'note'     => $list_note,
				'columns'  => array(
					'item_code' => __( 'Item ID', 'church-library' ),
					'title'     => __( 'Title', 'church-library' ),
					'media'     => __( 'Media Type', 'church-library' ),
					'count'     => __( 'Missing', 'church-library' ),
					'fields'    => __( 'Missing Fields', 'church-library' ),
					'edit'      => __( 'Fix', 'church-library' ),
				),
				'csv_skip' => array( 'edit' ),
				'rows'     => $rows,
			),
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
			$cols = array_diff_key( $s['columns'], array_flip( isset( $s['csv_skip'] ) ? $s['csv_skip'] : array() ) );
			fputcsv( $out, array_map( array( 'CLIB_Util', 'csv_safe' ), array_values( $cols ) ), ',', '"', '\\' );
			foreach ( $s['rows'] as $row ) {
				$line = array();
				foreach ( array_keys( $cols ) as $col ) {
					$line[] = CLIB_Util::csv_safe( isset( $row[ $col ] ) ? $row[ $col ] : '' );
				}
				fputcsv( $out, $line, ',', '"', '\\' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
