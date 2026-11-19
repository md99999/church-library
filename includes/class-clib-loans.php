<?php
/**
 * Circulation: check out / check in.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Loans {

	public static function active_count( $item_id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'loans' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE item_id = %d AND returned_at IS NULL", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function active_for_item( $item_id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'loans' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d AND returned_at IS NULL ORDER BY checked_out_at", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function history_for_item( $item_id, $limit = 50 ) {
		global $wpdb;
		$t = CLIB_DB::table( 'loans' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE item_id = %d ORDER BY checked_out_at DESC LIMIT %d", $item_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get( $loan_id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'loans' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $loan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Active loans with item info, optionally overdue only. */
	public static function active( $overdue_only = false ) {
		global $wpdb;
		$l   = CLIB_DB::table( 'loans' );
		$i   = CLIB_DB::table( 'items' );
		$lov = CLIB_DB::table( 'lov' );
		$sql = "SELECT l.*, i.item_code, i.title, i.copies, ch.label AS church_label
			FROM $l l JOIN $i i ON i.id = l.item_id LEFT JOIN $lov ch ON ch.id = i.church_id
			WHERE l.returned_at IS NULL";
		if ( $overdue_only ) {
			$sql .= $wpdb->prepare( ' AND l.due_date IS NOT NULL AND l.due_date < %s', current_time( 'Y-m-d' ) );
		}
		$sql .= ' ORDER BY l.due_date IS NULL, l.due_date ASC, l.checked_out_at ASC';
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Loans linked to a site account (current and past). */
	public static function for_user( $user_id, $limit = 100 ) {
		global $wpdb;
		$l = CLIB_DB::table( 'loans' );
		$i = CLIB_DB::table( 'items' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT l.*, i.item_code, i.title FROM $l l JOIN $i i ON i.id = l.item_id WHERE l.borrower_user_id = %d ORDER BY l.returned_at IS NULL DESC, l.checked_out_at DESC LIMIT %d", $user_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Check out one copy.
	 *
	 * @return int|WP_Error Loan id.
	 */
	public static function checkout( $item_id, array $raw ) {
		global $wpdb;
		$item = CLIB_Items::get( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'clib_missing', __( 'Item not found.', 'church-library' ) );
		}
		if ( 'not_available' === $item->storage_status ) {
			return new WP_Error( 'clib_unavailable', __( 'This item is marked Not Available.', 'church-library' ) );
		}

		$name = CLIB_Util::text( isset( $raw['borrower_name'] ) ? $raw['borrower_name'] : '', 150 );

		// Optional link to a site account (username or email) so the loan shows on "Library - My Loans".
		$user_id = null;
		$account = CLIB_Util::text( isset( $raw['borrower_account'] ) ? $raw['borrower_account'] : '', 100 );
		if ( '' !== $account ) {
			$user = is_email( $account ) ? get_user_by( 'email', $account ) : get_user_by( 'login', $account );
			if ( ! $user ) {
				return new WP_Error( 'clib_borrower', __( 'No site account matches that username or email.', 'church-library' ) );
			}
			$user_id = (int) $user->ID;
			if ( '' === $name ) {
				$name = CLIB_Util::cap( sanitize_text_field( $user->display_name ), 150 );
			}
		}
		if ( '' === $name ) {
			return new WP_Error( 'clib_borrower', __( 'Borrower name is required.', 'church-library' ) );
		}
		$due = CLIB_Util::date( isset( $raw['due_date'] ) ? $raw['due_date'] : '' );
		if ( ! $due ) {
			$due = ( new DateTime( 'now', wp_timezone() ) )->modify( '+' . (int) CLIB_Settings::get( 'loan_days' ) . ' days' )->format( 'Y-m-d' );
		}

		// Lock the item row so two desks cannot check out the last copy at once.
		$items = CLIB_DB::table( 'items' );
		$loans = CLIB_DB::table( 'loans' );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT id FROM $items WHERE id = %d FOR UPDATE", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $loans WHERE item_id = %d AND returned_at IS NULL", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $out >= (int) $item->copies ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'clib_none_left', __( 'All copies of this item are already checked out.', 'church-library' ) );
		}

		$ok = $wpdb->insert(
			$loans,
			array(
				'item_id'          => (int) $item_id,
				'borrower_name'    => $name,
				'borrower_contact' => CLIB_Util::text( isset( $raw['borrower_contact'] ) ? $raw['borrower_contact'] : '', 150 ),
				'borrower_user_id' => $user_id,
				'checked_out_at'   => CLIB_Util::now(),
				'due_date'         => $due,
				'checked_out_by'   => get_current_user_id(),
				'out_notes'        => CLIB_Util::text( isset( $raw['out_notes'] ) ? $raw['out_notes'] : '', 500 ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'clib_db', __( 'Could not check out the item.', 'church-library' ) );
		}
		$loan_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );

		CLIB_Items::refresh_status( $item_id );
		CLIB_Audit::log( 'checkout', 'item', $item_id, $item->item_code . ' to ' . $name . ' due ' . $due );
		return $loan_id;
	}

	/**
	 * Check in a loan.
	 *
	 * @return true|WP_Error
	 */
	public static function checkin( $loan_id, array $raw = array() ) {
		global $wpdb;
		$loan = self::get( $loan_id );
		if ( ! $loan || null !== $loan->returned_at ) {
			return new WP_Error( 'clib_loan', __( 'That loan is not open.', 'church-library' ) );
		}
		$condition = CLIB_Util::enum( isset( $raw['return_condition'] ) ? $raw['return_condition'] : '', array_map( 'strval', array_keys( CLIB_Util::condition_labels() ) ), '' );
		$updated   = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . CLIB_DB::table( 'loans' ) . ' SET returned_at = %s, returned_to = %d, return_condition = %s, return_notes = %s WHERE id = %d AND returned_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				CLIB_Util::now(),
				get_current_user_id(),
				$condition,
				CLIB_Util::text( isset( $raw['return_notes'] ) ? $raw['return_notes'] : '', 500 ),
				$loan_id
			)
		);
		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'clib_loan', __( 'That loan is not open.', 'church-library' ) );
		}
		if ( '' !== $condition ) {
			$wpdb->update( CLIB_DB::table( 'items' ), array( 'item_condition' => $condition ), array( 'id' => (int) $loan->item_id ), array( '%s' ), array( '%d' ) );
		}
		CLIB_Items::refresh_status( $loan->item_id );
		$item = CLIB_Items::get( $loan->item_id );
		CLIB_Audit::log( 'checkin', 'item', $loan->item_id, ( $item ? $item->item_code : '' ) . ' from ' . $loan->borrower_name );
		return true;
	}
}
