<?php
/**
 * Uninstall file for Pete Panel Site Converter
 *
 * Runs when the plugin is deleted via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------
// 1) Delete plugin-related transients
// ---------------------------------------------------------------------

// Job state transients
// We intentionally use direct SQL here because WordPress provides no API to bulk-delete transients by wildcard.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_pete_export_job_%',
		'_transient_timeout_pete_export_job_%'
	)
);

// Download token transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_pete_export_id_%',
		'_transient_timeout_pete_export_id_%'
	)
);

// ---------------------------------------------------------------------
// 2) Remove export directory inside uploads (EXTRA CAUTIOUS)
// ---------------------------------------------------------------------

$ppsc_uploads      = wp_upload_dir();
$ppsc_uploads_base = isset( $ppsc_uploads['basedir'] ) ? $ppsc_uploads['basedir'] : '';

if ( empty( $ppsc_uploads_base ) || ! is_dir( $ppsc_uploads_base ) ) {
	// If uploads is not available, do nothing.
	return;
}

$ppsc_expected_folder = 'pete-panel-site-converter';
$ppsc_target_dir      = trailingslashit( $ppsc_uploads_base ) . $ppsc_expected_folder;

// Resolve real paths (prevents path traversal or symlink surprises)
$ppsc_uploads_real = realpath( $ppsc_uploads_base );
$ppsc_target_real  = realpath( $ppsc_target_dir );

// If folder doesn't exist (yet), nothing to do.
if ( $ppsc_target_real === false || ! is_dir( $ppsc_target_real ) ) {
	return;
}

// Normalize slashes
$ppsc_uploads_real = rtrim( str_replace( '\\', '/', $ppsc_uploads_real ), '/' );
$ppsc_target_real  = rtrim( str_replace( '\\', '/', $ppsc_target_real ), '/' );

// HARD SAFETY CHECKS:
// 1) Target must be inside uploads
// 2) Target must NOT equal uploads root
// 3) Target must end with the expected folder name exactly
if ( strpos( $ppsc_target_real, $ppsc_uploads_real . '/' ) !== 0 ) {
	return; // not inside uploads
}
if ( $ppsc_target_real === $ppsc_uploads_real ) {
	return; // never delete uploads root
}
if ( basename( $ppsc_target_real ) !== $ppsc_expected_folder ) {
	return; // must match exactly
}

// Initialize WP_Filesystem so Plugin Check does not flag direct filesystem operations.
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;

if ( ! isset( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
	// If filesystem cannot be initialized, fail safely without deleting anything.
	return;
}

/**
 * Recursively delete directory contents safely.
 * - Only touches paths inside $ppsc_target_real.
 * - Optionally restrict deletions to known file types.
 */
$ppsc_allowed_extensions = array(
	'zip',
	'sql',
	'txt',
	'log',
	'json',
	'htaccess', // sometimes seen as extensionless but kept here for clarity
);

$ppsc_allowed_filenames = array(
	'.htaccess',
	'index.html',
	'index.php',
);

$ppsc_delete_tree = function ( $dir ) use ( &$ppsc_delete_tree, $ppsc_target_real, $ppsc_allowed_extensions, $ppsc_allowed_filenames, $wp_filesystem ) {
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( $item === '.' || $item === '..' ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;

		// Resolve and normalize
		$path_real = realpath( $path );
		if ( $path_real === false ) {
			// Might be a broken symlink; skip for safety.
			continue;
		}
		$path_real = rtrim( str_replace( '\\', '/', $path_real ), '/' );

		// Ensure we never operate outside target folder (symlink defense)
		if ( strpos( $path_real, $ppsc_target_real . '/' ) !== 0 && $path_real !== $ppsc_target_real ) {
			continue;
		}

		if ( is_dir( $path_real ) ) {
			$ppsc_delete_tree( $path_real );
			$wp_filesystem->rmdir( $path_real );
			continue;
		}

		// File deletion restrictions (extra caution)
		$base = basename( $path_real );
		$ext  = strtolower( pathinfo( $path_real, PATHINFO_EXTENSION ) );

		$ok_name = in_array( $base, $ppsc_allowed_filenames, true );
		$ok_ext  = ( $ext !== '' && in_array( $ext, $ppsc_allowed_extensions, true ) );

		// If it doesn't match allowed patterns, skip deletion.
		if ( ! $ok_name && ! $ok_ext ) {
			continue;
		}

		wp_delete_file( $path_real );
	}
};

// Delete contents first, then remove the directory
$ppsc_delete_tree( $ppsc_target_real );
$wp_filesystem->rmdir( $ppsc_target_real );