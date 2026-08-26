# Single source of truth for what CI runs. `make ci` locally == the pipeline.
#
# Targets that need a database expect the WordPress test suite to be installed.
# `bin/ci-local.sh` wraps that setup with wp-env so a contributor goes from clone to
# green in two commands. See README.md.

SHELL := /bin/bash
COMPOSER ?= composer
VENDOR_BIN := vendor/bin

WP_VERSION ?= latest
DB_NAME ?= wordpress_test
DB_USER ?= root
DB_PASS ?=
DB_HOST ?= 127.0.0.1

.DEFAULT_GOAL := help
.PHONY: help install lint lint-fix compat analyse test test-ms coverage ci clean

help: ## Show this help.
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install dev dependencies and the WordPress test suite.
	$(COMPOSER) install
	bin/install-wp-tests.sh $(DB_NAME) $(DB_USER) "$(DB_PASS)" $(DB_HOST) $(WP_VERSION)

lint: ## Check coding standards.
	$(VENDOR_BIN)/phpcs

lint-fix: ## Fix what can be fixed automatically.
	$(VENDOR_BIN)/phpcbf

compat: ## Check PHP 7.4+ compatibility.
	$(VENDOR_BIN)/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- \
		--extensions=php --ignore=vendor/*,node_modules/* \
		src plugin cli secrets-api.php

analyse: ## Run static analysis.
	$(VENDOR_BIN)/phpstan analyse --memory-limit=1G --no-progress

test: ## Run the single-site suite.
	$(VENDOR_BIN)/phpunit

test-ms: ## Run the multisite suite.
	WP_MULTISITE=1 $(VENDOR_BIN)/phpunit -c phpunit-multisite.xml.dist

coverage: ## Run the single-site suite with coverage.
	$(VENDOR_BIN)/phpunit --coverage-html coverage --coverage-text

ci: lint compat analyse test test-ms ## Everything CI runs.

clean: ## Remove generated artefacts.
	rm -rf vendor coverage .phpunit.result.cache .phpcs.cache
