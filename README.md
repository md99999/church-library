# Church Library

A WordPress plugin for running a library shared by several churches: a catalog of books, music, DVDs, props and anything else you lend out, with check out / check in, shelf locations, reports, CSV import and role-based permissions.

## Disclaimer: install and run at your own risk

**You install and run this plugin entirely at your own risk. The author accepts no responsibility or liability for any loss, damage or compromise that results from using it.**

Every effort has been made to write it safely (see *Security* below), but new vulnerabilities are discovered in software of every kind every day, and no website can be guaranteed secure. The plugin is provided as is, without warranty of any kind, under the GNU General Public License v3 or later. Test on a staging site first, keep backups of your database and files, keep WordPress, PHP, your theme and plugins up to date, and serve the site over HTTPS.

Deleting items and the *Delete all library data on uninstall* option erase data permanently and cannot be undone. Turning that option on requires ticking a warning box and typing `DELETE`; the server rejects the change otherwise.

## Features

- **Catalog** with title, description, notes, creator/author, publisher, media type, music sub-category and difficulty (music only), condition, liturgical use, classification, owned by, ISBN/identifier, barcode, call #, church, building, room, location ID, number of copies, storage status, inventory notes, cost, acquired date and an image.
- **Item IDs** are generated automatically from the media type prefix and a sequence number, e.g. `BOOK-000123`, `MUSIC-000007`. The prefix is set per media type under *Library - Lists*.
- **Locations**: church → building → room → location ID. The location ID is free-form, so you can use an aisle-bay-shelf scheme such as `A-12-3`.
- **Check out / check in** desk with borrower name and contact, due dates, multiple copies per item, overdue highlighting, return condition and full loan history. Status changes between *Checked In* and *Checked Out* automatically; *Not Available* is set by staff.
- **Reports**: collection summary (by media type, church, building, status, condition, totals), currently checked out, overdue, circulation history by date range, and an inventory/shelf list sorted by location. Every report can be downloaded as CSV or printed.
- **Lists of values** managed by admins: media types, music sub-categories, churches, buildings (linked to a church), classifications and liturgical uses. Starting values are seeded on activation.
- **CSV import** for existing spreadsheets (save the sheet as *CSV UTF-8*). Headings such as `Barcode`, `Call #`, `Title`, `Author's Name`, `Classification` and `Cost` are recognised automatically, with preview mode and duplicate skipping.
- **Title / ISBN lookup** on the item form. It shows matches already in your catalog and results from Open Library, and fills in title, author, publisher, ISBN, description and cover image. Lookup sources are pluggable (see *Extending the lookup*).
- **Public catalog** page (`Library - Catalog`, shortcode `[church_library]`) with search and filters. It can be restricted to logged-in users.
- **Role permission matrix** in *Library - Settings*. Administrators always have full rights.
- **Audit log** of every change, check out, check in, import and export.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer (tested on 8.5)
- MySQL 5.7+ / MariaDB 10.3+ with InnoDB

## Installation

1. Copy this folder to `wp-content/plugins/church-library` (or zip it and use *Plugins → Add New → Upload*).
2. Activate **Church Library**.
3. Go to **Library → Lists** and add your churches and buildings. Review the media types and their ID prefixes.
4. Go to **Library → Settings** to choose whether the catalog is public and to adjust role permissions.
5. Optional: **Library → Import** your existing spreadsheet.

