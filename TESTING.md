# Verification record — 2026-10-11

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

History and Forms are absent from the active-package list and inactive in the demo. Their source and stored records are retained. Current tests exercise the retained lineup and optional replacements; older Forms/History results are historical, not ongoing maintenance commitments. See RETIREMENTS.md for migration and caching boundaries.
