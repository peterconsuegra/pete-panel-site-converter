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
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_pete_export_job_%'
        OR option_name LIKE '_transient_timeout_pete_export_job_%'"
);

// Download token transients
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_pete_export_id_%'
        OR option_name LIKE '_transient_timeout_pete_export_id_%'"
);

// ---------------------------------------------------------------------
// 2) Remove export directory inside uploads (EXTRA CAUTIOUS)
// ---------------------------------------------------------------------

$uploads = wp_upload_dir();
$uploads_base = isset($uploads['basedir']) ? $uploads['basedir'] : '';

if ( empty( $uploads_base ) || ! is_dir( $uploads_base ) ) {
    // If uploads is not available, do nothing.
    return;
}

$expected_folder = 'pete-panel-site-converter';
$target_dir      = trailingslashit( $uploads_base ) . $expected_folder;

// Resolve real paths (prevents path traversal or symlink surprises)
$uploads_real = realpath( $uploads_base );
$target_real  = realpath( $target_dir );

// If folder doesn't exist (yet), nothing to do.
if ( $target_real === false || ! is_dir( $target_real ) ) {
    return;
}

// Normalize slashes
$uploads_real = rtrim( str_replace('\\', '/', $uploads_real ), '/' );
$target_real  = rtrim( str_replace('\\', '/', $target_real ), '/' );

// HARD SAFETY CHECKS:
// 1) Target must be inside uploads
// 2) Target must NOT equal uploads root
// 3) Target must end with the expected folder name exactly
if ( strpos( $target_real, $uploads_real . '/' ) !== 0 ) {
    return; // not inside uploads
}
if ( $target_real === $uploads_real ) {
    return; // never delete uploads root
}
if ( basename( $target_real ) !== $expected_folder ) {
    return; // must match exactly
}

/**
 * Recursively delete directory contents safely.
 * - Only touches paths inside $target_real.
 * - Optionally restrict deletions to known file types.
 */
$allowed_extensions = array(
    'zip', 'sql', 'txt', 'log', 'json',
    'htaccess', // sometimes seen as extensionless but kept here for clarity
);
$allowed_filenames = array(
    '.htaccess',
    'index.html',
    'index.php',
);

$delete_tree = function( $dir ) use ( &$delete_tree, $target_real, $allowed_extensions, $allowed_filenames ) {
    $items = @scandir( $dir );
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
        $path_real = rtrim( str_replace('\\', '/', $path_real ), '/' );

        // Ensure we never operate outside target folder (symlink defense)
        if ( strpos( $path_real, $target_real . '/' ) !== 0 && $path_real !== $target_real ) {
            continue;
        }

        if ( is_dir( $path_real ) ) {
            $delete_tree( $path_real );
            @rmdir( $path_real );
            continue;
        }

        // File deletion restrictions (extra caution)
        $base = basename( $path_real );
        $ext  = strtolower( pathinfo( $path_real, PATHINFO_EXTENSION ) );

        $ok_name = in_array( $base, $allowed_filenames, true );
        $ok_ext  = ( $ext !== '' && in_array( $ext, $allowed_extensions, true ) );

        // If it doesn't match allowed patterns, skip deletion.
        if ( ! $ok_name && ! $ok_ext ) {
            continue;
        }

        @unlink( $path_real );
    }
};

// Delete contents first, then remove the directory
$delete_tree( $target_real );
@rmdir( $target_real );