# Terrible Utilities

Small WordPress utilities that solve one problem each. No ads, telemetry, account requirement, upsell screens, or shared framework plugin.

Each `terrible-*` directory is an independent installable plugin. Mail's optional Integrations pack is one additional plugin containing all first-party API transports. Backups remains in the sibling `terrible-backups` repository with its own Integrations pack.

| Plugin | Location in WordPress | First release |
|---|---|---|
| Terrible Mail | Settings → Terrible Mail | SMTP, STARTTLS/implicit TLS, test sending, encrypted settings, named connections, bounded submission history, manual retry of definitive rejections |
| Terrible Mail — Integrations | Inside Mail settings | Resend, Amazon SES v2 (SigV4), Google Workspace/Gmail with OAuth |
| Terrible Redirects | Tools → Terrible Redirects | Exact-path redirects, loop prevention, bounded 404 report, export |
| Terrible History | Tools → Terrible History | Content/user/plugin/theme/settings events; setting names only; search and export |
| Terrible Media | Tools → Terrible Media; Media Library | Replace supported images/PDFs at their existing URL, regenerate metadata, one-version undo, known content references |
| Terrible Forms | Tools → Terrible Forms | `[terrible_form]` contact form, accessible labels, local inbox, honeypot/rate limit, notifications and export |
| Terrible Login | Profile; Tools → Terrible Login | TOTP, ten one-use recovery codes, encrypted secrets, replay prevention, administrator enrollment requirement |
| Terrible Metadata | Post/page editor; Tools → Terrible Metadata | Title/description/image overrides, saved preview, Open Graph tags and social card type; defaults from content |
| Terrible Links | Tools → Terrible Links | Incremental content indexing, bounded HTTP checks, confirmed dead-link reporting, replacement with revisions, dismiss/restore/export |
| Terrible Revisions | Tools → Terrible Revisions; post/page list | Prepare a draft replacement, preview, apply or schedule; conflict detection; preserve live URL |

## Install

Run `make package`, then upload the individual ZIPs from `dist/` through WordPress Plugins → Add Plugin → Upload Plugin. Install only the products you need. The Mail pack requires Terrible Mail. No common runtime plugin is needed.

PHP 8.2+ is required. Mail requires WordPress 6.8+; the other utilities declare 6.6+. Qualified runtime versions are recorded in `TESTING.md`; declaring a minimum is not evidence of a completed full version matrix. These initial distributions use `Update URI: false`; there is no hosted update feed yet. Upgrade using replacement ZIPs through WordPress.

## Mail

Configure a sender, host, port, and `tls` (STARTTLS) or `ssl` (implicit TLS). Use `none` only for a trusted local relay. Certificate validation stays enabled. Save and explicitly enable delivery, then send a test. A blank password/API-secret field preserves the stored secret. Named connections snapshot the configured transport and settings; changing defaults does not edit those snapshots.

`accepted` means accepted by the server/provider, not delivered to an inbox. `rejected` can be retried manually for 24 hours. `unknown` cannot be retried from the history because the provider may have already accepted it. No automatic fallback to PHP mail or another provider occurs while Mail is enabled. Use one mail-routing plugin at a time; another plugin that short-circuits `wp_mail()` can bypass any downstream transport.

History stores no visible message body or recipient list. Definitively rejected messages, including attachment bytes, are encrypted for up to 24 hours to support retries. Accepted/unknown payloads are discarded. Metadata is retained for seven days, bounded at 500 completed entries. Limits: 10 MiB per attachment and 15 MiB generated MIME message. Changing WordPress salts invalidates saved secrets and retry payloads; reconfigure connections after such a rotation. Multisite and high-volume delivery have not been qualified.

### API transports

- Resend: save an API key and use a sender authorized in that account.
- SES: save region and credentials authorized for `ses:SendEmail`; session tokens are supported. SES account/sandbox and sender requirements still apply.
- Gmail/Workspace: create your own OAuth application, enable Gmail API, and register the redirect URI displayed on the Mail screen. Save client ID/secret and use **Connect Google account**. The requested scope is `gmail.send`. Provider consent/review requirements still apply; there is no hidden hosted connection broker.

