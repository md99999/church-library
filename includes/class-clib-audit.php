<?php
/**
 * Audit log of every change made through the plugin.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Audit {

	public static function log( $action, $object_type = '', $object_id = 0, $details = '' ) {
		global $wpdb;
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// REMOTE_ADDR only: forwarded headers are client-controlled and spoofable.
			$candidate = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ip        = $candidate ? $candidate : '';
		}
		$wpdb->insert(
			CLIB_DB::table( 'audit' ),
			array(
				'created_at'  => CLIB_Util::now(),
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 32 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 20 ),
				'object_id'   => (int) $object_id,
				'details'     => CLIB_Util::cap( sanitize_text_field( $details ), 1000 ),
				'ip'          => $ip,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function page( $page, $per_page ) {
		global $wpdb;
		$t      = CLIB_DB::table( 'audit' );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( $rows, $total );
	}
}
