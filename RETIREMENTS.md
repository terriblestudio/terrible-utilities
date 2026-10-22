# Product decisions and migration

## History → Stream

Terrible History is retired. Source remains under `retired/terrible-history`; existing history tables are retained when deactivated or removed. No automatic import into Stream is attempted. Export old history with its existing export screen before removing the plugin if an archive is needed. Reactivating the preserved plugin temporarily restores access to its data.

[Stream](https://wordpress.org/plugins/stream/) is optional. Every retained core bundles a guarded Stream connector. With Stream active, it adds fixed, secret-free messages for backup completion/restore and destination changes, mail configuration changes, media replacement/undo, prepared-update publication/scheduling, redirect changes, and authenticator enrollment. It deliberately ignores arbitrary detail strings. Without Stream, the utilities operate normally. Mail submission history, backup jobs, and other operational records remain in their owning products.

## Forms paused

Terrible Forms is removed from the release lineup; source remains under `paused/terrible-forms`. Deactivation retains its inbox and settings and stops recurring cleanup. There is no destructive uninstall or automatic inbox migration. Existing `[terrible_form]` shortcodes must be replaced deliberately; installing another form plugin does not interpret them. Keep an export of the old inbox, or reactivate the preserved plugin temporarily to access it.

The demo uses [VS Contact Form](https://wordpress.org/plugins/very-simple-contact-form/) 19.1 for assessment. It offers a contact block/`[contact]` shortcode and name, email, subject, message, and consent fields. This is a reasonable fit for a conventional contact page, not a general form builder.

Enable **List submissions** to retain a local inbox before notification. Disable unnecessary auto-replies and IP collection. Keep labels visible. Enable visible spam errors; do not enable settings that silently ignore submissions. Exclude the contact page from full-page caching: its session/time/spam checks depend on fresh form state. A failed notification can leave a saved entry while displaying failure to the visitor; a resubmission can duplicate it. There is no tested automatic migration from Terrible Forms, exactly-once submission guarantee, or equivalence to its retry/inbox behavior.

Replacement plugin code is installed from WordPress.org only into the demo; it is not vendored in Terrible ZIPs and is not a dependency of any utility.
