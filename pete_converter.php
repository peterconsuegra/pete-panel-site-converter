<?php
/*
Plugin Name:       Site Migration & Backup Export (Pete Panel)
Plugin URI:        https://wordpress.org/plugins/site-migration-backup-export-pete-panel/
Description:       Export a WordPress site into a Pete Panel-compatible archive (files + database dump) for migration or cloning.
Version:           1.0.0
Requires at least: 5.8
Requires PHP:      7.4
Author:            Pedro Consuegra
Author URI:        https://deploypete.com
License:           GPLv3 or later
License URI:       https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:       site-migration-backup-export-pete-panel
Domain Path:       /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------

define( 'PETE_PSC_SLUG', 'site-migration-backup-export-pete-panel' );
define( 'PETE_PSC_VERSION', '1.0.0' ); // Used for enqueued asset versions.
define( 'PETE_PSC_UPLOAD_SUBDIR', 'pete-panel-site-converter' ); // uploads/{this}/ (kept for backwards compatibility)
define( 'PETE_PSC_ADMIN_JS_HANDLE', 'pete-psc-admin' );
define( 'PETE_PSC_ADMIN_CSS_HANDLE', 'pete-psc-admin-css' );

// Ensure get_home_path() and WP_Filesystem helpers exist.
require_once ABSPATH . 'wp-admin/includes/file.php';

// Bundled library
include_once dirname( __FILE__ ) . '/bin/Mysqldump.php';

// ---------------------------------------------------------------------
// Distribution integrity checks (helps catch missing files in the plugin ZIP)
// ---------------------------------------------------------------------

/**
 * Returns an array of required paths (relative to plugin root) and friendly labels.
 *
 * @return array<string,string>
 */
function pete_psc_required_distribution_items() {
	return array(
		'petefaceicon.png'     => 'petefaceicon.png (admin menu icon)',
		'licenses/GPL-3.0.txt' => 'licenses/GPL-3.0.txt (license file referenced in readme)',
	);
}

/**
 * Validate that required distribution files exist inside the plugin folder.
 * - languages/ can be created automatically.
 * - license/icon should be shipped (we warn if missing).
 *
 * @return array{missing: string[], created: string[]}
 */
function pete_psc_validate_distribution_files() {
	$plugin_root = trailingslashit( plugin_dir_path( __FILE__ ) );
	$req         = pete_psc_required_distribution_items();

	$missing = array();
	$created = array();

	// Ensure languages dir exists (can be auto-created).
	$lang_dir = $plugin_root . 'languages';
	if ( ! is_dir( $lang_dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $lang_dir );
		}
		// Do not report languages/ as "created" — it's optional and often absent in ZIPs.
	}

	foreach ( $req as $rel => $label ) {
		$abs = $plugin_root . ltrim( $rel, '/' );

		if ( $rel === 'languages' ) {
			if ( ! is_dir( $abs ) ) {
				$missing[] = $label;
			}
			continue;
		}

		if ( ! file_exists( $abs ) ) {
			$missing[] = $label;
		}
	}

	return array(
		'missing' => $missing,
		'created' => $created,
	);
}

/**
 * Store any missing distribution items for admin notices.
 *
 * @return void
 */
function pete_psc_run_distribution_checks_and_store() {
	$res = pete_psc_validate_distribution_files();

	// Always store (short TTL). If nothing missing, store empty.
	set_transient(
		'pete_psc_dist_missing',
		array(
			'missing' => $res['missing'],
			'created' => $res['created'],
			'ts'      => time(),
		),
		6 * HOUR_IN_SECONDS
	);
}

/**
 * Admin notice if packaging/distribution files are missing.
 *
 * @return void
 */
function pete_psc_admin_notice_distribution_missing() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$data = get_transient( 'pete_psc_dist_missing' );
	if ( ! is_array( $data ) ) {
		return;
	}

	$missing = isset( $data['missing'] ) && is_array( $data['missing'] ) ? $data['missing'] : array();
	$created = isset( $data['created'] ) && is_array( $data['created'] ) ? $data['created'] : array();

	// Only show if there is something to say.
	if ( empty( $missing ) && empty( $created ) ) {
		return;
	}

	// Show as warning if only created languages; error if missing critical shipped files.
	$is_error = ! empty( $missing );
	$class    = $is_error ? 'notice notice-error' : 'notice notice-warning';

	echo '<div class="' . esc_attr( $class ) . '"><p>';
	echo '<strong>' . esc_html__( 'Pete Panel Site Converter:', 'site-migration-backup-export-pete-panel' ) . '</strong> ';

	if ( ! empty( $created ) ) {
		echo esc_html__( 'Created missing directories:', 'site-migration-backup-export-pete-panel' ) . ' ';
		echo '<code>' . esc_html( implode( ', ', $created ) ) . '</code>. ';
	}

	if ( ! empty( $missing ) ) {
		echo esc_html__( 'Some required plugin files are missing from this installation (likely not included in the plugin ZIP). Please re-upload/reinstall the plugin including:', 'site-migration-backup-export-pete-panel' );
		echo '</p><ul style="margin-left: 1.2em; list-style: disc;">';
		foreach ( $missing as $m ) {
			echo '<li><code>' . esc_html( $m ) . '</code></li>';
		}
		echo '</ul><p>';
	}

	echo esc_html__( 'This does not stop exports, but it indicates an incomplete plugin package.', 'site-migration-backup-export-pete-panel' );
	echo '</p></div>';
}

// Run checks on admin load (helps catch missing files immediately after install/update).
add_action(
	'admin_init',
	function () {
		pete_psc_run_distribution_checks_and_store();
	}
);

add_action( 'admin_notices', 'pete_psc_admin_notice_distribution_missing' );

// ---------------------------------------------------------------------
// i18n + languages folder presence
// ---------------------------------------------------------------------

/**
 * Ensure /languages exists (repo + runtime expectation).
 *
 * We create it:
 * - on activation (best)
 * - and also on plugins_loaded (fallback if activation hook didn't run)
 */
function pete_psc_ensure_languages_dir() {
	$dir = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'languages';
	if ( ! is_dir( $dir ) ) {
		// wp_mkdir_p is safe and recursive.
		wp_mkdir_p( $dir );
	}
}

register_activation_hook(
	__FILE__,
	function () {
		pete_psc_ensure_languages_dir();
		// Run distribution checks on activation too (helps catch missing packaged files).
		pete_psc_run_distribution_checks_and_store();
	}
);

/**
 * Ensure /languages exists early.
 *
 * Note: When hosted on WordPress.org, translations under the plugin slug
 * are loaded automatically by WordPress. No manual load_plugin_textdomain()
 * call is needed.
 */
add_action(
	'plugins_loaded',
	function () {
		pete_psc_ensure_languages_dir();
	}
);

