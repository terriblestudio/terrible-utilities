COMPOSE = docker compose
WP = $(COMPOSE) exec -T -u www-data wordpress wp
.PHONY: check package test-up test test-down
check:
	python3 tests/sync-shared.py
	find terrible-* -name '*.php' -print0 | xargs -0 -n1 php -l
	node --check shared/admin.js
	for file in terrible-*/media.js; do node --check "$$file"; done
package: check
	python3 tests/package.py
tests/wp-cli.phar:
	curl --fail --location https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar -o $@
	printf '%s\n' 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  tests/wp-cli.phar' | shasum -a 256 -c -
test-up: tests/wp-cli.phar
	sh tests/certificates.sh
	$(COMPOSE) up -d --build --wait
	sh tests/setup.sh
test:
	mkdir -p test-results
	bash -o pipefail tests/run.sh 2>&1 | tee test-results/verification.log
test-down:
	$(COMPOSE) down
