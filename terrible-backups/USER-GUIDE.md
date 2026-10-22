# Terrible Backups

A manual WordPress backup and migration plugin. Install the ZIP from `dist/` under **Plugins → Add New → Upload Plugin**, activate it, and open **Tools → Terrible Backups**.

## Included

- Select all components or just the database, uploads, plugins, themes, must-use plugins, other `wp-content` files, WordPress core, and root files/configuration.
- Download local ZIP archives; upload them through small, retryable browser requests. Choose 256, 64 or 16 KiB chunks. The client also reduces its default to fit PHP's configured request limit.
- Send archives to and fetch them from FTP, explicit FTPS, AWS S3, and S3-compatible storage. FTP/S3 imports use an exact filename/object key relative to your configured prefix. The optional Integrations pack adds Dropbox/Google Drive with a remote archive browser.
- Optional source-site URL replacement during database import, including serialized arrays/objects, nested serialized strings, and JSON-escaped URLs. No PHP objects are instantiated during replacement. Post GUIDs are preserved. Source WordPress subdirectory paths are retained.
- Table-prefix migration, including role-option and user-capability keys.
- Per-file SHA-256 verification before completion of a backup and before applying a restore.
- Private working storage, encrypted remote credentials, administrator-only actions, CSRF checks, path/symlink checks, maintenance mode, file rollback journals, and staged database tables activated with one `RENAME TABLE` statement.
- WP-CLI commands for server-local import and recovery.

## What “everything” means

The archive covers the WordPress installation directory, `wp-content`, the configured uploads directory, and database tables using the site's table prefix. It does not include other databases, unprefixed tables, external object-storage media, operating-system configuration, or files outside those roots. Symbolic links are skipped with warnings; their targets are not followed. Empty directories are not recorded.

**Root files are backed up but are not automatically restored.** This preserves destination `wp-config.php`, `.htaccess`, `.user.ini`, `.env`, and other hosting configuration. Extract `root/` from the ZIP for manual recovery when needed. The running Terrible Backups plugin is also preserved. Selected files and database tables are overwritten; extra destination files and tables are retained. A restore is not a malware-cleanup operation or an exact deletion-based mirror.

## Requirements and limits

- Single-site WordPress 6.6+, PHP 8.2+ on a 64-bit host, PHP ZIP and OpenSSL; cURL and SimpleXML for remote storage. Multisite is rejected.
- Direct filesystem write permission; this version does not use WordPress's interactive FTP filesystem credentials to install files.
- Plugin and must-use plugin directories must be in their standard locations under `WP_CONTENT_DIR`. Custom `WP_CONTENT_DIR` is supported; symlinked restore destinations are rejected.
- Database permissions for reading, creating, inserting, renaming, and dropping tables. Views, foreign keys and triggers are explicitly rejected rather than silently omitted. Routines/events are outside the site's table backup format.
- Only Terrible Backups format-v1 ZIPs can be restored. SQL data is stored as schema + base64-encoded rows in `database/*.ndjson`; this is not a generic SQL or `.wpress` importer.
- Manual only. Keep the browser tab open during jobs; use **Resume** after an interruption. No cron, scheduling, retention policy, encryption of archive contents, SFTP, or differential backup.
- Limits: 200,000 archive files, 5,000 tables, 32 MiB manifest, 64 MiB encoded database row; S3 uses 8 MiB parts, up to 10,000 parts (about 78 GiB per remote upload). PHP memory/time limits and available disk may impose lower limits. File inventory is held in memory. Large individual files and large database rows can still exceed a host's execution or memory limits; use WP-CLI on such hosts.
- Backups are not a transactional snapshot of a site receiving writes. Pause editors, orders, imports, and external writers during backup and restore. Database rows are read in batches; concurrent changes can make a snapshot inconsistent.
- Restore stages data before application, but filesystem and database changes are not one global transaction. Files are applied in batches. A changed core/plugin/theme can prevent the next WordPress request from loading; keep SSH/WP-CLI or hosting recovery available for a full code restore, especially across versions. The rollback journal remains outside the web root.

