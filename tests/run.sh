#!/bin/bash
set -euo pipefail
sh tests/setup.sh
docker compose exec -T wordpress sh -c 'mkdir -p /tmp/tb-tests && cp /suite/tests/backups/*.php /tmp/tb-tests/ && sed -i s/18765/18766/g /tmp/tb-tests/*.php && chown -R www-data:www-data /tmp/tb-tests'
for test in integration recovery full-restore security; do docker compose exec -T -u www-data wordpress wp eval-file "/tmp/tb-tests/$test.php"; done
# Restore tests can restore an older active-plugin list; reinstall the current source.
sh tests/setup.sh
docker compose restart mock
for test in connectivity utilities http-fixtures; do docker compose exec -T -u www-data wordpress wp eval-file "/suite/tests/$test.php"; done
python3 tests/web.py
# Original browser/API backup regression uses the disposable port.
docker compose exec -T wordpress cat /suite/tests/backups/web-integration.py | python3 -c "import sys; exec(compile(sys.stdin.read().replace('18765','18766'),'backup-http','exec'))"
docker compose exec -T -u www-data wordpress sh /suite/tests/standalone.sh
for plugin in stream very-simple-contact-form; do
    docker compose exec -T -u www-data wordpress wp plugin is-installed "$plugin" || docker compose exec -T -u www-data wordpress wp plugin install "$plugin"
done
docker compose exec -T -u www-data wordpress wp plugin activate stream very-simple-contact-form
docker compose exec -T -u www-data wordpress wp eval-file /suite/tests/polish.php
docker compose exec -T -u www-data wordpress wp eval-file /suite/tests/replacements-setup.php
python3 tests/replacements.py
printf 'ALL SUITES PASSED\n' 
