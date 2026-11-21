# Church Library

A WordPress plugin for running a library shared by several churches: a catalog of books, music, DVDs, props and anything else you lend out, with check out / check in, shelf locations, reports, CSV import and role-based permissions.

## Disclaimer: install and run at your own risk

**You install and run this plugin entirely at your own risk. The author accepts no responsibility or liability for any loss, damage or compromise that results from using it.**

Every effort has been made to write it safely (see *Security* below), but new vulnerabilities are discovered in software of every kind every day, and no website can be guaranteed secure. The plugin is provided as is, without warranty of any kind, under the GNU General Public License v3 or later. Test on a staging site first, keep backups of your database and files, keep WordPress, PHP, your theme and plugins up to date, and serve the site over HTTPS.

Deleting items and the *Delete all library data on uninstall* option erase data permanently and cannot be undone. Turning that option on requires ticking a warning box and typing `DELETE`; the server rejects the change otherwise.

## Features

- **Catalog** with title, description, notes, creator/author, publisher, media type, music sub-category and difficulty (music only), condition, liturgical use, classification, owned by, ISBN/identifier, barcode, cat #, church, building, room, location ID, number of copies, storage status, inventory notes, cost, acquired date and an image.
- **Item IDs** are generated automatically from the media type prefix and a sequence number, e.g. `BOOK-000123`, `MUSIC-000007`. The prefix is set per media type under *Library - List of Values*. When an item's media type changes (for example Books to DVD), it gets a new ID with the new prefix when saved; the old and new IDs are recorded in the audit log.
- **Data Tools** (*Library - Data Tools*):
  - The **Validate ID prefixes** button in *Library - Settings* checks the prefix setup itself (missing, malformed or shared prefixes) and counts mismatched IDs per media type, without changing anything.
  - *Item ID check* lists items whose ID prefix does not match their media type (items moved before 1.3.0, or a media type whose prefix was changed) and regenerates the ticked ones after you review the list and confirm. Up to 500 per run, all-or-nothing.
  - *Duplicate titles* lists catalog items with the same title **in the same media type** (ignoring capitals, accents, punctuation and a leading The/A/An; optionally close spellings too). Pick the record to keep and merge the others into it: its quantity becomes the total copies of all merged records, empty fields are filled from the others, loan history moves to it, the merged IDs and barcodes are noted in its inventory notes, and the other records are deleted. Runs in one database transaction; any failure changes nothing.
- **Archive and delete** on the item view and edit pages. *Archive* marks an item inactive and hides it from the catalog, reports, lookups, duplicates and check out, keeping its record and history; restore it from *Catalog > Archived items*. *Delete* permanently removes the item and its loan history after a confirmation box that the server checks. An item with copies checked out cannot be archived or deleted.
- **Locations**: church → building → room (each chosen from the List of Values and linked to its parent) → location ID. The location ID is free-form, so you can use an aisle-bay-shelf scheme such as `A-12-3`.
- **Check out / check in** desk with borrower name and contact, due dates, multiple copies per item, overdue highlighting, return condition and full loan history. Status changes between *Checked In* and *Checked Out* automatically; *Not Available* is set by staff.
- **Reports**: collection summary (by media type, church, building, status, condition, totals), currently checked out, overdue, circulation history by date range, an inventory/shelf list sorted by location, an **Inventory Checklist** and a **Missing Data Audit**. Every report can be downloaded as CSV or printed.
  - *Inventory Checklist* is a printable stock-taking sheet: one row per item with Item ID, Title, Creator/Author, Media Type, Cat #, Building, Room, Copies, Barcode and an empty *Found* box to tick on paper, plus *Counted by* and *Date* lines. Filter by church, building, room, media type and status; sort by location, cat #, title or Item ID; optionally start a new page for each room. The column headings repeat on every printed page, rows are never split across pages, and pages are numbered (page numbers need Chrome or Edge).
  - *Missing Data Audit* counts how many items are missing each of: image (none set, or the media file was deleted), creator/author, publisher, ISBN/identifier, description, classification, church, building, room, cat #, barcode and condition, then lists the items with the most gaps first and an *Edit* link for each (opens in a new tab). Filter by the missing field, church, building, room and media type.
  - Archived items are left out of every report. Each run shows at most 5,000 rows; narrow the filters for more.
