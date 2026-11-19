<?php
/**
 * Plugin Name:       Church Library
 * Plugin URI:        https://github.com/md99999/church-library
 * Description:       Multi-church library catalog with check out / check in, locations, reports, CSV import and role-based permissions.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bill Mantz
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       church-library
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details. A copy is included in LICENSE.
 *
 * DISCLAIMER: you install and run this plugin at your own risk, and the author accepts no
 * responsibility or liability for any loss, damage or compromise arising from its use. Every
 * effort has been made to write it safely, but new vulnerabilities appear in software of every
 * kind every day and no website can be guaranteed secure. Test on a staging site, keep backups,
 * and keep WordPress, PHP, your theme and your plugins up to date. See README.md.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CLIB_VERSION', '1.0.0' );
define( 'CLIB_DB_VERSION', '1' );
define( 'CLIB_FILE', __FILE__ );
define( 'CLIB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLIB_URL', plugin_dir_url( __FILE__ ) );

require_once CLIB_DIR . 'includes/class-clib-util.php';
require_once CLIB_DIR . 'includes/class-clib-db.php';
require_once CLIB_DIR . 'includes/class-clib-settings.php';
require_once CLIB_DIR . 'includes/class-clib-perms.php';
require_once CLIB_DIR . 'includes/class-clib-audit.php';
require_once CLIB_DIR . 'includes/class-clib-lov.php';
require_once CLIB_DIR . 'includes/class-clib-items.php';
require_once CLIB_DIR . 'includes/class-clib-loans.php';
require_once CLIB_DIR . 'includes/class-clib-reports.php';
require_once CLIB_DIR . 'includes/class-clib-lookup.php';
require_once CLIB_DIR . 'includes/class-clib-import.php';
require_once CLIB_DIR . 'public/class-clib-public.php';

if ( is_admin() ) {
	require_once CLIB_DIR . 'admin/class-clib-admin.php';
}

register_activation_hook( __FILE__, array( 'CLIB_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CLIB_DB', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CLIB_DB', 'maybe_upgrade' ) );
add_action( 'init', array( 'CLIB_Public', 'init' ) );
add_action( 'init', array( 'CLIB_Lookup', 'init' ) );

if ( is_admin() ) {
	add_action( 'init', array( 'CLIB_Admin', 'init' ) );
}
