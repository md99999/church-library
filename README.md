# Church Library

A WordPress plugin for running a library shared by several churches: a catalog of books, music, DVDs, props and anything else you lend out, with check out / check in, shelf locations, reports, CSV import and role-based permissions.

## Disclaimer: install and run at your own risk

**You install and run this plugin entirely at your own risk. The author accepts no responsibility or liability for any loss, damage or compromise that results from using it.**

Every effort has been made to write it safely (see *Security* below), but new vulnerabilities are discovered in software of every kind every day, and no website can be guaranteed secure. The plugin is provided as is, without warranty of any kind, under the GNU General Public License v3 or later. Test on a staging site first, keep backups of your database and files, keep WordPress, PHP, your theme and plugins up to date, and serve the site over HTTPS.

**Found a security problem?** Please report it privately by email to **sysop@maddogproductions.online**, not in a public GitHub issue. See [SECURITY.md](SECURITY.md) and *Reporting a vulnerability* below.

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

> **Do not install GitHub's "Download ZIP" or a git clone directly.** GitHub names the folder after the branch (`church-library-main`), so a later proper install becomes a *second copy* of the plugin sharing the same tables, and the download carries development files (a clone also carries the `.git` folder with the whole project history) into your web root. Use a release zip, or build one as described in [Building the install zip](#building-the-install-zip).

1. In WordPress go to *Plugins → Add New → Upload Plugin*, choose `church-library-<version>.zip` and press *Install Now*. (Or unzip it and upload the `church-library` folder to `wp-content/plugins/` by SFTP. The folder must be named exactly `church-library`.)
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

After activating, open **Library → Settings** and check the **Installation health** box at the top. It should say *Nothing to fix*; press **Run web checks** once to confirm your server blocks the files it should.

## Building the install zip

There is no build step: no compiler, bundler or dependencies. The plugin is the source; it only needs packaging so that the zip holds **one top-level folder named `church-library`** and none of the development files. Use one of these.

### With git (same as the published releases)

From a clone of the repository:

```bash
git archive --format=zip --prefix=church-library/ -o ../church-library-1.3.8.zip HEAD
```

(Change the version number to match the `Version:` line in `church-library.php`.) This packs the last commit, not uncommitted edits, and `.gitattributes` keeps development-only files (`.gitattributes`, `.gitignore`, `.github/`, `tools/`) out of the zip. It works the same in Git Bash, PowerShell or a Linux/macOS terminal.

### With PHP, without git

If git is not installed (common on Windows), `tools/build-zip.php` does the same with nothing but PHP:

```bash
php tools/build-zip.php
```

It writes `church-library-<version>.zip` next to the plugin folder, taking the version from the plugin header, and prints the path, file count and size. Pass a path to write elsewhere: `php tools/build-zip.php C:\temp\church-library.zip`. It needs PHP's `zip` extension (sometimes switched off in a command-line PHP on Windows; the script says so and stops). It packs the **working tree**, uncommitted edits included, and skips `.git`, `.github`, `tools/`, `.gitignore`, `.gitattributes`, editor folders, logs and zips. Before writing, it checks the files against the list the installation health check uses (`CLIB_Health::MANIFEST` in `includes/class-clib-health.php`) and that every folder has its `index.php`, and refuses to build if they disagree. When you add a file to the plugin, add it to that list.

### Check the zip before uploading

Open the zip: it must contain exactly one folder, `church-library`, with `church-library.php` directly inside it. If you see `church-library-main/`, `.git/`, `.github/` or `tools/`, it is not a release zip.

### If someone already installed a raw download

*Library → Settings → Installation health* (and a notice on the Plugins screen) will say so. To fix it: deactivate the plugin, delete or rename the wrongly named folder (library data is in the database and is kept; check that *Delete all library data on uninstall* is **off** before using *Delete* on the Plugins screen), upload the proper zip, and activate it again.

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

### Reporting a vulnerability

If you find a security issue, please **email it privately to sysop@maddogproductions.online**. Do not open a public GitHub issue or pull request for it, and please do not publish details until a fixed version is released. Include the plugin version, steps to reproduce, and what an attacker could do. See [SECURITY.md](SECURITY.md) for the full policy.

### How the plugin is protected

The plugin was built to be safe on a public church website:

