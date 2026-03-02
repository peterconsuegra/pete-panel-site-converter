=== Migration & Backup Export for Pete Panel ===
Contributors: pedroconsuegra
Tags: migration, backup, site export, cloning, staging, database export, deployment, pete panel
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Migrate, clone, or back up your full WordPress site (files + database) in one click - ready for instant deployment.

== Description ==

Migration & Backup Export for Pete Panel is a powerful WordPress migration plugin and backup plugin that lets you export, clone, or move your entire WordPress site in one click.

Create a complete WordPress backup and migration export including files and database, packaged into a secure, ready-to-deploy archive.

Ideal for WordPress site migration, secure backups, staging environments, cloning, and local development workflows.

Unlike many WordPress migration plugins, this plugin focuses on generating a clean WordPress export that includes both files and database in a structured format. It can also serve as a lightweight WordPress backup solution for manual migrations and staging workflows.

The generated ZIP is structured exactly as Pete Panel expects.

During import, Pete Panel extracts the archive into a `massive_file/` directory.

Therefore the ZIP file itself must contain the following at its root (do NOT include a `massive_file/` folder inside the ZIP):

- `filem/`
- `query.sql`
- `config.txt`

This plugin automatically generates that structure for you.

After export, you receive a secure, admin-only download link inside WordPress.

---

== Migration & Backup Features ==

- Full WordPress migration export (files + database)
- Complete WordPress backup generation
- Generates Pete Panel-ready archive structure
- Background processing via WP-Cron
- Force-run fallback if WP-Cron is blocked
- Secure admin-only download endpoint
- Automatic ZIP cleanup after successful download
- Sensible default exclusions for secrets and heavy folders
- Lightweight alternative for manual site migration workflows

---

== How the Migration & Backup Export Works ==

1. Click **Start export** in WP Admin → Pete Converter.
2. The plugin queues the WordPress migration export via WP-Cron (background processing).
3. If WP-Cron is blocked by your host, the UI can trigger a fallback that runs the export directly.
4. When finished, a **Download** button appears.
5. Download the ZIP and use it for WordPress migration, backup storage, cloning, or import into Pete Panel.

Security protections include:

- Admin capability required (`manage_options`)
- Download bound to the user who created the export
- Nonce-protected URL
- Strict path validation inside the export directory

After a successful download, the ZIP file is automatically deleted to reduce leftover sensitive data on the server.

---

== Export Storage Location ==

The plugin attempts to write WordPress backup exports to:

1) Preferred location (outside uploads):
`wp-content/pete-panel-site-converter-exports/`

If not writable, it falls back to:

2) Uploads directory:
`wp-content/uploads/pete-panel-site-converter/`

In both cases the plugin creates:

- `.htaccess` (deny access for Apache environments)
- `index.html`

Note: On Nginx servers, `.htaccess` rules may not apply. Regardless, downloads remain protected by WordPress permissions, nonces, and strict path checks.

---

== Default Exclusions ==

To avoid exporting common secrets and unnecessary bulk during WordPress migration or backup creation, the plugin excludes (not limited to):

- `wp-config.php`
- `.env`
- `.git/`, `.svn/`, `.hg/`
- `node_modules/`, `vendor/`
- Common cache and backup directories inside `wp-content/`

Developers can customize exclusions using the filter:

`pete_psc_export_excludes`

---

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/pete-panel-site-converter/`  
   or install via the WordPress Plugins screen.
2. Activate the plugin.
3. Go to **Pete Converter** in the WordPress admin.
4. Click **Start export**.

---

== Frequently Asked Questions ==

= Where do I find the exported ZIP? =

Go to **WP Admin → Pete Converter**, run a WordPress migration export, and click the **Download** button when ready.

= Does it include uploads, themes, and plugins? =

Yes. The `filem/` directory inside the ZIP is a full WordPress site copy including `wp-content/` (uploads, plugins, themes), unless excluded by default rules or filters.

= Why is the folder called massive_file? =

Pete Panel extracts the archive into a directory named `massive_file/` during import.  
The ZIP itself must NOT contain that folder.

= What if WP-Cron is blocked? =

Some hosts disable or block WP-Cron. The plugin includes a fallback route that allows the WordPress migration and backup export to run directly if background processing does not progress.

= Is the download link public? =

No. Downloads are:

- Admin-only
- Nonce-protected
- Bound to the creating user
- Restricted to the plugin’s export directory

= Does this replace other WordPress backup plugins? =

This plugin focuses on WordPress migration exports and manual WordPress backups rather than automated scheduled backup systems.

If you need recurring off-site backups, use a dedicated WordPress backup plugin alongside this tool.

---

If you need a reliable WordPress migration and backup export tool that creates a full WordPress backup (files + database) for cloning, staging, or local development, this plugin provides a simple and secure solution.

---

== Screenshots ==

1. Export screen with “Start export” button
2. Progress bar during WordPress migration export
3. Download button when export is ready

---

== Third-Party Libraries ==

This plugin bundles a third-party library:

- mysqldump-php  
  File: `bin/Mysqldump.php`  
  License: GNU General Public License v3.0  
  License file: `licenses/GPL-3.0.txt`

---

== Changelog ==

= 1.0.0 =
* Initial release.
* WordPress migration and backup export functionality.
* Background export via WP-Cron with force-run fallback.
* Secure admin-only download endpoint.
* Automatic ZIP cleanup after download.
* Default exclusions for common secret, cache, and backup paths.
* Translatable admin UI strings.

---

== Upgrade Notice ==

= 1.0.0 =
Initial release.