- **List of Values** managed by admins: every dropdown (media types, music sub-categories, churches, buildings linked to a church, rooms linked to a building, classifications, liturgical uses, conditions and music difficulty levels) can be added to, renamed, reordered, hidden or deleted when unused. Starting values are seeded on activation.
- **CSV import** for existing spreadsheets (save the sheet as *CSV UTF-8*). Headings such as `Barcode`, `Cat #` (or `Call #`), `Title`, `Author's Name`, `Classification` and `Cost` are recognised automatically, with preview mode and duplicate skipping.
- **Full-width library pages** (Library - Settings > Page layout, on by default): the "Library - ..." pages use the theme's full-width / no-sidebar page template when it has one, and otherwise hide sidebar widget areas and widen the content. A template picked by hand in the page editor is never changed.
- **Title / ISBN lookup** on the item form. It shows matches already in your catalog and results from the online sources ticked in Library - Settings, and fills in title, author, publisher, ISBN, description and cover image. Built-in sources: Open Library (books), Google Books (books; optional API key), MusicBrainz + Cover Art Archive (CDs and recordings, with album art), Hymnary.org (hymn texts, authors, meter), IMSLP (public-domain scores, composer), Wikidata (films, DVD and VHS: title, year, director, description; free CC0 data, no key) and Discogs (music releases; needs a free personal access token or an app's consumer key and secret). Sources that suit the item's media type run first, in order (book sources for Books, music sources for music; Wikidata for DVD/VHS); the others run only when those find nothing. Results are collated and ranked by how closely the title, and the author when one is typed, match. Author matching ignores word order, so "Tolkien, J.R.R." matches "J. R. R. Tolkien". If a source is over its quota or rejects its key, the lookup says so. IMDb has no free official API and TMDB was removed in 1.3.2 because its licence for this use is paid, so Wikidata is the movie source (it has no cover images). The enabled sources are credited in a "Lookup data from" footer on library pages and admin screens, and Google Books results and covers are also credited where they appear. Each can be switched on or off; only https requests go out, only staff can run them, and cover images come only from each source's allow-listed image host and are re-encoded before saving. Lookup sources are pluggable (see *Extending the lookup*).
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
3. Go to **Library → List of Values** and add your churches and buildings. Review the media types and their ID prefixes.
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
| Library - List of Values | Manage lists of values |
| Library - Import | Import CSV |
| Library - Data Tools | Delete items (merging and fixing IDs also need Add & edit items) |
| Library - Settings | Administrator only |
| Library - Audit Log | Administrator only |

Deleting items, importing, lists of values, settings and the audit log are available only in the dashboard.

## Roles and permissions

Default permissions (editable in *Library - Settings*):

| Role | View | Add & edit | Delete | Check out / in | Reports | Import / export | List of Values |
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
- **API keys** (Google Books, Discogs) are validated against each service's key format, never shown again on the settings page, and sent only over https to the fixed address of their own service, in a request header where the service allows it (Google Books `X-goog-api-key`, Discogs `Authorization`). Remote data is re-validated field by field: ids and poster names must match strict patterns, cover URLs are rebuilt server-side on allow-listed hosts, and text is sanitised before it reaches the browser.
- **Destructive actions** (delete, archive, merge, Item ID fixes) each need an action-bound nonce, the matching capability, and, for delete, merge and fixes, a confirmation box checked on the server. Merges, ID fixes and deletes run inside database transactions with row locks and roll back on any failure.
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

## Changelog

### 1.3.6
- Missing Data Audit: the *Edit* links open the item in a new tab, so the report stays open while you fix items.

### 1.3.5
- New report: **Inventory Checklist**, a printable stock-taking sheet with a *Found* box per item, *Counted by* / *Date* lines, filters (church, building, room, media type, status), sort by location, cat #, title or Item ID, and an option to start a new page for each room. Printed pages repeat the column headings, keep each row whole and are numbered.
- New report: **Missing Data Audit**, a count per field of items missing an image or catalog details, and a list of those items (most gaps first) with an *Edit* link. Filter by the missing field, church, building, room and media type.
- "Call #" is now labelled **Cat #** everywhere (item form and view, catalog, reports, CSV export). CSV import accepts either heading.
- Both reports also download as CSV, leave out archived items, and are on the front-end Reports page. Filters are checked against fixed lists or sanitized, every query is prepared, and a failed query is written to the Audit Log instead of being shown.

### 1.3.4
- Archive and Delete are now on the front-end **Edit item** page (Library - Manage Items) too, not only in the dashboard. Archive needs the Manage items permission and Delete needs the Delete items permission; Delete still needs the confirmation box, which the server checks. Each form carries a nonce bound to that item, so a request cannot be redirected to another item.
- Both Edit item pages have an "Archive or delete this item" link at the top that jumps to the buttons at the bottom.

### 1.3.3
- API key fields no longer pick up a browser's saved login password (which could replace or shorten a pasted key). The fields are wider, have a Show button to check a key before saving, and show the saved key's last four characters and length so you can confirm it was stored whole.
- A key in the wrong format is refused, never shortened: the error names the field and says how many characters were received and what was expected. Spaces, line breaks and invisible characters picked up while copying are removed first.
- Library - Settings > **Show this server's IP address**: finds the server's outgoing IPv4 and IPv6 addresses (via ipify.org, only when clicked) to add to an API key's IP restriction. On shared hosting this is often not the address the site name points to.
- When Google refuses the key, the lookup says why: IP address restriction (with the address Google saw), website-only restriction, Books API not enabled, or invalid key.
- Errors are written to the Audit Log (action `error`): lookup sources that refuse or do not answer, cover images that cannot be imported or come from a host not on the allow-list, the lookup rate limit, and a failed IP check. Each distinct error is logged at most once an hour. API keys and response bodies are never logged. Refused API key entries are logged as `settings_rejected` (field name and length only).
- Google Books keys are checked strictly (39 characters starting with AIza). A saved key that does not match is flagged on the settings page.

### 1.3.2
- Removed the TMDB movie lookup source (its licence for this use is paid). Its settings and any saved TMDB key are deleted on update. Sites that had TMDB switched on get Wikidata instead.
- New free movie source: Wikidata (CC0 data, no key). Searches films, TV films, series and other screen works and fills title, year, director and description, with a link to the Wikidata entry.
- "Lookup data from" footer on library pages and admin screens credits every enabled lookup source with a link.
- Google Books results and imported covers are credited to Google Books.

### 1.3.1
- Library - Settings > Item ID prefixes > **Validate ID prefixes**: read-only check that every media type has its own valid prefix (present, letters/digits only, not shared with another media type) and that every Item ID matches its media type, with counts per media type and a link to Data Tools to fix mismatches.

### 1.3.0
- Changing an item's media type gives it a new Item ID with the new prefix.
- Library - Data Tools: Item ID check (find and fix IDs that do not match their media type) and Duplicate titles (find possible duplicates within the same media type and merge them, adding up the copies).
- Archive (hide, restorable) and permanent Delete on the item edit page; archived items have their own catalog filter.
- Title lookup: Google Books and TMDB sources; sources matched to the media type run first; results ranked by title and author closeness; quota and key problems are reported.
- Database version 6 (adds `is_archived` and `archived_at` to items; applied automatically on update).

### 1.2.2
- Page links (1 2 3 Next) shown above the list as well as below, on the front-end catalog, the admin catalog and the audit log.

### 1.2.1
- Discogs can use a registered app's consumer key and secret instead of a personal access token (validated; never shown back on the settings page).
- Settings shows an error when a Discogs credential is rejected, instead of silently keeping the old value.

### 1.2.0
- Library pages show full width without the theme sidebar by default (Library - Settings > Page layout).
- Save / Add / Cancel buttons at the top and bottom of the item form.
- Discogs setting links straight to the page where the personal access token is created, and to the Discogs API docs and terms.

### 1.1.0
- Room as a managed List of Values entry linked to Building, seeded from existing data.
- Music lookups: MusicBrainz + Cover Art Archive, Hymnary.org, IMSLP and Discogs, each toggleable.
- Music CSV template and music-only export; import flags values not in List of Values.
- Optional, validated borrower email at check out (staff-only, WordPress privacy export/erase).
- Image upload lockdown: JPEG/PNG/GIF/WebP only, content-checked, re-encoded, random names, no-exec folder.
- Bulk edit, full catalog CSV export, editable List of Values, front-end pages and How To guide.

### 1.0.0
- First release.

## License

GPL-3.0-or-later. See `LICENSE`.
