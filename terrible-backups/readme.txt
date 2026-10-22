=== Terrible Backups ===
Contributors: terriblestudios
Tags: backup, migration, restore, s3, ftp
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manual full or selective backups, chunked uploads, FTP/FTPS and S3-compatible transfers, and serialized-data-safe URL replacement.

== Installation ==
Upload the plugin ZIP, activate, and open Tools > Terrible Backups.
Use TERRIBLE_BACKUPS_DIR to configure durable private storage outside the web root.

== Important behavior ==
Single-site only. Supports this plugin's ZIP format. Root configuration files are backed up but not automatically restored. The running recovery plugin is preserved. Extra destination files/tables are retained. No scheduled backups, archive encryption, SFTP or multisite support. Keep a destination backup and hosting recovery access before importing code or a database. See the bundled USER-GUIDE.md for requirements, limits and recovery procedures.

== Changelog ==
= 0.2.0 =
Public storage API v1, optional Integrations pack, named destinations, and provider catalogs.
= 0.1.0 =
Initial manual backup, transfer and restore release.
