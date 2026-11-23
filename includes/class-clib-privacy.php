<?php
/**
 * WordPress personal-data export / erase for library borrowers.
 *
 * Tools > Export Personal Data and Tools > Erase Personal Data find loans by
 * the borrower's email address or linked site account.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Privacy {

	const PER_PAGE = 100;

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['church-library'] = array(
			'exporter_friendly_name' => __( 'Church Library loans', 'church-library' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['church-library'] = array(
			'eraser_friendly_name' => __( 'Church Library loans', 'church-library' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Church Library',
				'<p>' . esc_html__( 'When you borrow from the church library we record your name, and optionally your email address, other contact details and site account, with the items you borrow and the dates. Only library staff can see this. It is kept so items can be returned and for the library\'s loan history.', 'church-library' ) . '</p>'
			);
		}
	}

	/** Loans matching an email address (or the account that has it). */
	private static function loans( $email, $page, $offset = null ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		$l    = CLIB_DB::table( 'loans' );
		$i    = CLIB_DB::table( 'items' );
		$off  = null === $offset ? max( 0, ( (int) $page - 1 ) * self::PER_PAGE ) : max( 0, (int) $offset );
		return $wpdb->get_results( $wpdb->prepare( "SELECT l.*, i.item_code, i.title FROM $l l LEFT JOIN $i i ON i.id = l.item_id WHERE l.borrower_email = %s OR ( %d > 0 AND l.borrower_user_id = %d ) ORDER BY l.id LIMIT %d OFFSET %d", $email, $user ? (int) $user->ID : 0, $user ? (int) $user->ID : 0, self::PER_PAGE, $off ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function export( $email, $page = 1 ) {
		$rows = self::loans( $email, $page );
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'church-library-loans',
				'group_label' => __( 'Church Library loans', 'church-library' ),
				'item_id'     => 'clib-loan-' . (int) $r->id,
				'data'        => array(
					array( 'name' => __( 'Item', 'church-library' ), 'value' => trim( $r->item_code . ' ' . $r->title ) ),
					array( 'name' => __( 'Borrower name', 'church-library' ), 'value' => $r->borrower_name ),
					array( 'name' => __( 'Email', 'church-library' ), 'value' => $r->borrower_email ),
					array( 'name' => __( 'Contact', 'church-library' ), 'value' => $r->borrower_contact ),
					array( 'name' => __( 'Checked out', 'church-library' ), 'value' => $r->checked_out_at ),
					array( 'name' => __( 'Due', 'church-library' ), 'value' => (string) $r->due_date ),
					array( 'name' => __( 'Returned', 'church-library' ), 'value' => (string) $r->returned_at ),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Remove a borrower's name from the Audit Log entries of one item. Check-out
	 * and check-in entries record "<Item ID> to <name> due <date>" and
	 * "<Item ID> from <name>"; only those exact phrases are replaced, so other
	 * text in the log is untouched. The name is compared in PHP, never put in SQL.
	 *
	 * @param int    $item_id Item the loan was for.
	 * @param string $name    Borrower name as stored on the loan.
	 */
	private static function scrub_audit( $item_id, $name ) {
		global $wpdb;
		$name    = sanitize_text_field( $name ); // The form CLIB_Audit::log() wrote it in.
		$removed = __( '[removed]', 'church-library' );
		if ( '' === $name || $removed === $name || $item_id <= 0 ) {
			return;
		}
		$t    = CLIB_DB::table( 'audit' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, action, details FROM $t WHERE object_type = 'item' AND object_id = %d AND action IN ( 'checkout', 'checkin' )", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		foreach ( (array) $rows as $row ) {
			$details = (string) $row->details;
			if ( 'checkout' === $row->action ) {
				$clean = str_replace( ' to ' . $name . ' due ', ' to ' . $removed . ' due ', $details );
			} else {
				// The name ends a check-in entry; match it there only, so "Ann" never touches "Anna".
				$tail  = ' from ' . $name;
				$clean = substr( $details, -strlen( $tail ) ) === $tail ? substr( $details, 0, -strlen( $tail ) ) . ' from ' . $removed : $details;
			}
			if ( $clean !== $details ) {
				$wpdb->update( $t, array( 'details' => $clean ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			}
		}
	}

	/**
	 * Erase: returned loans are anonymised, along with the borrower's name in the
	 * Audit Log entries for those loans. Loans still out are kept (the item
	 * has to come back) and reported as retained.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$removed  = false;
		$kept     = 0; // Loans still out stay in the match, so skip past them.
		$messages = array();
		for ( $guard = 0; $guard < 1000; $guard++ ) {
			$rows = self::loans( $email, 1, $kept );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				if ( null === $r->returned_at ) {
					++$kept;
					continue;
				}
				$wpdb->update(
					CLIB_DB::table( 'loans' ),
					array(
						'borrower_name'    => __( '[removed]', 'church-library' ),
						'borrower_email'   => '',
						'borrower_contact' => '',
						'borrower_user_id' => null,
						'out_notes'        => '',
						'return_notes'     => '',
					),
					array( 'id' => (int) $r->id ),
					array( '%s', '%s', '%s', '%d', '%s', '%s' ),
					array( '%d' )
				);
				self::scrub_audit( (int) $r->item_id, (string) $r->borrower_name );
				$removed = true;
			}
			if ( count( $rows ) < self::PER_PAGE ) {
				break;
			}
		}
		$retained = $kept > 0;
		if ( $retained ) {
			$messages[] = __( 'Loans for items still checked out were kept until the items are returned.', 'church-library' );
		}
		if ( $removed ) {
			CLIB_Audit::log( 'privacy_erase', 'loan', 0, 'Borrower details anonymised on request' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
