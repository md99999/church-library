<?php
/**
 * Builds an installable Church Library zip with nothing but PHP: no git, no
 * zip command, no dependencies. Handy on Windows.
 *
 *   php tools/build-zip.php                       writes ../church-library-<version>.zip
 *   php tools/build-zip.php C:\path\to\out.zip    writes that file instead
 *
 * It packs the working tree, so uncommitted edits are included (the difference
 * from `git archive`, which packs the last commit). Both give one top-level
 * folder named church-library, which is what Plugins > Add New > Upload Plugin
 * expects and what updates use.
 *
 * Before writing anything it checks that the files to pack match
 * CLIB_Health::MANIFEST, so the installation health check stays accurate, and
 * that every folder has its index.php.
 *
 * This file is development-only: it is left out of every release zip, and
 * tools/.htaccess denies web requests for it if a repository copy is uploaded.
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		header( 'HTTP/1.1 403 Forbidden' );
	}
	exit( 'CLI only.' );
}

/**
 * Print to stderr and stop.
 *
 * @param string $msg Message.
 */
function clib_build_fail( $msg ) {
	fwrite( STDERR, rtrim( $msg ) . "\n" );
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	clib_build_fail( "PHP's zip extension is not enabled, so this script cannot build the archive. Enable extension=zip in php.ini, or use the git archive command in README.md." );
}

$clib_root = realpath( dirname( __DIR__ ) );
$clib_slug = 'church-library';
if ( false === $clib_root || ! is_file( $clib_root . '/' . $clib_slug . '.php' ) ) {
	clib_build_fail( 'Run this from a Church Library checkout: church-library.php was not found next to tools/.' );
}

/* Version from the plugin header. */
$clib_header = (string) file_get_contents( $clib_root . '/' . $clib_slug . '.php', false, null, 0, 8192 );
if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/m', $clib_header, $clib_m ) ) {
	clib_build_fail( 'Could not read the Version: line from church-library.php.' );
}
$clib_version = $clib_m[1];

/* Output path: argument or ../church-library-<version>.zip. Never inside the plugin folder. */
$clib_out = ( isset( $argv[1] ) && '' !== trim( $argv[1] ) ) ? trim( $argv[1] ) : dirname( $clib_root ) . DIRECTORY_SEPARATOR . $clib_slug . '-' . $clib_version . '.zip';
if ( '.zip' !== strtolower( substr( $clib_out, -4 ) ) ) {
	clib_build_fail( 'The output file name must end in .zip.' );
}
$clib_out_dir = realpath( dirname( $clib_out ) );
if ( false === $clib_out_dir || ! is_dir( $clib_out_dir ) ) {
	clib_build_fail( 'The output folder does not exist: ' . dirname( $clib_out ) );
}
$clib_out = $clib_out_dir . DIRECTORY_SEPARATOR . basename( $clib_out );
if ( 0 === strpos( $clib_out_dir . DIRECTORY_SEPARATOR, $clib_root . DIRECTORY_SEPARATOR ) ) {
	clib_build_fail( 'Write the zip outside the plugin folder, or it would end up inside the next build.' );
}

/* Anything that is not part of the plugin. Mirrors export-ignore in .gitattributes. */
$clib_skip_dirs  = array( '.git', '.github', '.idea', '.vscode', 'node_modules', 'vendor', 'tools', 'tests' );
$clib_skip_files = array( '.gitignore', '.gitattributes', '.DS_Store', 'Thumbs.db', 'desktop.ini', '.editorconfig' );
$clib_skip_exts  = array( 'zip', 'log', 'swp', 'bak', 'orig', 'rej', 'tmp' );

$clib_iter = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $clib_root, FilesystemIterator::SKIP_DOTS ),
		function ( $file ) use ( $clib_skip_dirs ) {
			return ! ( $file->isDir() && in_array( $file->getFilename(), $clib_skip_dirs, true ) );
		}
	),
	RecursiveIteratorIterator::SELF_FIRST
);