- **SQL injection**: every query uses `$wpdb->prepare()` or `$wpdb->insert()/update()` with formats. Table and column names come from fixed whitelists, `LIKE` values go through `esc_like()`, and sort columns/directions are whitelisted.
- **Public catalog search** (the search box and filters on *Library - Catalog*, open to guests when the catalog is public): the term is cut to 800 bytes before any processing, then sanitised (tags, percent-encoded octets, control characters and null bytes removed, invalid UTF-8 dropped) and capped at 100 characters; filters are integers or whitelisted values and arrays are rejected. The term is only ever a bound `LIKE` value after `esc_like()`, never SQL text, a file path or a command. Guests cannot search barcodes (staff-only data). Searches are limited to 60 a minute per IP address (per user when logged in), counted under a keyed hash so raw IPs are not stored; change it with the `clib_catalog_search_rate_limit` filter (0 turns it off, for example behind a proxy that gives every visitor the same address). Page links are rebuilt from the sanitised values only, never echoed from the request URL, and database errors are never shown to visitors (they go to the Audit Log, at most once an hour). The tests in `tools/tests/` check all of this.
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
- **Direct access**: every PHP file exits unless loaded by WordPress, every folder has a "silence is golden" `index.php`, every folder of PHP files has a deny-all `.htaccess`, `assets/` serves only CSS, JS and images, and the top-level `.htaccess` refuses hidden paths (`.git/`, `.github/`, `.env`) and non-asset files (`.md`, `.sql`, `.json`, `.log`, `.zip` ...) on Apache. This holds even if someone uploads a raw repository copy instead of a release zip.
- **Installation health check** (*Library - Settings*, administrators only): checks the plugin folder name, other copies, a `.git` folder, files that are not part of the release, missing `index.php` / `.htaccess` / `ABSPATH` guards, PHP, WordPress and database versions, the library tables, InnoDB and schema version, the image upload folder, HTTPS, on-screen PHP errors and the lookup configuration. **Run web checks** (nonce-protected POST) also asks the site whether it serves `README.md` and `.git/HEAD`, and tests an outgoing https request to api.wordpress.org. All checks only read, and every result is escaped when shown.
- **Audit log** with user, time and IP (`REMOTE_ADDR` only, as forwarded headers can be spoofed).
- **Uninstall** removes capabilities. It only drops tables if *Delete all library data on uninstall* is ticked, which an administrator can only turn on by ticking a warning box and typing `DELETE` (checked on the server behind a nonce and `manage_options`, and recorded in the audit log).

### nginx

nginx ignores `.htaccess`. Add rules like these to your server block:

```nginx
location ~* ^/wp-content/plugins/church-library/(.*/)?\. { deny all; }
location ~* ^/wp-content/plugins/church-library/tools/ { deny all; }
location ~* ^/wp-content/plugins/church-library/.*\.(php|md|txt|json|sql|log|lock|zip)$ { deny all; }
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
includes/                 Data model, validation, permissions, lookup, import, reports, health check
admin/                    Admin controller and screen templates
public/                   Front-end shortcode
assets/                   CSS and JavaScript
tools/                    Build script and tests (development only; not in release zips)
SECURITY.md               How to report a vulnerability
```

## Changelog

### 1.3.8
- Security review and hardening of the public **catalog search** (search box, filters, sort and page links), which guests can use when the catalog is public. No SQL injection, XSS, path traversal or command injection was found; these changes add defence in depth:
  - The search term is cut to 800 bytes before sanitising, so an oversized query string never reaches the regular expressions, then capped at 100 characters as before.
  - Guests can no longer search by barcode. The barcode is only shown to staff, so a guest could previously confirm a barcode by searching for it.
  - Searches are limited to 60 a minute per IP address (per user when logged in). Over the limit, the page asks the visitor to wait instead of running the query. Filter `clib_catalog_search_rate_limit` changes the number; 0 turns it off.
  - Page links are built by the plugin from the sanitised search values only. WordPress's `paginate_links()` copied every parameter of the current request URL into the links, so an attacker-crafted link could plant arbitrary (escaped, but unwanted) parameters in the page; that no longer happens.
  - A database error during a catalog search is never shown to the visitor, even with `WP_DEBUG_DISPLAY` on; it is written to the Audit Log, at most once an hour.
  - The *Items per page* setting is clamped when read, so a damaged value can no longer cause a division-by-zero error on the catalog.
  - Sort direction is taken from the whitelisted sort value rather than the raw parameter; page numbers are capped at 10,000; guest page views no longer look up per-user notices.
- New tests in `tools/tests/` (development only, plain PHP, no WordPress needed): `catalog-search-test.php` (153 checks: SQL injection, XSS, traversal and command strings, wrong types, oversized and broken UTF-8 input, ORDER BY injection, LIKE wildcard escaping, the rate limit) and `catalog-render-test.php` (106 checks that render the catalog page with hostile query strings and inspect the HTML and the queries). Run each with `php tools/tests/<name>.php`.

### 1.3.7
- **Installation health** box at the top of *Library - Settings* (administrators only). It warns when the plugin was installed from a GitHub download or clone instead of a release zip (folder not named `church-library`, a second copy, a `.git` folder, files that are not part of this version), when protection files (`index.php`, `.htaccess`, `ABSPATH` checks) are missing, and checks PHP / WordPress / database versions, the library tables (present, InnoDB, schema version), the image upload folder, HTTPS, on-screen PHP errors and the lookup setup. **Run web checks** tests whether the server really refuses `README.md` and `.git/HEAD`, and whether outgoing https works. The Plugins screen shows a notice for a wrongly installed copy.
- Hardening for raw repository uploads: the top-level `.htaccess` now also refuses hidden paths such as `.git/HEAD` (file-name rules alone did not cover them) and more non-asset file types; `public/views/partials/` gets its `index.php` and `.htaccess`; nginx rules updated.
- README: how to report a security problem privately (sysop@maddogproductions.online), new [SECURITY.md](SECURITY.md), and *Building the install zip* (git archive or `php tools/build-zip.php`). `.gitattributes` keeps development files out of built zips.

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