Links to provider documentation: [Resend Send Email](https://resend.com/docs/api-reference/emails/send-email), [SES SendEmail](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html), [Gmail messages.send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send).

## Product boundaries

- Redirects match the incoming path exactly (including trailing slash); they discard the incoming query string. The destination can include its own query. Administrative routes cannot be source paths. The 404 report retains up to 200 paths for 30 days, without IPs or query strings.
- History retains 30 days or 1,000 events. It is an operational timeline, not a tamper-proof forensic log. The `terrible_history_event` action accepts event name, object ID, and a short detail string; extensions must never place secrets in those fields.
- Media supports local JPEG/PNG/GIF/WebP/AVIF/PDF files with the same extension and MIME type, up to 50 MiB and subject to PHP upload limits. External/offloaded media and symlinks are rejected. `TERRIBLE_MEDIA_DIR` can set a durable private undo directory outside the web root; otherwise host temporary-directory cleanup may remove undo copies. Reference detection is bounded and cannot prove that a file is unused by theme code, external websites, or custom plugin stores. CDN caches are not purged automatically.
- Forms intentionally provides a single contact form (name, email, message), not a form builder. Messages are saved locally before notifications. It keeps 90 days or 500 messages. Failed/unknown notification attempts do not lose the submission; manually sending another notification may duplicate a previous unacknowledged notification. Cached forms must refresh within the lifetime of WordPress nonces. Rate limits are basic abuse resistance, not a promise to eliminate spam.
- Login protects WordPress password authentication. Enrolled/required accounts cannot use application passwords. Existing browser sessions are not challenged every request; enrollment invalidates other sessions for the enrolling user. Keep recovery codes outside the website. SSO integrations and multisite are not qualified. Emergency recovery through an authorized filesystem/WP-CLI administrator can deactivate the plugin; there is no email-only bypass.
- Metadata yields automatically to detected Yoast, Rank Math, or AIOSEO. `terrible_metadata_enabled` can suppress output for other SEO systems. It does not duplicate core canonical links or XML sitemaps. It does not guarantee how search/social services display a page.
- Links indexes 20 posts and checks five URLs per requested batch; it does not crawl continuously. HTTP 404/410 are broken; authentication/rate-limit/network failures are unverified. Requests validate redirect destinations against private-network SSRF. Replacements update stored HTML links/images; arbitrary dynamic blocks, shortcode-generated URLs, CSS, and plugin-specific stores are outside this version's scope. The inventory is capped at 5,000 references; reports display at most 500.
- Revisions applies title, body, excerpt, taxonomy terms, featured image, and page template, not arbitrary custom fields. It detects changed live snapshots before applying. Publication uses InnoDB transactions and row locks; non-InnoDB content tables are rejected. Scheduled updates depend on WordPress cron and can run late on quiet sites. Publisher permissions are checked again at execution. Deactivation cancels schedules and keeps drafts; reschedule explicitly after reactivation.

## Removal and data ownership

Deactivation stops each product's hooks. Forms/Mail recurring jobs and Revisions schedules are cleared on deactivation. Settings, histories, inbox entries, recovery configuration, and content remain in place when plugin files are removed; there is no destructive uninstall hook. Use the clear/delete controls and exports before removal as appropriate. Backups and Media private files are retained for deliberate recovery/cleanup. Removing an Integrations pack preserves parent settings and history; affected providers fail explicitly until reinstalled.

## Development and verification

Keep this repository beside `terrible-backups`, or set `BACKUPS_SOURCE` in `.env` to its checkout. `make test-up` builds a disposable WordPress/MariaDB lab with MinIO, FTP, and local HTTP/SMTP/TLS fixtures. WordPress binds only `127.0.0.1:18766`. `make test` exercises both the existing Backups regression suite and this suite. Tests deliberately replace content and restore databases inside these disposable volumes. Never point these scripts at a real site.

`make test-down` stops the lab and preserves its volumes. Test output is written to `test-results/verification.log`. The test CA and private key under `tests/certs/` are generated locally and excluded from packages and Git. No real storage/mail credentials are used.

See `EXTENSIONS.md` for the public Mail extension contract and the sibling Backups `EXTENSIONS.md` for storage.