// ---------------------------------------------------------------------
// Utilities (logging + safe wrappers)
// ---------------------------------------------------------------------

if ( ! function_exists( 'pete_psc_log' ) ) {
	/**
	 * Lightweight debug logger.
	 *
	 * @param string $msg
	 * @param array  $ctx
	 */
	function pete_psc_log( $msg, array $ctx = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! apply_filters( 'pete_psc_enable_logging', true ) ) {
			return;
		}

		$prefix = '[Pete Converter] ';
		if ( ! empty( $ctx ) ) {
			$msg .= ' | ' . wp_json_encode( $ctx );
		}

		/**
		 * Fires when the plugin wants to log a debug message.
		 *
		 * @param string $message Full message, including prefix.
		 * @param array  $context Context array.
		 */
		do_action( 'pete_psc_log', $prefix . $msg, $ctx );
	}
}

if ( ! function_exists( 'pete_psc_die' ) ) {
	/**
	 * Proper wp_die() wrapper with HTTP response codes.
	 *
	 * @param string $message
	 * @param int    $code
	 * @param string $title
	 */
	function pete_psc_die( $message, $code = 403, $title = '' ) {
		$code = absint( $code );
		if ( $code < 100 || $code > 599 ) {
			$code = 500;
		}

		$title = (string) $title;

		if ( '' === $title ) {
			$title = ( $code >= 400 )
				? __( 'Error', 'site-migration-backup-export-pete-panel' )
				: __( 'Notice', 'site-migration-backup-export-pete-panel' );
		}

		$args = array(
			'response' => (int) $code,
		);

		wp_die(
			esc_html( (string) $message ),
			esc_html( $title ),
			$args
		);
	}
}

/**
 * Convert an absolute path into a nicer display path.
 * Prefer showing relative to ABSPATH when possible.
 *
 * @param string $abs
 * @return string
 */
function pete_psc_pretty_path( $abs ) {
	$abs = (string) $abs;
	if ( $abs === '' ) {
		return '';
	}

	$abs_norm  = wp_normalize_path( $abs );
	$root_norm = wp_normalize_path( trailingslashit( ABSPATH ) );

	if ( strpos( $abs_norm, $root_norm ) === 0 ) {
		$rel = ltrim( substr( $abs_norm, strlen( $root_norm ) ), '/' );
		return '/' . $rel;
	}

	return $abs_norm;
}

/**
 * Initialize and return WP_Filesystem if available.
 *
 * @return WP_Filesystem_Base|null
 */
function pete_psc_get_filesystem() {
	global $wp_filesystem;

	if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
		return $wp_filesystem;
	}

	if ( function_exists( 'WP_Filesystem' ) ) {
		$ok = WP_Filesystem();
		if ( $ok && isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
			return $wp_filesystem;
		}
	}

	return null;
}

/**
 * Safe realpath wrapper with logging.
 *
 * @param string $path
 * @param string $label
 * @return string Empty string if not resolvable.
 */
function pete_psc_realpath( $path, $label = '' ) {
	$path = (string) $path;
	if ( $path === '' ) {
		return '';
	}

	$rp = realpath( $path );
	if ( $rp === false ) {
		pete_psc_log(
			'realpath() failed',
			array(
				'label' => (string) $label,
				'path'  => $path,
			)
		);
		return '';
	}

	return $rp;
}

/**
 * Ensure a directory exists.
 *
 * @param string $dir
 * @param string $label
 * @return bool
 */
function pete_psc_ensure_dir( $dir, $label = '' ) {
	$dir = (string) $dir;
	if ( $dir === '' ) {
		return false;
	}

	if ( is_dir( $dir ) ) {
		return true;
	}

	$ok = wp_mkdir_p( $dir );
	if ( ! $ok ) {
		pete_psc_log(
			'wp_mkdir_p failed',
			array(
				'label' => (string) $label,
				'dir'   => $dir,
			)
		);
	}
	return (bool) $ok;
}

/**
 * Safe file_put_contents with logging.
 *
 * @param string $path
 * @param string $contents
 * @param string $label
 * @return bool
 */
function pete_psc_file_put_contents( $path, $contents, $label = '' ) {
	$path = (string) $path;

	$dir = dirname( $path );
	if ( ! pete_psc_ensure_dir( $dir, $label . ':ensure_dir' ) ) {
		return false;
	}

	$fs = pete_psc_get_filesystem();
	if ( $fs ) {
		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false;

		$ok = $fs->put_contents( $path, $contents, $chmod );
		if ( ! $ok ) {
			pete_psc_log(
				'WP_Filesystem put_contents failed; falling back to file_put_contents',
				array(
					'label' => (string) $label,
					'path'  => $path,
				)
			);
		} else {
			return true;
		}
	}

	$bytes = file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
	if ( $bytes === false ) {
		pete_psc_log(
			'file_put_contents failed',
			array(
				'label' => (string) $label,
				'path'  => $path,
			)
		);
		return false;
	}

	return true;
}

/**
 * Safe unlink with logging.
 *
 * @param string $path
 * @param string $label
 * @return bool
 */
function pete_psc_unlink( $path, $label = '' ) {
	$path = (string) $path;
	if ( $path === '' ) {
		return false;
	}
	if ( ! file_exists( $path ) ) {
		return true;
	}

	$fs = pete_psc_get_filesystem();
	if ( $fs ) {
		$ok = $fs->delete( $path, false, 'f' );
		if ( $ok ) {
			return true;
		}
		pete_psc_log(
			'WP_Filesystem delete(file) failed; falling back to unlink',
			array(
				'label' => (string) $label,
				'path'  => $path,
			)
		);
	}

	$ok = unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	if ( ! $ok ) {
		pete_psc_log(
			'unlink failed',
			array(
				'label' => (string) $label,
				'path'  => $path,
			)
		);
	}
	return (bool) $ok;
}

/**
 * Safe file size with logging.
 *
 * @param string $path
 * @param string $label
 * @return int
 */
function pete_psc_filesize( $path, $label = '' ) {
	$path = (string) $path;
	if ( $path === '' || ! file_exists( $path ) ) {
		return 0;
	}

	$sz = filesize( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
	if ( $sz === false ) {
		pete_psc_log(
			'filesize failed',
			array(
				'label' => (string) $label,
				'path'  => $path,
			)
		);
		return 0;
	}

	return (int) $sz;
}

/**
 * Robust file streaming for downloads.
 *
 * @param string $path
 * @return int Bytes sent (best-effort).
 */
function pete_psc_stream_file_to_output( $path ) {
	$path = (string) $path;

	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		pete_psc_log( 'fopen failed for download stream', array( 'path' => $path ) );
		return 0;
	}

	$sent = 0;
	while ( ! feof( $fh ) ) {
		$buf = fread( $fh, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $buf === false ) {
			pete_psc_log( 'fread failed while streaming download', array( 'path' => $path, 'sent' => $sent ) );
			break;
		}
		$len = strlen( $buf );
		if ( $len === 0 ) {
			break;
		}
		echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$sent += $len;

		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return (int) $sent;
}

/**
 * Build a safe export filename for the download.
 *
 * @param string $job_id
 * @return string
 */
function pete_psc_build_download_filename( $job_id ) {
	$job_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $job_id );
	if ( $job_id === '' ) {
		$job_id = 'job';
	}
	$ts = gmdate( 'Y-m-d-His' );
	return 'massive_file-' . $job_id . '-' . $ts . '.zip';
}