Activation creates these tables (using your site's table prefix, normally `wp_`):

| Table | Purpose |
| --- | --- |
| `wp_clib_items` | Catalog items |
| `wp_clib_loans` | Check out / check in history |
| `wp_clib_lov` | Admin-managed lists of values |
| `wp_clib_sequences` | Item ID counters per prefix |
| `wp_clib_audit` | Audit log |

The prefix is `clib_` with an underscore rather than `clib-`, because hyphens in MySQL table names must be back-quoted in every query and are not supported by WordPress's `dbDelta()`.

All schema and seed SQL lives in PHP (`includes/class-clib-db.php`); the plugin ships no `.sql` files.

## Pages

### Website pages (front end)

Activation creates one WordPress page per task, each holding a shortcode. Team members can do their everyday work from these pages without opening the WordPress dashboard. A navigation bar on every library page shows only the pages the visitor is allowed to open.

| Page | Shortcode | Who can open it |
| --- | --- | --- |
| Library - Catalog | `[church_library]` | Everyone when *Public catalog* is on, otherwise logged-in users with *View catalog* |
| Library - Check Out | `[clib_checkout]` | *Check out / check in* |
| Library - Check In | `[clib_checkin]` | *Check out / check in* |
| Library - Add or Edit Item | `[clib_manage]` | *Add & edit items* |
| Library - My Loans | `[clib_my_loans]` | Logged-in users with *View catalog* (loans linked to their account) |
| Library - Reports | `[clib_reports]` | *View reports* |
| Library - How To | `[clib_howto]` | Same as the catalog: a plain-language guide for team members |

Existing pages are reused and never overwritten. If a page is deleted or renamed, press **Create / repair library pages** in *Library - Settings*. Add the pages you want to a menu under *Appearance*.

Every page checks access on the server before rendering anything. Every form posts back to its own page with an action-bound nonce, and the handler checks the nonce and the capability again before it does anything.

When checking an item out you can enter a site username or email. The loan is then linked to that account and shows on the borrower's *Library - My Loans* page.

### Admin screens

Under the **Library** menu in the dashboard:

| Screen | Required permission |
| --- | --- |
| Library - Catalog | View catalog |
| Library - Add Item / Edit Item | Add & edit items |
| Library - Check Out / In | Check out / check in |
| Library - Reports | View reports |
| Library - Lists | Manage lists of values |
| Library - Import | Import CSV |
| Library - Settings | Administrator only |
| Library - Audit Log | Administrator only |

Deleting items, importing, lists of values, settings and the audit log are available only in the dashboard.

## Roles and permissions

Default permissions (editable in *Library - Settings*):

| Role | View | Add & edit | Delete | Check out / in | Reports | Import | Lists |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Administrator | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Editor | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Author | ✓ | ✓ | | ✓ | ✓ | | |
| Contributor | ✓ | ✓ | | ✓ | ✓ | | |
| Subscriber | ✓ | | | | | | |
| Public (not logged in) | ✓ when *Public catalog* is on | | | | | | |

Permissions are stored as real WordPress capabilities (`clib_view`, `clib_manage_items`, `clib_delete_items`, `clib_circulate`, `clib_reports`, `clib_import`, `clib_manage_lists`), so they also work with role-editor plugins.

Borrower names are visible only to users who can check items out, unless *Borrower names* is enabled in settings. Guests never see them.

## Security

The plugin was built to be safe on a public church website:

- **SQL injection**: every query uses `$wpdb->prepare()` or `$wpdb->insert()/update()` with formats. Table and column names come from fixed whitelists, `LIKE` values go through `esc_like()`, and sort columns/directions are whitelisted.
- **CSRF and authorisation**: every state-changing action is a POST to `admin-post.php` with an action-bound nonce (per item for deletes), followed by a capability check before any input is read. The AJAX lookup has a nonce and a capability check, and no `nopriv` handler.
- **Input validation**: all input is unslashed, sanitised, stripped of control characters and null bytes, and cut to the column's maximum length, so oversized or appended strings are truncated rather than stored. Enumerations (status, condition, difficulty, list types, report names) are whitelisted. IDs that reference lists are checked to exist in the right list, and a building must belong to the selected church.
- **Output escaping**: everything is escaped at output (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`). Admin notices are stored server-side per user and never reflected from the URL. Admin JavaScript inserts remote data with `textContent` only.
- **Uploads**: images must be JPG, PNG, GIF or WebP, are checked by extension, real MIME type (`wp_check_filetype_and_ext`) and image header (`getimagesize`), are limited to 5 MB, and are then **re-encoded** through the WordPress image editor. Re-encoding strips EXIF/GPS data and anything appended to the file. Files are stored by `wp_handle_upload()`, which sanitises names and prevents path traversal, in `uploads/church-library/`. That folder gets an `index.php` and an `.htaccess` that disables script execution.
- **CSV import** accepts only `.csv` text files up to 5 MB / 5,000 rows, rejects binary or zipped content, and reads the temporary file in place without moving it anywhere web-accessible.
- **CSV export** neutralises spreadsheet formula injection (`=`, `+`, `-`, `@`).
- **Outbound requests**: the lookup can be turned off in settings. It uses `wp_safe_remote_get()` with short timeouts and a size limit, is limited to 30 lookups per user per 5 minutes, and caches results. Cover images are never downloaded from a URL sent by the browser: the server rebuilds the URL from a numeric cover ID and checks the host against the provider's allow list.
- **Race conditions**: Item IDs are allocated atomically (`LAST_INSERT_ID(expr)`), and check-outs lock the item row so two desks cannot lend the last copy at the same time.
- **Direct access**: every PHP file exits unless loaded by WordPress, every folder has a "silence is golden" `index.php`, and `.htaccess` files deny direct requests to PHP and other non-asset files on Apache.
- **Audit log** with user, time and IP (`REMOTE_ADDR` only, as forwarded headers can be spoofed).
- **Uninstall** removes capabilities. It only drops tables if *Delete all library data on uninstall* is ticked, which an administrator can only turn on by ticking a warning box and typing `DELETE` (checked on the server behind a nonce and `manage_options`, and recorded in the audit log).

### nginx

nginx ignores `.htaccess`. Add rules like these to your server block:

```nginx
location ~* ^/wp-content/plugins/church-library/.*\.(php|md|txt|json|sql)$ { deny all; }
location ~* ^/wp-content/uploads/church-library/.*\.(php\d?|phtml|phar|pl|py|cgi|sh|html?|js|svg)$ { deny all; }
```

## Extending the lookup

The lookup is designed for more sources (for example sheet-music publishers). Implement `CLIB_Lookup_Provider` and register it:

```php
add_filter( 'clib_lookup_providers', function ( $providers ) {
	$providers[] = new My_Music_Provider();
	return $providers;
} );
```

A provider returns normalised results (`title`, `creator`, `publisher`, `year`, `identifier`, `description`, `cover_ref`, `cover_thumb`) and builds cover URLs itself from `cover_ref`, restricted to the hosts in `allowed_hosts()`. All provider output is sanitised again before it reaches the browser.

## Directory layout

```
church-library.php        Plugin bootstrap
uninstall.php             Uninstall cleanup
includes/                 Data model, validation, permissions, lookup, import, reports
admin/                    Admin controller and screen templates
public/                   Front-end shortcode
assets/                   CSS and JavaScript
```

## License

GPL-3.0-or-later. See `LICENSE`.
