# Verification record — 2026-10-11

## Social Images release qualification

The Metadata 0.2.1 and Social Images 0.1.0 ZIPs were installed together on clean WordPress sites: 7.1.4/PHP 8.3.36 and 6.6.2/PHP 8.2.25. The package-pair test checked the generated image, the `og:image` on the public post, JPEG bytes at the public image URL, and manual Metadata image precedence. The main disposable suite covers preview access/nonces, bulk permissions and bounded cursor batches, a held advisory lock on a second database connection, and changes during rendering. WP Offload Media Lite 3.4.4 plus MinIO was separately tested using removed local originals and fetching the resulting offloaded JPEG. This is not a full multisite, real AWS, every-offload-provider, typography or hosting matrix.

`make package` creates the ZIPs, `compose.social-package.yaml` runs a clean current-version package test after the utility WordPress image has been built, and `compose.social-package-min.yaml` uses WordPress 6.6/PHP 8.2. On either stack, install WordPress with `--url=http://wordpress`, install the two ZIPs in `/packages/`, then run `wp eval-file /suite-tests/social-package.php --allow-root` in the WordPress container. The minimum-version stack also requires `tests/wp-cli.phar` (downloaded by `make test-up`).

## Social Images offload test

`make test` runs the local Social Images and preview HTTP regressions. For a separate real offload test, install WP Offload Media Lite 3.4.4 into a temporary directory from the WordPress.org distribution, then set `TSI_OFFLOAD_PLUGIN` to the absolute path of its extracted `amazon-s3-and-cloudfront` directory. First run `make test-up` once to build `terrible-utilities-wordpress:test`, then stop the regular lab before starting the separate stack:

```sh
docker compose -f compose.social-offload.yaml up -d --wait
docker compose -f compose.social-offload.yaml exec minio /opt/bitnami/minio-client/bin/mc alias set local http://127.0.0.1:9000 isolated-test-access isolated-test-secret
docker compose -f compose.social-offload.yaml exec minio /opt/bitnami/minio-client/bin/mc mb --ignore-existing local/social-images-test
docker compose -f compose.social-offload.yaml exec wordpress wp core install --url=http://localhost:18769 --title='Offload test' --admin_user=admin --admin_password=local-test-only --admin_email=admin@example.test --skip-email --allow-root
docker compose -f compose.social-offload.yaml exec wordpress wp plugin activate amazon-s3-and-cloudfront terrible-social-images terrible-metadata --allow-root
docker compose -f compose.social-offload.yaml exec wordpress wp eval-file /suite-tests/social-real-offload.php --allow-root
docker compose -f compose.social-offload.yaml stop
```

The configuration under `tests/social-offload-config.php` is disposable and deliberately permits the test-only MinIO hostname in WordPress's safe HTTP checks. It uses private signed URLs to exercise remote retrieval. It must not be installed on a real site. MinIO does not implement some AWS bucket-management APIs; those warnings do not qualify AWS behavior. Test credentials and data are not production credentials.

The retained utility lineup passed in the isolated `terrible-utilities-test` Docker project on WordPress **7.1.3**, PHP **8.3.31**, MariaDB **11.4**, and WP-CLI **2.12.0**. The revised full runner finished with **217 passing assertions**, eight independent core-plugin activation/bootstrap checks, and `ALL SUITES PASSED`. A subsequent destination-edit regression run adds secret-preservation and default-isolation coverage. PHP and JavaScript syntax checks and release ZIP integrity checks also pass. There are ten current packages: eight core products and two optional integration packs.

This is a tested initial implementation, not a claim of live-provider or full hosting/version-matrix qualification. Tests use synthetic data and dummy credentials. The existing Alyx Local site was not used for destructive tests.

## Evidence

Local run output is retained in `test-results/verification.log` and `test-results/polish-final.log` (ignored by Git). Reproduce with `make test-up`, then `make test`. Docker binds WordPress only to `127.0.0.1:18766`; data remains in disposable named volumes. Tests deliberately restore databases and replace files there.

| Area | Verified behavior |
|---|---|
| Backups core | Existing integration, recovery, full-restore, security and HTTP suites: FTP/MinIO round trips, selective/full restore, serialization, prefix migration, replay recovery, traversal/symlink rejection, permissions and chunked upload under a 64 KiB request limit |
| Backups pack | Dropbox/Drive 20 MiB multi-chunk byte-identical round trips, digest verification, provider catalogs, actual file restoration, corrupted downloads, saved destinations, missing providers, configuration changes, first/intermediate/final checkpoint replay |
| Mail | Authenticated SMTP, STARTTLS and implicit TLS, hostname mismatch rejection, MIME attachments/Bcc, WordPress success/failure hooks, rejection versus uncertain acceptance, encrypted manual retries, saved connections, no missing-provider fallback |
| Mail pack | Resend, SES and Gmail mock HTTP submissions, attachments, rate limiting and uncertain server errors; SES fixture verifies SigV4 signatures; Gmail fixture checks raw MIME recipients |
| Redirects | Redirect status and query behavior, unsafe sources/targets and cycles, bounded 404 reporting |
| Media | Same-URL replacement, regenerated metadata, byte-identical undo and content references; actual multipart HTTP requests |
| Replacements | Stream 4.4.0 receives one fixed semantic event across bundled copies without arbitrary details. VS Contact Form 19.1 preserves invalid input, sends valid submissions over local SMTP, and retains an inbox entry when notification fails. |
| Login | RFC 6238 vector, encrypted enrollment, TOTP/recovery replay prevention, password-only refusal, recovery-code login, application-password bypass prevention and attempt limits |
| Metadata / Links | Escaped public metadata; link indexing and revision-preserving replacement; private-address/redirect SSRF refusal; actual Docker HTTP fixtures for 410, 403 and HEAD-to-GET fallback |
| Revisions | Draft replacement leaves live content intact, accidental separate publication prevented, URL preserved, conflicting live changes rejected, scheduled publication and permission checks |
| Administration / OAuth | Each screen loads; bad CSRF tokens and subscriber access rejected; cloud OAuth callback is site-fixed, user-bound and single-use |
| Independence / UI | Every core product bootstraps with all other Terrible plugins deactivated. Real browser inspection of Mail, Media, Metadata, Revisions, and Backups; 390px and desktop layouts; published-content search and draft creation/comparison; live editor preview and lazy-mounted image controls; cloud-settings save and one Send selector per archive. HTTP tests exercise all administration pages. |

## Qualification boundaries

- Dropbox, Drive, Resend, SES and Gmail are exercised against local protocol mocks, not live accounts. Real consent/app approval, sender verification, quotas, shared-drive variants and provider policy remain unverified.
- SMTP TLS uses a disposable test CA trusted only inside the test WordPress container. FTP and S3 use local FTP/MinIO servers; FTPS is not qualified by this run.
- The complete final suite ran on WordPress 7.1.3 / PHP 8.3.31. Other declared minimum versions, multisite, SSO/offloaded media, large-site scale and production concurrency have not completed a qualification matrix.
- Backups remains manual, with no scheduling or automatic remote retention. Revisions scheduling depends on WordPress cron. Other product-specific limits and retention periods are listed in README.md and the bundled guides.
- Mocks prove the behavior modeled by these fixtures; they cannot prove every external API behavior or inbox delivery. No live provider credentials or production changes were needed.

## Retirements

History and Forms are absent from the active-package list and inactive in the demo. Stored records are retained, but their source is no longer part of this repository. Current tests exercise the active lineup and optional replacements; older Forms/History results are historical, not ongoing maintenance commitments. See RETIREMENTS.md for migration and caching boundaries.