## Private storage

For durable storage, add this to the destination's `wp-config.php`:

```php
define('TERRIBLE_BACKUPS_DIR', '/absolute/private/path/terrible-backups');
```

The directory must be writable by PHP and outside the WordPress and web-server document roots. Its permissions are restricted to its owner. By default the plugin uses a site-specific directory under the system temporary directory, which the host can clean. Set the constant before use on a durable installation. Changing salts changes the default directory name and invalidates encrypted remote credentials; use a stable configured directory and re-enter credentials after salt rotation.

Archives contain user data, password hashes and, when root files are selected, configuration secrets. They are **not encrypted**. The ZIP manifest checksums detect damage, not authenticity: only restore trusted archives. Do not serve the private working directory through a separate web-server alias.

A completed restore keeps the old tables and rollback files until its job is deleted. **Delete** removes the local archive and retained rollback data, never a remote archive. Uninstalling the plugin intentionally leaves private backups intact. Remove them manually when you no longer need them.

## Remote storage

Save credentials under **Remote storage settings**. They are encrypted using AES-256-GCM with a key derived from this installation's WordPress salts and are stored outside the database and archive. Secrets are not returned to the admin page. Blank secret/password/token fields preserve their saved values; use the checkbox to clear an S3 session token.

**FTP / FTPS:** enter hostname, port, username, password, and a directory relative to the FTP login directory. The directory must already exist. Plain FTP is unencrypted; enable explicit TLS for FTPS. TLS peer and hostname verification stay enabled. The server must support binary transfers, SIZE, REST for resumed reads/writes, and rename. Uploads use a temporary `.part-JOBID` name and rename on completion. An interrupted/cancelled FTP transfer can leave this temporary remote file; remove it on the server if no longer needed.

**S3:** enter bucket, region, key ID and secret; optionally provide an STS session token. Leave endpoint blank for the AWS regional endpoint, or enter an HTTPS endpoint for an S3-compatible service. Path-style addressing is default; use the virtual-hosted checkbox when required. Permissions needed include object read/write, multipart initiation/upload/completion/abort, and object metadata reads under the chosen prefix. Buckets are not created by the plugin. Credentials and requests use AWS Signature V4. Configure an S3 lifecycle rule to expire incomplete multipart uploads as a fallback for abandoned jobs or changed credentials.

A completed archive's provider-specific **Send to** action uses `terrible-backups-JOBID.zip` under the prefix. The success message reports the resulting key. Enter the same filename under **Fetch from remote storage**, then inspect and restore it.

The opt-in `TERRIBLE_BACKUPS_ALLOW_HTTP` constant exists only for local S3 test services. Do not enable it for Internet storage.

## Restore and recovery

1. Create a destination backup first.
2. Upload or fetch a trusted archive, then choose **Inspect / restore**.
3. Select components and optionally enter the destination home URL. Blank preserves source URLs.
4. Confirm the overwrite warning and start. Keep the tab open. Public requests receive maintenance responses while files and tables are being activated. A temporary HttpOnly cookie lets the restoring browser continue through maintenance; every API action still requires WordPress administrator authentication and a nonce.
5. After restoring the database, sign in with an account from the **source** site. Destination users/passwords are replaced. The destination's credentials, salts and server configuration are preserved.
6. Verify the frontend, media, admin access, plugins, and permalinks before deleting rollback data. Re-save permalinks if the destination server needs different rewrite rules; hosting configuration is intentionally preserved.

Validation failure changes no live content. Handled application failures/cancellation roll applied files back and discard staged tables. If an abrupt interruption leaves maintenance active, resume or cancel the job from the same browser or use WP-CLI. Maintenance remains active until completion/recovery rather than automatically exposing a partially restored site.

