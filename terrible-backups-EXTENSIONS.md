# Storage API v1 and Integrations pack

Terrible Backups 0.2.0 exposes `TerribleBackups\StorageProvider`, optional `CatalogProvider`, and `Providers::API_VERSION` (1). Built-in FTP/S3 use the same storage interface as the pack. Local archive creation/import remains owned by the core engine.

Install **Terrible Backups — Integrations** beside core to add Dropbox and Google Drive. The pack has no independent menu, scheduler, archive format, or framework dependency. It registers providers through `terrible_backups_storage_providers` after checking the API version. Independent third-party provider plugins can use this filter too.

## Connect an account

Create your own provider OAuth application; no hosted Terrible account or connection broker is involved. Save client ID and client secret under Tools → Terrible Backups → Remote storage settings, then use **Connect account**. Register the callback URL:

`https://YOUR-SITE/wp-admin/admin-post.php?action=terrible_backups_oauth`

Dropbox needs offline access and file metadata/content read/write permissions. Its configured prefix must refer to an existing folder. Google Drive requests `drive.file`, so the catalog covers files created or authorized through the same application, not every file in the account. Keep the same OAuth application when recovering on a fresh installation; reauthorize it on that installation. A supplied refresh token can also be saved directly.

Save a **named destination** to snapshot a provider configuration. Defaults can then be changed without altering the saved destination. Transfers bind to the selected destination and refuse to continue if its configuration changes. This release supports up to 30 named destinations. Configuration is encrypted in core's private settings file; it is not included in public job responses.

Cloud uploads use bounded 8 MiB chunks. Dropbox sessions and Google resumable uploads checkpoint provider state between requests. Replaying an acknowledged chunk or final acknowledgement is reconciled against provider state. Completed cloud uploads verify size plus Dropbox content hash or Drive MD5. Downloads verify the same digest before marking an archive ready; core restore additionally validates the archive manifest and per-file SHA-256.

Use **Browse provider archives** for a paginated provider catalog. It does not depend on the site's old backup database or local job catalog. Select an archive, fetch it, inspect it, then restore with core's existing workflow. FTP/S3 continue using explicit archive paths in this release. Core remains a manual backup/migration tool: this change does not add scheduled backups or automatic remote retention/deletion.

Removing the pack preserves settings and jobs. A missing provider produces a contained error rather than another destination being selected. Local cancellation remains possible when remote cleanup is unavailable; incomplete cloud sessions are left to expire, and committed backups are not deleted by cancellation.

## Provider contract

`StorageProvider` contains `id`, `label`, `fields`, `validate`, `size`, `transfer`, and `abort`.

- `fields()` returns `field => [label, HTML input type, default]`; use `password` for secrets. Core retains blank saved-secret fields and never renders them back.
- `validate($config)` validates configuration without mutating remote state.
- `size($config, $key)` returns the positive byte size of a downloadable archive.
- `transfer(&$job, $directory, $config)` performs bounded work, returning `true` only when complete. Core persists the updated job. `$directory/archive.zip` is the local archive; `direction`, `key`, `size`, and `offset` are stable engine fields. Save newly allocated remote-session identifiers immediately before issuing further operations. Never place access/refresh tokens in job state.
- `abort($job, $config)` cleans up uncommitted transfer state when safe. Never delete a committed backup merely because a local acknowledgement was lost.

`CatalogProvider::catalog($config, $cursor)` returns `files` entries with `key` and `size`, plus an opaque continuation `cursor`. Keys are relative to the configured prefix. Pages must be bounded.

Use the `terrible_backups_connection_controls` action (provider ID argument) to render optional provider-specific authorization controls after its settings fields. Core does not assume every provider uses OAuth.

Endpoint overrides exist only through the `TERRIBLE_BACKUPS_TEST_ENDPOINTS` PHP constant for disposable tests. Provider-returned upload-session URLs are restricted to trusted origins and redirects are disabled. OAuth state is expiring, single-use, and tied to the initiating administrator.

## Verification

The sibling `terrible-utilities` Docker suite tests Dropbox/Drive against local protocol mocks: 20 MiB multi-request upload/download, provider-side catalog discovery, actual WordPress restore, corrupted downloads, missing modules, configuration changes, named destinations, OAuth state/replay, and recovery from lost chunk/final checkpoints. Existing FTP/MinIO/full-restore/recovery/security tests are retained.

These mocks are not live Dropbox/Google account qualification. Real consent screens, application review, quotas, shared-drive variants, and provider-specific production policies still require account-level qualification. Reference specifications: [Dropbox HTTP API](https://www.dropbox.com/developers/documentation/http/documentation), [Drive resumable uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads).


### Configuration readiness (0.3.0)

API v1 providers may additionally implement `ConfiguredProvider::configured(array $config): bool`. This must inspect required local configuration without a network call. Providers without this interface fall back to nonempty configuration; `terrible_backups_destination_configured` can refine that result. A failed network request does not make a destination disappear. The bundled Integrations 0.3.0 pack requires this readiness interface and reports an actionable notice on older cores.
