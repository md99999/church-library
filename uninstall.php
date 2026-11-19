<?php
/**
 * Uninstall. Removes capabilities always; removes tables and settings only
 * when "Delete all library data on uninstall" was ticked in Settings.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-clib-util.php';
require_once __DIR__ . '/includes/class-clib-settings.php';
require_once __DIR__ . '/includes/class-clib-perms.php';
require_once __DIR__ . '/includes/class-clib-db.php';

CLIB_Perms::remove_all();

if ( CLIB_Settings::get( 'uninstall_delete' ) ) {
	CLIB_DB::drop_all();
	delete_option( CLIB_Settings::OPTION );
	delete_option( 'clib_db_version' );
}
