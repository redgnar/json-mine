# All tools run inside the pinned Docker image (docker/Dockerfile, service "php" in docker-compose.yml).
# Local PHP is never used. Docker runs with userns remapping (rootless): container root == host user.
RUN := docker compose run --rm --no-deps php

.PHONY: image install update test coverage stan cs cs-fix validate audit deps deptrac mutation bench ci shell

image: ## Build the dev/test image
	docker compose build

install: image
	$(RUN) composer install

update: image
	$(RUN) composer update

test:
	$(RUN) vendor/bin/phpunit

coverage:
	$(RUN) vendor/bin/phpunit --coverage-text

stan:
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

cs:
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(RUN) vendor/bin/php-cs-fixer fix

validate:
	$(RUN) composer validate --strict

audit: ## Known security vulnerabilities in dependencies (abandoned transitive dev deps: report, don't fail)
	$(RUN) composer audit --abandoned=report

deps: ## Dependency hygiene: no transitive-only usage, no unused requires
	$(RUN) vendor/bin/composer-require-checker check
	$(RUN) vendor/bin/composer-unused

deptrac: ## Module boundaries (deptrac.yaml)
	$(RUN) vendor/bin/deptrac analyse --no-progress --cache-file=.cache/deptrac.cache

mutation: ## Mutation testing (Infection) — verifies test quality
	$(RUN) vendor/bin/infection --threads=max --no-progress

bench: ## Performance benchmarks (informational, not a CI gate)
	$(RUN) vendor/bin/phpbench run --report=aggregate

ci: validate cs stan deptrac test audit deps mutation ## Everything the git pipeline checks

shell: ## Interactive shell inside the dev image
	docker compose run --rm --no-deps -it php sh