/**
 * Build a nonce-protected download URL WITHOUT HTML-escaping.
 *
 * @param string $base_url
 * @param string $action_key
 * @return string
 */
function pete_psc_build_nonce_download_url( $base_url, $action_key ) {
	$base_url = (string) $base_url;
	if ( $base_url === '' ) {
		return '';
	}
	$nonce = wp_create_nonce( $action_key );
	return add_query_arg( '_wpnonce', $nonce, $base_url );
}

/**
 * Return an array of default exclusions for filesystem export.
 *
 * @return array{basenames: string[], rel_prefixes: string[]}
 */
function pete_psc_get_default_export_excludes() {
	$excludes = array(
		'basenames'    => array(
			'wp-config.php',
			'.env',
			'.htaccess',
			'error_log',
			'.DS_Store',
			'.gitignore',
		),
		'rel_prefixes' => array(
			'.git/',
			'.svn/',
			'.hg/',
			'node_modules/',
			'vendor/',
			'wp-content/cache/',
			'wp-content/upgrade/',
			'wp-content/backup/',
			'wp-content/backups/',
			'wp-content/ai1wm-backups/',
			'wp-content/updraft/',
			'wp-content/wpvividbackups/',
			'wp-content/uploads/backup',
			'wp-content/uploads/backups',
		),
	);

	return apply_filters( 'pete_psc_export_excludes', $excludes );
}

/**
 * Determine whether a given relative path should be excluded.
 *
 * @param string $rel
 * @param array  $excludes
 * @return bool
 */
function pete_psc_is_excluded_relpath( $rel, array $excludes ) {
	$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );

	$base = basename( $rel );
	if ( ! empty( $excludes['basenames'] ) && in_array( $base, (array) $excludes['basenames'], true ) ) {
		return true;
	}

	if ( ! empty( $excludes['rel_prefixes'] ) ) {
		foreach ( (array) $excludes['rel_prefixes'] as $prefix ) {
			$prefix = ltrim( str_replace( '\\', '/', (string) $prefix ), '/' );
			if ( $prefix !== '' && strpos( $rel, $prefix ) === 0 ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Decide if a file should be included, returning its relative path if included.
 *
 * @param string $full_real
 * @param string $site_root_real
 * @param string $plugin_norm
 * @param string $export_norm
 * @param array  $excludes
 * @return string|false
 */
function pete_psc_export_relpath_if_included( $full_real, $site_root_real, $plugin_norm, $export_norm, array $excludes ) {
	$full_real = wp_normalize_path( (string) $full_real );

	$full_norm_slash = trailingslashit( $full_real );
	if ( strpos( $full_norm_slash, $site_root_real ) !== 0 ) {
		return false;
	}

	if ( $plugin_norm && strpos( $full_norm_slash, $plugin_norm ) === 0 ) {
		return false;
	}
	if ( $export_norm && strpos( $full_norm_slash, $export_norm ) === 0 ) {
		return false;
	}

	$rel = ltrim( substr( $full_real, strlen( $site_root_real ) ), '/' );
	$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );

	if ( pete_psc_is_excluded_relpath( $rel, $excludes ) ) {
		return false;
	}

	return $rel;
}

/**
 * Count how many files will be added to the ZIP (for accurate progress).
 *
 * @param string $site_root
 * @param string $exclude_plugin_dir_real
 * @param string $exclude_export_dir_real
 * @return int
 */
function pete_psc_count_site_files_for_zip( $site_root, $exclude_plugin_dir_real = '', $exclude_export_dir_real = '' ) {
	$site_root      = trailingslashit( (string) $site_root );
	$site_root_real = pete_psc_realpath( $site_root, 'count_site_root' );
	if ( ! $site_root_real ) {
		return 0;
	}
	$site_root_real = trailingslashit( wp_normalize_path( $site_root_real ) );

	$plugin_norm = $exclude_plugin_dir_real ? trailingslashit( wp_normalize_path( (string) $exclude_plugin_dir_real ) ) : '';
	$export_norm = $exclude_export_dir_real ? trailingslashit( wp_normalize_path( (string) $exclude_export_dir_real ) ) : '';

	$excludes = pete_psc_get_default_export_excludes();
	$count    = 0;

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $site_root_real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $it as $info ) {
		/** @var SplFileInfo $info */
		$full = $info->getPathname();

		if ( is_link( $full ) ) {
			continue;
		}

		if ( $info->isDir() ) {
			continue;
		}

		$full_real = pete_psc_realpath( $full, 'count_item' );
		if ( ! $full_real ) {
			continue;
		}

		$rel = pete_psc_export_relpath_if_included( $full_real, $site_root_real, $plugin_norm, $export_norm, $excludes );
		if ( false === $rel ) {
			continue;
		}

		$count++;
	}

	return (int) $count;
}

/**
 * Add site files directly into an open ZipArchive under a given prefix.
 *
 * Progress cb signature: function(int $added, int $total): void
 *
 * @param ZipArchive    $zip
 * @param string        $site_root
 * @param string        $zip_prefix
 * @param string        $exclude_plugin_dir_real
 * @param string        $exclude_export_dir_real
 * @param callable|null $progress_cb
 * @param int           $total_files
 * @return int
 * @throws Exception
 */
function pete_psc_zip_site_root( $zip, $site_root, $zip_prefix, $exclude_plugin_dir_real = '', $exclude_export_dir_real = '', $progress_cb = null, $total_files = 0 ) {
	$site_root      = trailingslashit( (string) $site_root );
	$site_root_real = pete_psc_realpath( $site_root, 'zip_site_root' );
	if ( ! $site_root_real ) {
		throw new Exception( esc_html__( 'Could not resolve site root realpath.', 'site-migration-backup-export-pete-panel' ) );
	}
	$site_root_real = trailingslashit( wp_normalize_path( $site_root_real ) );

	$plugin_norm = $exclude_plugin_dir_real ? trailingslashit( wp_normalize_path( (string) $exclude_plugin_dir_real ) ) : '';
	$export_norm = $exclude_export_dir_real ? trailingslashit( wp_normalize_path( (string) $exclude_export_dir_real ) ) : '';

	$excludes = pete_psc_get_default_export_excludes();
	$added    = 0;

	$last_cb_time = microtime( true );
	$cb_min_sec   = 0.5;
	$cb_every_n   = 50;

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $site_root_real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $it as $info ) {
		/** @var SplFileInfo $info */
		$full = $info->getPathname();

		if ( is_link( $full ) ) {
			continue;
		}

		$full_real = pete_psc_realpath( $full, 'zip_item' );
		if ( ! $full_real ) {
			continue;
		}
		$full_real = wp_normalize_path( $full_real );

		$rel = pete_psc_export_relpath_if_included( $full_real, $site_root_real, $plugin_norm, $export_norm, $excludes );
		if ( false === $rel ) {
			continue;
		}

		$zip_path = rtrim( (string) $zip_prefix, '/' ) . '/' . $rel;

		if ( $info->isDir() ) {
			$zip->addEmptyDir( rtrim( $zip_path, '/' ) );
			continue;
		}

		if ( ! $zip->addFile( $full_real, $zip_path ) ) {
			pete_psc_log( 'ZipArchive addFile failed', array( 'full' => $full_real, 'zip_path' => $zip_path ) );
			continue;
		}

		$added++;

		if ( is_callable( $progress_cb ) ) {
			$now = microtime( true );
			if ( ( $added % $cb_every_n ) === 0 || ( $now - $last_cb_time ) >= $cb_min_sec || ( $total_files > 0 && $added >= $total_files ) ) {
				$last_cb_time = $now;
				call_user_func( $progress_cb, (int) $added, (int) $total_files );
			}
		}
	}

	if ( is_callable( $progress_cb ) ) {
		call_user_func( $progress_cb, (int) $added, (int) $total_files );
	}

	return (int) $added;
}