$clib_files = array();
$clib_dirs  = array();
foreach ( $clib_iter as $clib_file ) {
	$clib_rel = str_replace( DIRECTORY_SEPARATOR, '/', substr( $clib_file->getPathname(), strlen( $clib_root ) + 1 ) );
	if ( $clib_file->isLink() ) {
		clib_build_fail( "Refusing to pack a link: $clib_rel" );
	}
	if ( $clib_file->isDir() ) {
		$clib_dirs[] = $clib_rel . '/';
		continue;
	}
	if ( in_array( $clib_file->getFilename(), $clib_skip_files, true ) ) {
		continue;
	}
	if ( in_array( strtolower( $clib_file->getExtension() ), $clib_skip_exts, true ) ) {
		continue;
	}
	$clib_files[ $clib_rel ] = $clib_file->getPathname();
}
ksort( $clib_files );
sort( $clib_dirs );

/* Every folder needs its silence file. */
$clib_problems = array();
foreach ( $clib_dirs as $clib_d ) {
	if ( ! isset( $clib_files[ $clib_d . 'index.php' ] ) ) {
		$clib_problems[] = "folder without index.php: $clib_d";
	}
}

/* Compare with CLIB_Health::MANIFEST (read as text; the class needs WordPress to load). */
$clib_health = (string) file_get_contents( $clib_root . '/includes/class-clib-health.php' );
if ( ! preg_match( '/const MANIFEST = array\((.*?)\);/s', $clib_health, $clib_mm ) || ! preg_match_all( "/'([^']+)'/", $clib_mm[1], $clib_entries ) ) {
	clib_build_fail( 'Could not read CLIB_Health::MANIFEST from includes/class-clib-health.php.' );
}
$clib_expected = $clib_entries[1];
$clib_packed   = array_merge( array_keys( $clib_files ), $clib_dirs );
foreach ( array_diff( $clib_expected, $clib_packed ) as $clib_p ) {
	$clib_problems[] = "in the manifest but not packed: $clib_p";
}
foreach ( array_diff( $clib_packed, $clib_expected ) as $clib_p ) {
	$clib_problems[] = "packed but not in the manifest: $clib_p";
}
if ( $clib_problems ) {
	clib_build_fail( "Not building: the zip would not match CLIB_Health::MANIFEST, so the installation health check would be wrong.\n  " . implode( "\n  ", $clib_problems ) . "\nUpdate const MANIFEST in includes/class-clib-health.php (or add the missing index.php) and build again." );
}

/* Write to a temporary file first, then move it into place, so a failed build never leaves a half zip. */
$clib_tmp = $clib_out . '.tmp-' . getmypid();
$clib_zip = new ZipArchive();
if ( true !== $clib_zip->open( $clib_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	clib_build_fail( "Could not create $clib_tmp" );
}
$clib_zip->addEmptyDir( $clib_slug );
foreach ( $clib_dirs as $clib_d ) {
	$clib_zip->addEmptyDir( $clib_slug . '/' . rtrim( $clib_d, '/' ) );
}
foreach ( $clib_files as $clib_rel => $clib_path ) {
	if ( ! $clib_zip->addFile( $clib_path, $clib_slug . '/' . $clib_rel ) ) {
		$clib_zip->close();
		@unlink( $clib_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		clib_build_fail( "Could not add $clib_rel" );
	}
}
if ( ! $clib_zip->close() ) {
	@unlink( $clib_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	clib_build_fail( "Could not write $clib_tmp" );
}
if ( file_exists( $clib_out ) && ! unlink( $clib_out ) ) {
	@unlink( $clib_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	clib_build_fail( "Could not replace $clib_out" );
}
if ( ! rename( $clib_tmp, $clib_out ) ) {
	@unlink( $clib_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	clib_build_fail( "Could not move the zip to $clib_out" );
}

printf( "%s\n%d files, %.1f KB, version %s\n", $clib_out, count( $clib_files ), filesize( $clib_out ) / 1024, $clib_version );
