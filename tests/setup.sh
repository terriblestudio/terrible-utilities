#!/bin/sh
set -eu
# The image entrypoint copies WordPress after the container starts.
for attempt in $(seq 1 30); do
    if docker compose exec -T wordpress test -f /var/www/html/wp-includes/version.php; then break; fi
    sleep 1
done
docker compose exec -T wordpress sh -c 'cp -R /suite/terrible-* /var/www/html/wp-content/plugins/ && chown -R www-data:www-data /var/www/html/wp-content/plugins/terrible-* && cp /suite/tests/certs/ca.crt /usr/local/share/ca-certificates/terrible-test.crt && update-ca-certificates >/dev/null'
docker compose exec -T -u www-data wordpress wp core is-installed || docker compose exec -T -u www-data wordpress wp core install --url=http://localhost:18766 --title='Terrible Utilities Lab' --admin_user=backup-admin --admin_password=local-backup-test-password --admin_email=backup@example.test --skip-email
docker compose exec -T -u www-data wordpress wp plugin activate terrible-backups terrible-mail terrible-backups-integrations terrible-mail-integrations terrible-redirects terrible-media terrible-login terrible-metadata terrible-links terrible-revisions

for plugin in terrible-history terrible-forms; do
    if docker compose exec -T -u www-data wordpress wp plugin is-installed "$plugin"; then docker compose exec -T -u www-data wordpress wp plugin deactivate "$plugin"; fi
done