// ---------------------------------------------------------------------
// Admin Menu + Assets
// ---------------------------------------------------------------------

add_action(
	'admin_menu',
	function () {
		$icon_abs = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'petefaceicon.png';
		$icon     = file_exists( $icon_abs ) ? plugins_url( 'petefaceicon.png', __FILE__ ) : 'dashicons-migrate';

		add_menu_page(
			__( 'Pete Panel Site Converter', 'site-migration-backup-export-pete-panel' ),
			__( 'Pete Converter', 'site-migration-backup-export-pete-panel' ),
			'manage_options',
			'pete-export-options',
			'pete_psc_export_view',
			$icon,
			6
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook_suffix ) {
		if ( $hook_suffix !== 'toplevel_page_pete-export-options' ) {
			return;
		}

		wp_enqueue_style(
			PETE_PSC_ADMIN_CSS_HANDLE,
			plugin_dir_url( __FILE__ ) . 'assets/admin-export.css',
			array(),
			PETE_PSC_VERSION
		);

		wp_enqueue_script(
			PETE_PSC_ADMIN_JS_HANDLE,
			plugin_dir_url( __FILE__ ) . 'assets/admin-export.js',
			array(),
			PETE_PSC_VERSION,
			true
		);

		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$start_url  = esc_url_raw( rest_url( 'pete/v1/export' ) );

		$i18n = array(
			'starting'          => __( 'Starting…', 'site-migration-backup-export-pete-panel' ),
			'queued'            => __( 'Queued…', 'site-migration-backup-export-pete-panel' ),
			'working'           => __( 'Working…', 'site-migration-backup-export-pete-panel' ),
			'running'           => __( 'Running…', 'site-migration-backup-export-pete-panel' ),
			'ready'             => __( 'Export ready.', 'site-migration-backup-export-pete-panel' ),
			'failed_start'      => __( 'Failed to start:', 'site-migration-backup-export-pete-panel' ),
			'export_failed'     => __( 'Export failed:', 'site-migration-backup-export-pete-panel' ),
			'error_prefix'      => __( 'Error:', 'site-migration-backup-export-pete-panel' ),
			'cron_blocked'      => __( 'Cron seems blocked. Running export directly…', 'site-migration-backup-export-pete-panel' ),
			'download_fallback' => __( 'Export finished, but download link is missing. Please refresh this page.', 'site-migration-backup-export-pete-panel' ),
			'download_default'  => __( 'Download export', 'site-migration-backup-export-pete-panel' ),
			'start'             => __( 'Start export', 'site-migration-backup-export-pete-panel' ),
		);

		wp_localize_script(
			PETE_PSC_ADMIN_JS_HANDLE,
			'PetePSC',
			array(
				'nonce'    => $rest_nonce,
				'startUrl' => $start_url,
				'restRoot' => esc_url_raw( rest_url() ),
				'i18n'     => $i18n,
			)
		);

		wp_add_inline_script(
			PETE_PSC_ADMIN_JS_HANDLE,
			'window.PetePSC = window.PetePSC || {};'
			. '(function(){'
			. 'var root = (window.PetePSC.restRoot || "/wp-json/");'
			. 'window.PetePSC.restRoot = (root && root.slice(-1) === "/") ? root : (root + "/");'
			. '})();',
			'before'
		);
	},
	10,
	1
);

function pete_psc_export_view() {
	if ( ! current_user_can( 'manage_options' ) ) {
		pete_psc_die( __( 'You do not have sufficient permissions to access this page.', 'site-migration-backup-export-pete-panel' ), 403, __( 'Forbidden', 'site-migration-backup-export-pete-panel' ) );
	}

	$dist    = get_transient( 'pete_psc_dist_missing' );
	$missing = ( is_array( $dist ) && ! empty( $dist['missing'] ) && is_array( $dist['missing'] ) ) ? $dist['missing'] : array();
	?>
	<div class="wrap pete-psc-wrap">
		<h1><?php echo esc_html__( 'Export site to Pete Panel format', 'site-migration-backup-export-pete-panel' ); ?></h1>

		<?php if ( ! empty( $missing ) ) : ?>
			<div class="notice notice-error">
				<p>
					<strong><?php echo esc_html__( 'Plugin package appears incomplete:', 'site-migration-backup-export-pete-panel' ); ?></strong>
					<?php echo esc_html__( 'Some required files are missing from this installation:', 'site-migration-backup-export-pete-panel' ); ?>
				</p>
				<ul style="margin-left:1.2em;list-style:disc;">
					<?php foreach ( $missing as $m ) : ?>
						<li><code><?php echo esc_html( $m ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<p><?php echo esc_html__( 'Reinstall the plugin from a ZIP that includes these items.', 'site-migration-backup-export-pete-panel' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="pete-psc-intro"><?php echo esc_html__( 'Click “Start export”. The job runs in the background - feel free to keep working.', 'site-migration-backup-export-pete-panel' ); ?></p>

		<details class="pete-psc-tip">
			<summary>
				<strong><?php echo esc_html__( 'Tip:', 'site-migration-backup-export-pete-panel' ); ?></strong>
				<?php echo esc_html__( 'If you ever hit time limits on some hosts', 'site-migration-backup-export-pete-panel' ); ?>
			</summary>
			<ol>
				<li><?php echo wp_kses_post( __( 'In <code>wp-config.php</code>: <code>set_time_limit(300);</code>', 'site-migration-backup-export-pete-panel' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'In <code>php.ini</code>: <code>max_execution_time = 300</code>', 'site-migration-backup-export-pete-panel' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'In <code>.htaccess</code>: <code>php_value max_execution_time 300</code>', 'site-migration-backup-export-pete-panel' ) ); ?></li>
			</ol>
		</details>

		<p class="pete-psc-security">
			<strong><?php echo esc_html__( 'Security note:', 'site-migration-backup-export-pete-panel' ); ?></strong>
			<?php echo esc_html__( 'Exports may include sensitive files if present in your site root. By default, this plugin excludes common secrets (like wp-config.php and .env) and common backup/cache folders. Review your archive contents before sharing it.', 'site-migration-backup-export-pete-panel' ); ?>
		</p>

		<button id="pete-start-export" class="button button-primary"><?php echo esc_html__( 'Start export', 'site-migration-backup-export-pete-panel' ); ?></button>

		<div id="pete-progress" class="pete-psc-progress">
			<div id="pete-progress-bar" class="pete-psc-progress-bar">
				<span id="pete-progress-fill" class="pete-psc-progress-fill"></span>
			</div>
			<p id="pete-progress-text" class="pete-psc-progress-text"><?php echo esc_html__( 'Queued…', 'site-migration-backup-export-pete-panel' ); ?></p>
		</div>

		<div id="pete-download" class="pete-psc-download"></div>
	</div>
	<?php
}

// ---------------------------------------------------------------------
// Secure download endpoint (admin only)
// ---------------------------------------------------------------------

add_action( 'admin_post_pete_download_export', 'pete_psc_handle_secure_download' );

add_action(
	'admin_post_nopriv_pete_download_export',
	function () {
		pete_psc_die( __( 'You must be logged in as an administrator to download this export.', 'site-migration-backup-export-pete-panel' ), 403, __( 'Forbidden', 'site-migration-backup-export-pete-panel' ) );
	}
);

function pete_psc_handle_secure_download() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		pete_psc_die( __( 'You must be an administrator to download this export.', 'site-migration-backup-export-pete-panel' ), 403, __( 'Forbidden', 'site-migration-backup-export-pete-panel' ) );
	}

	$q_raw = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$q     = preg_replace( '/[^A-Za-z0-9]/', '', $q_raw );
	if ( empty( $q ) ) {
		pete_psc_die( __( 'Invalid download request (missing id).', 'site-migration-backup-export-pete-panel' ), 400, __( 'Bad Request', 'site-migration-backup-export-pete-panel' ) );
	}

	$transient_key = 'pete_export_id_' . $q;
	$payload       = get_transient( $transient_key );
	if ( ! $payload || ! is_array( $payload ) ) {
		pete_psc_die( __( 'This download link has expired or is invalid. Please run a new export.', 'site-migration-backup-export-pete-panel' ), 410, __( 'Gone', 'site-migration-backup-export-pete-panel' ) );
	}

	$owner_id = isset( $payload['user'] ) ? (int) $payload['user'] : 0;
	if ( $owner_id <= 0 ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'Invalid link (no owner bound). Please run a new export.', 'site-migration-backup-export-pete-panel' ), 400, __( 'Bad Request', 'site-migration-backup-export-pete-panel' ) );
	}

	$provided   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	$action_key = 'pete_download_' . $owner_id . '_' . $q;

	if ( ! $provided || ! wp_verify_nonce( $provided, $action_key ) ) {
		pete_psc_log(
			'Nonce check failed for download',
			array(
				'owner' => $owner_id,
				'id'    => $q,
				'nonce' => substr( (string) $provided, 0, 10 ),
			)
		);
		pete_psc_die( __( 'Invalid or expired download link. Please click the “Download” button again from the export page.', 'site-migration-backup-export-pete-panel' ), 403, __( 'Forbidden', 'site-migration-backup-export-pete-panel' ) );
	}

	$current_id = get_current_user_id();
	if ( $current_id !== $owner_id ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'Access denied. Please log in as the user who created the export and retry.', 'site-migration-backup-export-pete-panel' ), 403, __( 'Forbidden', 'site-migration-backup-export-pete-panel' ) );
	}

	$path          = isset( $payload['path'] ) ? (string) $payload['path'] : '';
	$download_name = isset( $payload['download_name'] ) ? (string) $payload['download_name'] : ( isset( $payload['file'] ) ? (string) $payload['file'] : 'massive_file.zip' );
	$download_name = sanitize_file_name( $download_name );

	if ( '' === $path ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'File not found.', 'site-migration-backup-export-pete-panel' ), 404, __( 'Not Found', 'site-migration-backup-export-pete-panel' ) );
	}

	$base_dir_raw = isset( $payload['base_dir'] ) ? (string) $payload['base_dir'] : '';
	$base_dir     = $base_dir_raw ? pete_psc_realpath( $base_dir_raw, 'download_base_dir' ) : '';
	$real         = pete_psc_realpath( $path, 'download_zip' );

	$base_dir = $base_dir ? wp_normalize_path( $base_dir ) : '';
	$real     = $real ? wp_normalize_path( $real ) : '';

	$base_dir_slash = $base_dir ? trailingslashit( $base_dir ) : '';

	if ( ! $real || ! $base_dir_slash || strpos( $real, $base_dir_slash ) !== 0 ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'File not found or access denied.', 'site-migration-backup-export-pete-panel' ), 404, __( 'Not Found', 'site-migration-backup-export-pete-panel' ) );
	}

	if ( 'zip' !== strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'File not found or access denied.', 'site-migration-backup-export-pete-panel' ), 404, __( 'Not Found', 'site-migration-backup-export-pete-panel' ) );
	}

	if ( ! file_exists( $real ) || ! is_readable( $real ) ) {
		delete_transient( $transient_key );
		pete_psc_die( __( 'File not found or not readable.', 'site-migration-backup-export-pete-panel' ), 404, __( 'Not Found', 'site-migration-backup-export-pete-panel' ) );
	}

	$size = pete_psc_filesize( $real, 'download' );

	pete_psc_log(
		'Streaming export download',
		array(
			'owner' => $owner_id,
			'file'  => basename( $real ),
			'as'    => $download_name,
			'size'  => $size,
		)
	);

	nocache_headers();
	status_header( 200 );

	header( 'Content-Description: File Transfer' );
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
	header( 'Content-Transfer-Encoding: binary' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	ignore_user_abort( true );

	clearstatcache( true, $real );
	$size      = pete_psc_filesize( $real, 'download_after_clearstatcache' );
	$bytesSent = pete_psc_stream_file_to_output( $real );

	if ( $bytesSent > 0 && $size > 0 && (int) $bytesSent === (int) $size ) {
		delete_transient( $transient_key );
		pete_psc_unlink( $real, 'download_cleanup' );
		pete_psc_log( 'Download completed; zip deleted', array( 'file' => basename( $real ) ) );
	} else {
		pete_psc_log(
			'Download incomplete; zip kept',
			array(
				'sent' => (int) $bytesSent,
				'size' => (int) $size,
				'file' => basename( $real ),
			)
		);
	}

	exit;
}