```sh
wp terrible-backups jobs
wp terrible-backups backup --parts=database,uploads
wp terrible-backups import /private/path/archive.zip
wp terrible-backups restore ARCHIVE_ID --parts=database,uploads --url=https://destination.example --yes
wp terrible-backups resume JOB_ID
wp terrible-backups cancel JOB_ID
wp terrible-backups remote s3 archive.zip --source=ARCHIVE_ID
wp terrible-backups remote ftp archive.zip
wp terrible-backups delete JOB_ID --yes
```

If an imported plugin/theme blocks WordPress startup, skip that code while loading the recovery engine:

```sh
wp --skip-plugins --skip-themes eval 'require WP_PLUGIN_DIR . "/terrible-backups/terrible-backups.php"; \TerribleBackups\Engine::cancel("JOB_ID");'
```

WP-CLI still loads must-use plugins and core; if those prevent startup, restore the corresponding files using the job's private `rollback/` files and `job.json` journal through hosting/file access before running cancellation. Do not delete working directories or old tables while a restore needs recovery. Completed restores cannot be cancelled through the normal UI; restore the destination backup to reverse a completed migration.

## Development and verification

`make check` runs PHP/JavaScript syntax checks. `make package` creates the installable ZIP and SHA-256 checksum. Only `terrible-backups/` enters the package; test credentials, fixtures, containers and backups do not.

The Compose project is named `terrible-backups-test`, uses separate named volumes, binds WordPress only to `127.0.0.1:18765`, and starts MariaDB, MinIO and a disposable FTP server. It does not touch an existing Local site. Its fixed test subnet is `10.254.213.0/24`; change it if needed. All included credentials are disposable local-test values.

```sh
make test-up       # Downloads checksum-pinned WP-CLI 2.12.0, builds and starts the lab
make test          # Installs WP if needed, then runs all integration suites
make test-down     # Stops the test containers, retaining their volumes
```

Apache's request-body limit and PHP's upload/post limits are deliberately 64 KiB. The HTTP suite proves that a single 2 MiB request gets HTTP 413, then uploads and restores the same archive using 16 KiB requests. Other suites cover full and selective restores, 20 MiB FTP/S3 transfers, binary data, serialization, prefix migration, replayed requests, interrupted database activation, path traversal, and symlinks. See `TESTING.md` for the results and verification boundaries.

Lab login: `backup-admin` / `local-backup-test-password` at <http://localhost:18765/wp-admin/tools.php?page=terrible-backups>. Never use these credentials on an exposed installation.

## Optional storage integrations (0.2.0)

Install Terrible Backups — Integrations for Dropbox and Google Drive. Configure your own OAuth application, save credentials, then connect from Remote storage settings. The callback URI is displayed there. Browse provider archives to recover a backup without the original site catalog. Named destinations save independent configuration snapshots. Missing providers fail explicitly; removing the pack preserves its settings. The pack includes its own connection guide. Core exposes storage API v1 for additional providers.


## Destinations and navigation (0.3.0)

With another Terrible core active, open **Terrible → Backups**. Otherwise use **Tools → Terrible Backups**; old URLs still work. Send and fetch actions list only configured connections, independent of current network health. Send uses one destination selector. Download/restore actions require a local archive.

Under **Destinations & remote storage**, choose one provider to configure. Save its settings, connect its account if applicable, and optionally save a distinct destination name. Repeating a name with identical settings is idempotent; conflicting names are rejected. Saved destinations are snapshots: use **Edit connection** to edit one without changing provider defaults. Blank secrets retain that destination’s credentials. Rename/remove directly; for OAuth reconnection, reconnect the provider and explicitly choose **Use current provider settings**. Changing a destination interrupts transfers bound to its old configuration; start a new transfer. Removing a destination leaves remote archives intact. Missing integration packs leave saved records visible for recovery but remove unavailable send/fetch actions. Identical provider defaults and a named snapshot appear only once in actions.
