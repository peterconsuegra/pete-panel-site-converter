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

$pete_psc_uploads      = wp_upload_dir();
$pete_psc_uploads_base = isset( $pete_psc_uploads['basedir'] ) ? $pete_psc_uploads['basedir'] : '';

if ( empty( $pete_psc_uploads_base ) || ! is_dir( $pete_psc_uploads_base ) ) {
	return;
}

$pete_psc_expected_folder = 'pete-panel-site-converter';
$pete_psc_target_dir      = trailingslashit( $pete_psc_uploads_base ) . $pete_psc_expected_folder;

$pete_psc_uploads_real = realpath( $pete_psc_uploads_base );
$pete_psc_target_real  = realpath( $pete_psc_target_dir );

if ( $pete_psc_target_real === false || ! is_dir( $pete_psc_target_real ) ) {
	return;
}

$pete_psc_uploads_real = rtrim( str_replace( '\\', '/', $pete_psc_uploads_real ), '/' );
$pete_psc_target_real  = rtrim( str_replace( '\\', '/', $pete_psc_target_real ), '/' );

if ( strpos( $pete_psc_target_real, $pete_psc_uploads_real . '/' ) !== 0 ) {
	return;
}

if ( $pete_psc_target_real === $pete_psc_uploads_real ) {
	return;
}

if ( basename( $pete_psc_target_real ) !== $pete_psc_expected_folder ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;

if ( ! isset( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
	return;
}

$pete_psc_allowed_extensions = array(
	'zip',
	'sql',
	'txt',
	'log',
	'json',
	'htaccess',
);

$pete_psc_allowed_filenames = array(
	'.htaccess',
	'index.html',
	'index.php',
);

$pete_psc_delete_tree = function ( $dir ) use (
	&$pete_psc_delete_tree,
	$pete_psc_target_real,
	$pete_psc_allowed_extensions,
	$pete_psc_allowed_filenames,
	$wp_filesystem
) {

	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( $item === '.' || $item === '..' ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;

		$path_real = realpath( $path );
		if ( $path_real === false ) {
			continue;
		}

		$path_real = rtrim( str_replace( '\\', '/', $path_real ), '/' );

		if ( strpos( $path_real, $pete_psc_target_real . '/' ) !== 0 && $path_real !== $pete_psc_target_real ) {
			continue;
		}

		if ( is_dir( $path_real ) ) {
			$pete_psc_delete_tree( $path_real );
			$wp_filesystem->rmdir( $path_real );
			continue;
		}

		$base = basename( $path_real );
		$ext  = strtolower( pathinfo( $path_real, PATHINFO_EXTENSION ) );

		$ok_name = in_array( $base, $pete_psc_allowed_filenames, true );
		$ok_ext  = ( $ext !== '' && in_array( $ext, $pete_psc_allowed_extensions, true ) );

		if ( ! $ok_name && ! $ok_ext ) {
			continue;
		}

		wp_delete_file( $path_real );
	}
};

$pete_psc_delete_tree( $pete_psc_target_real );
$wp_filesystem->rmdir( $pete_psc_target_real );