// ---------------------------------------------------------------------
// REST authentication robustness (centralized permission check)
// ---------------------------------------------------------------------

function pete_psc_rest_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'pete_psc_rest_not_logged_in',
			__( 'Authentication required.', 'site-migration-backup-export-pete-panel' ),
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'pete_psc_rest_forbidden',
			__( 'Forbidden.', 'site-migration-backup-export-pete-panel' ),
			array( 'status' => 403 )
		);
	}

	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce ) {
		$nonce = $request->get_param( '_wpnonce' );
	}

	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error(
			'pete_psc_rest_bad_nonce',
			__( 'Invalid or missing REST nonce.', 'site-migration-backup-export-pete-panel' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

// ---------------------------------------------------------------------
// Background Export Core (reusable)
// ---------------------------------------------------------------------

function pete_psc_get_export_base_dir() {
	// Always export into /wp-content/uploads/{PETE_PSC_UPLOAD_SUBDIR}/
	$uploads     = wp_upload_dir();
	$uploads_dir = trailingslashit( $uploads['basedir'] ) . PETE_PSC_UPLOAD_SUBDIR;

	// Ensure the directory exists (best effort)
	if ( ! wp_mkdir_p( $uploads_dir ) ) {
		pete_psc_log( 'wp_mkdir_p failed for uploads export dir', array( 'dir' => $uploads_dir ) );
	}

	// Protect directory from web access (best effort)
	$ht = trailingslashit( $uploads_dir ) . '.htaccess';
	if ( ! file_exists( $ht ) ) {
		pete_psc_file_put_contents(
			$ht,
			"Require all denied\nOrder allow,deny\nDeny from all\n",
			'htaccess_uploads'
		);
	}

	$idx = trailingslashit( $uploads_dir ) . 'index.html';
	if ( ! file_exists( $idx ) ) {
		pete_psc_file_put_contents( $idx, '', 'index_uploads' );
	}

	return array(
		'base_dir'   => $uploads_dir,
		'is_uploads' => true,
	);
}

function pete_run_export_core( array $job ) {
	if ( ! empty( $job['run_as'] ) ) {
		wp_set_current_user( (int) $job['run_as'] );
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		throw new Exception( esc_html__( 'Unauthorized', 'site-migration-backup-export-pete-panel' ) );
	}

	$base_info   = pete_psc_get_export_base_dir();
	$baseDirPath = (string) $base_info['base_dir'];
	$baseDirReal = pete_psc_realpath( $baseDirPath, 'export_base_dir' );

	$save_progress = function ( $pct, $msg = '' ) use ( $job ) {
		$key   = 'pete_export_job_' . $job['id'];
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['progress'] = max( 0, min( 100, (int) $pct ) );
		$state['message']  = $msg;
		set_transient( $key, $state, HOUR_IN_SECONDS );
	};

	$save_progress( 5, __( 'Preparing archive…', 'site-migration-backup-export-pete-panel' ) );

	$job_token = ! empty( $job['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $job['id'] ) : 'job';
	$rand      = wp_generate_password( 8, false, false );
	$zipPath   = trailingslashit( $baseDirPath ) . 'massive_file-' . $job_token . '-' . $rand . '.zip';

	if ( ! class_exists( 'ZipArchive' ) ) {
		throw new Exception( esc_html__( 'ZipArchive PHP extension not available. Please enable it to create the .zip file.', 'site-migration-backup-export-pete-panel' ) );
	}

	$zip         = new ZipArchive();
	$open_result = $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	if ( true !== $open_result ) {
		throw new Exception( esc_html__( 'Could not create export archive (ZipArchive open failed).', 'site-migration-backup-export-pete-panel' ) );
	}

	$zip->addEmptyDir( 'filem' );

	$save_progress( 15, __( 'Writing config…', 'site-migration-backup-export-pete-panel' ) );

	global $wpdb;
	$site_url = str_replace( array( 'http://', 'https://' ), '', get_site_url() );
	$cfg      = "domain: '" . $site_url . "'" . PHP_EOL;
	$cfg     .= "platform: 'WordPress'" . PHP_EOL;
	$cfg     .= "prefix: '" . $wpdb->prefix . "'" . PHP_EOL;

	if ( ! $zip->addFromString( 'config.txt', $cfg ) ) {
		$zip->close();
		pete_psc_unlink( $zipPath, 'config_add_failed_cleanup' );
		throw new Exception( esc_html__( 'Failed to add config.txt to archive.', 'site-migration-backup-export-pete-panel' ) );
	}

	$save_progress( 30, __( 'Dumping database…', 'site-migration-backup-export-pete-panel' ) );

	$db_name     = defined( 'DB_NAME' ) ? DB_NAME : '';
	$db_user     = defined( 'DB_USER' ) ? DB_USER : '';
	$db_password = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
	$db_host     = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';

	$tmpSql = trailingslashit( $baseDirPath ) . 'query-' . $job_token . '-' . wp_generate_password( 6, false, false ) . '.sql';

	try {
		$dump = new Ifsnop\Mysqldump\Mysqldump( "mysql:host=$db_host;dbname=$db_name", $db_user, $db_password );
		$dump->start( $tmpSql );
	} catch ( Exception $e ) {
		$zip->close();
		pete_psc_unlink( $zipPath, 'zip_cleanup_after_db_fail' );
		pete_psc_unlink( $tmpSql, 'tmp_sql_cleanup_after_db_fail' );
		$raw_db_error = $e->getMessage();
		$db_error     = wp_strip_all_tags( (string) $raw_db_error );
		throw new Exception(
			sprintf(
				/* translators: %s: database error message. */
				esc_html__( 'Database dump failed: %s', 'site-migration-backup-export-pete-panel' ),
				esc_html( $db_error )
			)
		);
	}

	if ( ! file_exists( $tmpSql ) || ! is_readable( $tmpSql ) ) {
		$zip->close();
		pete_psc_unlink( $zipPath, 'zip_cleanup_missing_sql' );
		pete_psc_unlink( $tmpSql, 'tmp_sql_cleanup_missing_sql' );
		throw new Exception( esc_html__( 'Database dump created no readable SQL file.', 'site-migration-backup-export-pete-panel' ) );
	}

	if ( ! $zip->addFile( $tmpSql, 'query.sql' ) ) {
		$zip->close();
		pete_psc_unlink( $zipPath, 'zip_cleanup_add_sql_failed' );
		pete_psc_unlink( $tmpSql, 'tmp_sql_cleanup_add_sql_failed' );
		throw new Exception( esc_html__( 'Failed to add query.sql to archive.', 'site-migration-backup-export-pete-panel' ) );
	}

	// -------------------------------------------------------------
	// Real ZIP progress: animate during counting, then real add progress
	// -------------------------------------------------------------

	$exclude_plugin_dir_real = pete_psc_realpath( plugin_dir_path( __FILE__ ), 'plugin_dir' );

	// "Counting..." animation so UI shows movement even on huge sites.
	$save_progress( 35, __( 'Counting files…', 'site-migration-backup-export-pete-panel' ) );

	$total_files = pete_psc_count_site_files_for_zip(
		get_home_path(),
		$exclude_plugin_dir_real ? $exclude_plugin_dir_real : '',
		$baseDirReal ? $baseDirReal : ''
	);

	$save_progress(
		45,
		sprintf(
			/* translators: %d: total files to add to the archive. */
			__( 'Adding files to archive… (%d files)', 'site-migration-backup-export-pete-panel' ),
			(int) $total_files
		)
	);

	$progress_start = 45;
	$progress_end   = 90;
	$progress_range = max( 1, (int) ( $progress_end - $progress_start ) );
	$last_pct_sent  = -1;

	$progress_cb = function ( $added, $total ) use ( $save_progress, $progress_start, $progress_range, &$last_pct_sent ) {
		$added = (int) $added;
		$total = (int) $total;

		$den   = max( 1, $total );
		$ratio = $added / $den;
		if ( $ratio < 0 ) {
			$ratio = 0;
		}
		if ( $ratio > 1 ) {
			$ratio = 1;
		}

		$pct = $progress_start + (int) floor( $ratio * $progress_range );

		if ( $pct === $last_pct_sent ) {
			return;
		}
		$last_pct_sent = $pct;

		$save_progress(
			$pct,
			sprintf(
				/* translators: 1: added files, 2: total files. */
				__( 'Adding files to archive… (%1$d/%2$d)', 'site-migration-backup-export-pete-panel' ),
				$added,
				$den
			)
		);
	};

	$added_files = pete_psc_zip_site_root(
		$zip,
		get_home_path(),
		'filem/',
		$exclude_plugin_dir_real ? $exclude_plugin_dir_real : '',
		$baseDirReal ? $baseDirReal : '',
		$progress_cb,
		(int) $total_files
	);

	$save_progress(
		90,
		sprintf(
			/* translators: %d: number of files added to the ZIP archive. */
			__( 'Finalizing archive… (%d files)', 'site-migration-backup-export-pete-panel' ),
			(int) $added_files
		)
	);

	$zip->close();

	pete_psc_unlink( $tmpSql, 'tmp_sql_cleanup_after_close' );

	$zip_size = pete_psc_filesize( $zipPath, 'zip_final' );
	if ( $zip_size <= 0 ) {
		pete_psc_unlink( $zipPath, 'zip_empty_cleanup' );
		throw new Exception( esc_html__( 'Export archive created but appears to be invalid (0 bytes).', 'site-migration-backup-export-pete-panel' ) );
	}

	$owner = ( isset( $job['run_as'] ) && $job['run_as'] ) ? (int) $job['run_as'] : (int) get_current_user_id();

	$id            = wp_generate_password( 24, false, false );
	$transient_key = 'pete_export_id_' . $id;

	$download_name = pete_psc_build_download_filename( $job['id'] );

	$payload = array(
		'path'          => $zipPath,
		'download_name' => $download_name,
		'created'       => time(),
		'user'          => $owner,
		'base_dir'      => $baseDirPath,
	);

	set_transient( $transient_key, $payload, 2 * HOUR_IN_SECONDS );

	$base_url = add_query_arg(
		array(
			'action' => 'pete_download_export',
			'q'      => $id,
		),
		admin_url( 'admin-post.php' )
	);

	$save_progress( 100, __( 'Ready', 'site-migration-backup-export-pete-panel' ) );

	return array(
		'id'            => $id,
		'url'           => $base_url,
		'download_name' => $download_name,
		'zip_path'      => $zipPath,
		'base_dir'      => $baseDirPath,
	);
}

// ---------------------------------------------------------------------
// REST API: start, status, force-run
// ---------------------------------------------------------------------

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'pete/v1',
			'/export',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'pete_psc_rest_permission_check',
				'callback'            => 'pete_psc_rest_start_export',
			)
		);

		register_rest_route(
			'pete/v1',
			'/export/(?P<job>[A-Za-z0-9_-]+)/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'pete_psc_rest_permission_check',
				'callback'            => 'pete_psc_rest_export_status',
			)
		);

		register_rest_route(
			'pete/v1',
			'/export/(?P<job>[A-Za-z0-9_-]+)/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'pete_psc_rest_permission_check',
				'callback'            => 'pete_psc_rest_force_run_export',
			)
		);
	}
);

