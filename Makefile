# Makefile for files_labels Nextcloud app
#
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

.PHONY: help install build dev watch test test-unit test-e2e test-e2e-ui test-e2e-debug \
        test-sqlite test-mysql test-pgsql test-all-databases \
        pull-images start-sqlite start-mysql start-pgsql stop-all \
        clean lint

# Default target
help:
	@echo "files_labels - Nextcloud File Labels App"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Setup targets:"
	@echo "  install         Install npm and composer dependencies"
	@echo "  pull-images     Pull all required container images"
	@echo ""
	@echo "Build targets:"
	@echo "  build           Build frontend for production"
	@echo "  dev             Build frontend with watch mode"
	@echo "  watch           Alias for dev"
	@echo ""
	@echo "Test targets:"
	@echo "  test            Run all tests (unit + E2E against all databases)"
	@echo "  test-unit       Run Jest unit tests only"
	@echo "  test-e2e        Run Playwright E2E tests (default environment)"
	@echo "  test-e2e-ui     Run E2E tests with Playwright UI"
	@echo "  test-e2e-debug  Run E2E tests in debug mode"
	@echo ""
	@echo "Database-specific tests:"
	@echo "  test-sqlite     Run E2E tests against SQLite"
	@echo "  test-mysql      Run E2E tests against MySQL 8.0"
	@echo "  test-pgsql      Run E2E tests against PostgreSQL 15"
	@echo "  test-all-databases  Run E2E tests against all databases"
	@echo ""
	@echo "Environment management:"
	@echo "  start-sqlite    Start SQLite test environment (port 8081)"
	@echo "  start-mysql     Start MySQL test environment (port 8082)"
	@echo "  start-pgsql     Start PostgreSQL test environment (port 8083)"
	@echo "  stop-all        Stop all test environments"
	@echo ""
	@echo "Other targets:"
	@echo "  clean           Remove build artifacts and dependencies"
	@echo "  lint            Run linters (eslint, php-cs-fixer)"
	@echo ""

# Install dependencies
install:
	npm install
	@if [ -f composer.json ]; then composer install --no-interaction; fi

# Build frontend for production
build:
	npm run build

# Development build with watch
dev:
	npm run dev

watch: dev

# ============================================================================
# Testing
# ============================================================================

# Run all tests (unit + all databases)
test: test-unit test-all-databases

# Run Jest unit tests
test-unit:
	npm run test

# Run Playwright E2E tests (using default docker-compose.yml)
test-e2e:
	npx playwright install --with-deps chromium
	npm run test:e2e

# Run E2E tests with Playwright UI
test-e2e-ui:
	npx playwright install --with-deps chromium
	npm run test:e2e:ui

# Run E2E tests in debug mode
test-e2e-debug:
	npx playwright install --with-deps chromium
	npm run test:e2e:debug

# View E2E test report
test-e2e-report:
	npm run test:e2e:report

# ============================================================================
# Multi-Database Testing
# ============================================================================

# Pull all required container images
pull-images:
	podman pull docker.io/library/nextcloud:29
	podman pull docker.io/library/mysql:8.0
	podman pull docker.io/library/postgres:15-alpine

# Run tests against SQLite
test-sqlite: build
	@echo "Testing with SQLite..."
	@chmod +x scripts/test-all-databases.sh
	./scripts/test-all-databases.sh sqlite

# Run tests against MySQL
test-mysql: build
	@echo "Testing with MySQL..."
	@chmod +x scripts/test-all-databases.sh
	./scripts/test-all-databases.sh mysql

# Run tests against PostgreSQL
test-pgsql: build
	@echo "Testing with PostgreSQL..."
	@chmod +x scripts/test-all-databases.sh
	./scripts/test-all-databases.sh pgsql

# Run tests against all databases
test-all-databases: build
	@echo "Testing with all databases..."
	@chmod +x scripts/test-all-databases.sh
	./scripts/test-all-databases.sh

# ============================================================================
# Environment Management
# ============================================================================

# Start SQLite test environment
start-sqlite:
	cd tests/docker && podman-compose -f docker-compose.sqlite.yml up -d
	@echo "SQLite environment starting on http://localhost:8081"
	@echo "Run 'podman exec -u www-data files_labels_test_sqlite php occ app:enable files_labels' to enable the app"

# Start MySQL test environment
start-mysql:
	cd tests/docker && podman-compose -f docker-compose.mysql.yml up -d
	@echo "MySQL environment starting on http://localhost:8082"
	@echo "Run 'podman exec -u www-data files_labels_test_mysql php occ app:enable files_labels' to enable the app"

# Start PostgreSQL test environment
start-pgsql:
	cd tests/docker && podman-compose -f docker-compose.pgsql.yml up -d
	@echo "PostgreSQL environment starting on http://localhost:8083"
	@echo "Run 'podman exec -u www-data files_labels_test_pgsql php occ app:enable files_labels' to enable the app"

# Stop all test environments
stop-all:
	cd tests/docker && podman-compose -f docker-compose.sqlite.yml down -v 2>/dev/null || true
	cd tests/docker && podman-compose -f docker-compose.mysql.yml down -v 2>/dev/null || true
	cd tests/docker && podman-compose -f docker-compose.pgsql.yml down -v 2>/dev/null || true
	@echo "All test environments stopped"

# ============================================================================
# Cleanup
# ============================================================================

# Clean build artifacts
clean:
	rm -rf node_modules
	rm -rf vendor
	rm -rf js/*.js js/*.js.map
	rm -rf playwright-report
	rm -rf test-results

# ============================================================================
# Linting
# ============================================================================

# Run linters
lint: lint-js lint-php

lint-js:
	@if [ -f .eslintrc.js ] || [ -f .eslintrc.json ]; then \
		npx eslint --ext .js,.vue src/; \
	else \
		echo "ESLint not configured. Run 'make setup-lint' to configure."; \
	fi

lint-php:
	@if [ -f .php-cs-fixer.php ] || [ -f .php-cs-fixer.dist.php ]; then \
		vendor/bin/php-cs-fixer fix --dry-run --diff lib/; \
	else \
		echo "PHP-CS-Fixer not configured. Run 'make setup-lint' to configure."; \
	fi

# Fix linting issues
lint-fix: lint-fix-js lint-fix-php

lint-fix-js:
	@if [ -f .eslintrc.js ] || [ -f .eslintrc.json ]; then \
		npx eslint --ext .js,.vue --fix src/; \
	fi

lint-fix-php:
	@if [ -f .php-cs-fixer.php ] || [ -f .php-cs-fixer.dist.php ]; then \
		vendor/bin/php-cs-fixer fix lib/; \
	fi
