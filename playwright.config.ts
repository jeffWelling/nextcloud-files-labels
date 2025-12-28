/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for Nextcloud files_labels E2E tests
 *
 * Environment variables:
 * - NEXTCLOUD_URL: Base URL of Nextcloud instance (default: http://localhost:8080)
 * - TEST_USER: Test user username (default: testuser1)
 * - TEST_PASSWORD: Test user password (default: testpass123)
 * - TEST_USER_2: Second test user for multi-user tests (default: testuser2)
 * - TEST_PASSWORD_2: Second test user password (default: testpass123)
 */
export default defineConfig({
	testDir: './tests/E2E',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never' }],
		['list'],
	],

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 15000,
		navigationTimeout: 30000,
	},

	projects: [
		{
			name: 'setup',
			testMatch: /global-setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				storageState: 'tests/E2E/.auth/testuser1.json',
			},
			dependencies: ['setup'],
		},
	],

	timeout: 60000,
	expect: {
		timeout: 10000,
	},
})