function pete_psc_rest_start_export( WP_REST_Request $req ) {
	$job_id = wp_generate_password( 16, false, false );

	$state = array(
		'id'            => $job_id,
		'owner'         => get_current_user_id(),
		'created'       => time(),
		'progress'      => 1,
		'message'       => __( 'Queued…', 'site-migration-backup-export-pete-panel' ),
		'done'          => false,
		'error'         => null,
		'download'      => null,
		'download_name' => null,
		'download_id'   => null,
		'download_url'  => null,
		'zip_path'      => null,
		'base_dir'      => null,
	);

	set_transient( 'pete_export_job_' . $job_id, $state, HOUR_IN_SECONDS );

	if ( ! wp_next_scheduled( 'pete_psc_run_export_job', array( $job_id ) ) ) {
		$scheduled = wp_schedule_single_event( time() + 2, 'pete_psc_run_export_job', array( $job_id ) );

		if ( false === $scheduled ) {
			$state['done']     = true;
			$state['error']    = __( 'Could not schedule WP-Cron event (wp_schedule_single_event returned false).', 'site-migration-backup-export-pete-panel' );
			$state['message']  = __( 'Failed', 'site-migration-backup-export-pete-panel' );
			$state['progress'] = 100;
			set_transient( 'pete_export_job_' . $job_id, $state, HOUR_IN_SECONDS );
			return new WP_REST_Response( array( 'job' => $job_id ), 202 );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		$blocking = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? true : false;
		$timeout  = $blocking ? 3 : 0.01;

		wp_remote_get(
			site_url( '/wp-cron.php?doing_wp_cron=' . urlencode( microtime( true ) ) ),
			array(
				'timeout'   => $timeout,
				'blocking'  => $blocking,
				'sslverify' => (bool) apply_filters( 'pete_psc_https_local_ssl_verify', true ),
			)
		);
	}

	return new WP_REST_Response( array( 'job' => $job_id ), 202 );
}

function pete_psc_rest_export_status( WP_REST_Request $req ) {
	$job_id_raw = isset( $req['job'] ) ? (string) $req['job'] : '';
	$job_id     = preg_replace( '/[^A-Za-z0-9_-]/', '', $job_id_raw );

	if ( '' === $job_id ) {
		return new WP_REST_Response( array( 'error' => __( 'Invalid job id', 'site-migration-backup-export-pete-panel' ) ), 400 );
	}

	$state = get_transient( 'pete_export_job_' . $job_id );

	if ( ! $state || (int) $state['owner'] !== get_current_user_id() ) {
		return new WP_REST_Response( array( 'error' => __( 'Not found or expired', 'site-migration-backup-export-pete-panel' ) ), 404 );
	}

	if ( ! empty( $state['download_url'] ) && ! empty( $state['download_id'] ) ) {
		$action_key        = 'pete_download_' . get_current_user_id() . '_' . $state['download_id'];
		$state['download'] = pete_psc_build_nonce_download_url( $state['download_url'], $action_key );
	}

	if ( ! empty( $state['download_name'] ) ) {
		/* translators: %s: export ZIP filename. */
		$state['download_name'] = sprintf(
			__( 'Download %s', 'site-migration-backup-export-pete-panel' ),
			(string) $state['download_name']
		);
	}

	if ( ! empty( $state['zip_path'] ) ) {
		/* translators: %s: filesystem path to the generated export archive. */
		$state['zip_location_label'] = sprintf(
			__( 'Export location: %s', 'site-migration-backup-export-pete-panel' ),
			pete_psc_pretty_path( (string) $state['zip_path'] )
		);
	}

	return $state;
}

function pete_psc_rest_force_run_export( WP_REST_Request $req ) {
	$job_id_raw = isset( $req['job'] ) ? (string) $req['job'] : '';
	$job_id     = preg_replace( '/[^A-Za-z0-9_-]/', '', $job_id_raw );

	if ( '' === $job_id ) {
		return new WP_REST_Response( array( 'error' => __( 'Invalid job id', 'site-migration-backup-export-pete-panel' ) ), 400 );
	}

	$key   = 'pete_export_job_' . $job_id;
	$state = get_transient( $key );

	if ( ! $state || (int) $state['owner'] !== get_current_user_id() ) {
		return new WP_REST_Response( array( 'error' => __( 'Not found or expired', 'site-migration-backup-export-pete-panel' ) ), 404 );
	}

	if ( ! empty( $state['done'] ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'done' => true ), 200 );
	}

	$state['message']  = __( 'Running…', 'site-migration-backup-export-pete-panel' );
	$state['progress'] = 3;
	set_transient( $key, $state, HOUR_IN_SECONDS );

	try {
		$run_as = isset( $state['owner'] ) ? (int) $state['owner'] : 0;
		$res    = pete_run_export_core( array( 'id' => $job_id, 'run_as' => $run_as ) );

		$state['done']          = true;
		$state['download_id']   = isset( $res['id'] ) ? $res['id'] : '';
		$state['download_url']  = isset( $res['url'] ) ? $res['url'] : '';
		$state['download_name'] = isset( $res['download_name'] ) ? (string) $res['download_name'] : null;
		$state['zip_path']      = isset( $res['zip_path'] ) ? (string) $res['zip_path'] : '';
		$state['base_dir']      = isset( $res['base_dir'] ) ? (string) $res['base_dir'] : '';
		$state['message']       = __( 'Ready', 'site-migration-backup-export-pete-panel' );
		$state['progress']      = 100;
		set_transient( $key, $state, HOUR_IN_SECONDS );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	} catch ( Throwable $e ) {
		$err_text          = wp_strip_all_tags( (string) $e->getMessage() );
		$state['done']     = true;
		$state['error']    = $err_text;
		$state['message']  = __( 'Failed', 'site-migration-backup-export-pete-panel' );
		$state['progress'] = 100;
		set_transient( $key, $state, HOUR_IN_SECONDS );

		return new WP_REST_Response(
			array(
				/* translators: %s: export error message. */
				'error' => sprintf(
					__( 'Error: %s', 'site-migration-backup-export-pete-panel' ),
					$err_text
				),
			),
			500
		);
	}
}

