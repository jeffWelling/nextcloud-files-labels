# Makefile for files_labels Nextcloud app
#
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

.PHONY: help install build dev watch test test-unit test-e2e test-e2e-ui test-e2e-debug clean lint

# Default target
help:
	@echo "files_labels - Nextcloud File Labels App"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Build targets:"
	@echo "  install      Install npm and composer dependencies"
	@echo "  build        Build frontend for production"
	@echo "  dev          Build frontend with watch mode"
	@echo "  watch        Alias for dev"
	@echo ""
	@echo "Test targets:"
	@echo "  test         Run all tests (unit + e2e)"
	@echo "  test-unit    Run PHPUnit tests only"
	@echo "  test-e2e     Run Playwright E2E tests"
	@echo "  test-e2e-ui  Run E2E tests with Playwright UI"
	@echo "  test-e2e-debug  Run E2E tests in debug mode"
	@echo ""
	@echo "Other targets:"
	@echo "  clean        Remove build artifacts and dependencies"
	@echo "  lint         Run linters"
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

# Run all tests
test: test-unit test-e2e

# Run PHPUnit tests
test-unit:
	@if [ -f vendor/bin/phpunit ]; then \
		vendor/bin/phpunit; \
	elif [ -f tests/phpunit.xml ]; then \
		echo "PHPUnit not installed. Run 'make install' first or run from Nextcloud root."; \
	else \
		echo "No PHPUnit tests found."; \
	fi

# Run Playwright E2E tests
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

# Clean build artifacts
clean:
	rm -rf node_modules
	rm -rf vendor
	rm -rf js/*.js js/*.js.map
	rm -rf playwright-report
	rm -rf test-results

# Run linters
lint:
	@echo "No linting configured. Add eslint if needed."
