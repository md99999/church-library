<?php
/**
 * Schema install / upgrade, seed data, activation.
 *
 * Tables are created as {$wpdb->prefix}clib_* (for example wp_clib_items).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_DB {

	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'items', 'loans', 'lov', 'sequences', 'audit' );
		if ( ! in_array( $name, $allowed, true ) ) {
			wp_die( esc_html__( 'Invalid table.', 'church-library' ) );
		}
		return $wpdb->prefix . 'clib_' . $name;
	}

	public static function activate() {
		self::install();
		CLIB_Perms::install_defaults();
		CLIB_Public::ensure_page();
		CLIB_Items::protect_upload_dir();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'clib_db_version' ) !== CLIB_DB_VERSION ) {
			self::install();
			CLIB_Perms::ensure_admin_caps();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = self::table( 'items' );
		$loans   = self::table( 'loans' );
		$lov     = self::table( 'lov' );
		$seq     = self::table( 'sequences' );
		$audit   = self::table( 'audit' );

		$sql = array();

		$sql[] = "CREATE TABLE $items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_code varchar(32) NOT NULL,
  title varchar(255) NOT NULL,
  description text NULL,
  notes text NULL,
  creator varchar(255) NOT NULL DEFAULT '',
  publisher varchar(255) NOT NULL DEFAULT '',
  media_type_id bigint(20) unsigned NOT NULL,
  subcategory_id bigint(20) unsigned NULL,
  difficulty varchar(20) NOT NULL DEFAULT '',
  item_condition varchar(20) NOT NULL DEFAULT '',
  liturgical_use varchar(255) NOT NULL DEFAULT '',
  classification_id bigint(20) unsigned NULL,
  owned_by varchar(150) NOT NULL DEFAULT '',
  identifier varchar(64) NOT NULL DEFAULT '',
  barcode varchar(64) NOT NULL DEFAULT '',
  call_number varchar(64) NOT NULL DEFAULT '',
  church_id bigint(20) unsigned NULL,
  building_id bigint(20) unsigned NULL,
  room varchar(100) NOT NULL DEFAULT '',
  location_code varchar(64) NOT NULL DEFAULT '',
  copies int(10) unsigned NOT NULL DEFAULT 1,
  storage_status varchar(20) NOT NULL DEFAULT 'available',
  inventory_notes text NULL,
  image_id bigint(20) unsigned NULL,
  cost decimal(10,2) NULL,
  acquired_date date NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY item_code (item_code),
  KEY title (title(191)),
  KEY media_type_id (media_type_id),
  KEY church_id (church_id),
  KEY building_id (building_id),
  KEY storage_status (storage_status),
  KEY barcode (barcode),
  KEY identifier (identifier)
) $charset;";

		$sql[] = "CREATE TABLE $loans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  borrower_name varchar(150) NOT NULL,
  borrower_contact varchar(150) NOT NULL DEFAULT '',
  borrower_user_id bigint(20) unsigned NULL,
  checked_out_at datetime NOT NULL,
  due_date date NULL,
  checked_out_by bigint(20) unsigned NOT NULL DEFAULT 0,
  out_notes varchar(500) NOT NULL DEFAULT '',
  returned_at datetime NULL,
  returned_to bigint(20) unsigned NULL,
  return_condition varchar(20) NOT NULL DEFAULT '',
  return_notes varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY item_id (item_id),
  KEY returned_at (returned_at),
  KEY due_date (due_date)
) $charset;";

		$sql[] = "CREATE TABLE $lov (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_type varchar(32) NOT NULL,
  code varchar(20) NOT NULL DEFAULT '',
  label varchar(100) NOT NULL,
  parent_id bigint(20) unsigned NULL,
  is_music tinyint(1) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY list_type (list_type),
  KEY parent_id (parent_id)
) $charset;";

		$sql[] = "CREATE TABLE $seq (
  prefix varchar(20) NOT NULL,
  next_val bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (prefix)
) $charset;";

		$sql[] = "CREATE TABLE $audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(32) NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details varchar(1000) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object (object_type, object_id)
) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::seed();
		CLIB_Settings::ensure_defaults();
		update_option( 'clib_db_version', CLIB_DB_VERSION, false );
	}

	/** Seed starting list values (only when a list is empty). */
	private static function seed() {
		$seed = array(
			'media_type'        => array(
				array( 'CDROM', 'CDROM', 0 ),
				array( 'BOOK', 'Books', 0 ),
				array( 'DVD', 'DVD', 0 ),
				array( 'MUSIC', 'Music', 1 ),
				array( 'SERMON', 'Sermons', 0 ),
				array( 'PROP', 'Theatre Props', 0 ),
				array( 'AUDIO', 'Audio', 0 ),
				array( 'SOFT', 'Software', 0 ),
				array( 'MAG', 'Magazines', 0 ),
			),
			'music_subcategory' => array(
				array( '', 'Sheet Music', 0 ),
				array( '', 'Choir Music', 0 ),
				array( '', 'Instrumental Music', 0 ),
				array( '', 'Orchestra Scores', 0 ),
				array( '', 'Handbell Music', 0 ),
				array( '', 'Worship Music', 0 ),
				array( '', 'Performance Parts', 0 ),
			),
			'classification'    => array(
				array( '', 'Fiction', 0 ),
				array( '', 'Non-Fiction', 0 ),
				array( '', "Children's", 0 ),
				array( '', 'Religious', 0 ),
				array( '', 'Instructional', 0 ),
				array( '', 'Health', 0 ),
				array( '', 'Audio', 0 ),
			),
			'liturgical_use'    => array(
				array( '', 'Advent', 0 ),
				array( '', 'Christmas', 0 ),
				array( '', 'Epiphany', 0 ),
				array( '', 'Lent', 0 ),
				array( '', 'Holy Week', 0 ),
				array( '', 'Easter', 0 ),
				array( '', 'Pentecost', 0 ),
				array( '', 'Ordinary Time', 0 ),
				array( '', 'Weddings', 0 ),
				array( '', 'Funerals', 0 ),
				array( '', 'Baptism', 0 ),
				array( '', 'Communion', 0 ),
			),
		);

		foreach ( $seed as $type => $rows ) {
			if ( CLIB_LOV::count( $type ) > 0 ) {
				continue;
			}
			$order = 10;
			foreach ( $rows as $row ) {
				CLIB_LOV::insert(
					array(
						'list_type'  => $type,
						'code'       => $row[0],
						'label'      => $row[1],
						'is_music'   => $row[2],
						'sort_order' => $order,
					)
				);
				$order += 10;
			}
		}
	}

	/** .htaccess for uploads/church-library: images only, never execute scripts. */
	public static function uploads_htaccess() {
		return <<<'HTACCESS'
# Church Library: block script execution and directory listing.
Options -Indexes -ExecCGI
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>
<IfModule mod_php8.c>
  php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pl .py .cgi .sh
<FilesMatch "\.(?i:php\d?|phtml|phar|pl|py|cgi|sh|shtml|svg|html?|js)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
<IfModule mod_headers.c>
  Header set X-Content-Type-Options nosniff
</IfModule>

HTACCESS;
	}

	/** Drop all plugin data. Only called from uninstall.php when the admin opted in. */
	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'items', 'loans', 'lov', 'sequences', 'audit' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table names.
		}
	}
}