add_action(
	'pete_psc_run_export_job',
	function ( $job_id ) {
		$job_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $job_id );
		if ( '' === $job_id ) {
			return;
		}

		$key   = 'pete_export_job_' . $job_id;
		$state = get_transient( $key );
		if ( ! $state ) {
			return;
		}

		$run_as = isset( $state['owner'] ) ? (int) $state['owner'] : 0;
		if ( $run_as <= 0 || ! user_can( $run_as, 'manage_options' ) ) {
			$state['done']     = true;
			$state['error']    = __( 'Invalid job owner.', 'site-migration-backup-export-pete-panel' );
			$state['message']  = __( 'Failed', 'site-migration-backup-export-pete-panel' );
			$state['progress'] = 100;
			set_transient( $key, $state, HOUR_IN_SECONDS );
			return;
		}

		wp_set_current_user( $run_as );

		$state['message']  = __( 'Running…', 'site-migration-backup-export-pete-panel' );
		$state['progress'] = 3;
		set_transient( $key, $state, HOUR_IN_SECONDS );

		try {
			$res = pete_run_export_core( array( 'id' => $job_id, 'run_as' => $run_as ) );

			$state['done']          = true;
			$state['download_id']   = isset( $res['id'] ) ? $res['id'] : '';
			$state['download_url']  = isset( $res['url'] ) ? $res['url'] : '';
			$state['download_name'] = isset( $res['download_name'] ) ? (string) $res['download_name'] : null;
			$state['zip_path']      = isset( $res['zip_path'] ) ? (string) $res['zip_path'] : '';
			$state['base_dir']      = isset( $res['base_dir'] ) ? (string) $res['base_dir'] : '';
			$state['message']       = __( 'Ready', 'site-migration-backup-export-pete-panel' );
			$state['progress']      = 100;
		} catch ( Throwable $e ) {
			$state['done']     = true;
			$state['error']    = $e->getMessage();
			$state['message']  = __( 'Failed', 'site-migration-backup-export-pete-panel' );
			$state['progress'] = 100;
		}

		set_transient( $key, $state, HOUR_IN_SECONDS );
	},
	10,
	1
);