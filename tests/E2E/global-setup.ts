/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test as setup, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const TEST_USER = process.env.TEST_USER || 'testuser1'
const TEST_PASSWORD = process.env.TEST_PASSWORD || 'testpass123'
const TEST_USER_2 = process.env.TEST_USER_2 || 'testuser2'
const TEST_PASSWORD_2 = process.env.TEST_PASSWORD_2 || 'testpass123'

async function loginAndSaveState(
	page: import('@playwright/test').Page,
	username: string,
	password: string,
	storageStatePath: string
) {
	await page.goto(`${NEXTCLOUD_URL}/login`)
	await page.waitForSelector('input[name="user"]', { state: 'visible', timeout: 30000 })
	await page.fill('input[name="user"]', username)
	await page.fill('input[name="password"]', password)
	await page.click('button[type="submit"], input[type="submit"]')
	await expect(page).toHaveURL(/\/(apps\/(dashboard|files)|index\.php)/, { timeout: 30000 })
	await page.context().storageState({ path: storageStatePath })
}

setup('authenticate testuser1', async ({ page }) => {
	await loginAndSaveState(page, TEST_USER, TEST_PASSWORD, 'tests/E2E/.auth/testuser1.json')
})

setup('authenticate testuser2', async ({ page }) => {
	await loginAndSaveState(page, TEST_USER_2, TEST_PASSWORD_2, 'tests/E2E/.auth/testuser2.json')
})
