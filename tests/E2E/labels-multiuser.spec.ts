/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Multi-user Tests - User isolation and permissions
 */

import { test as base, expect, config, selectors, NextcloudLabelsPage } from './fixtures/nextcloud'
import { chromium, Browser, BrowserContext, Page } from '@playwright/test'

const test = base.extend<{
	user2Browser: Browser
	user2Context: BrowserContext
	user2Page: Page
	user2Labels: NextcloudLabelsPage
}>({
	user2Browser: async ({}, use) => {
		const browser = await chromium.launch()
		await use(browser)
		await browser.close()
	},
	user2Context: async ({ user2Browser }, use) => {
		const context = await user2Browser.newContext({
			storageState: 'tests/E2E/.auth/testuser2.json'
		})
		await use(context)
		await context.close()
	},
	user2Page: async ({ user2Context }, use) => {
		const page = await user2Context.newPage()
		await use(page)
		await page.close()
	},
	user2Labels: async ({ user2Page }, use) => {
		await use(new NextcloudLabelsPage(user2Page))
	}
})

test.describe('Multi-user Label Isolation', () => {
	const sharedFile = 'shared-test-file.jpg'
	const testFile = 'test-labels-file.jpg'

	test('5.1: Labels are user-specific', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'user1label', 'user1value')
		const user1Labels = await labels.getLabelsViaAPI(fileId)
		expect(user1Labels).toHaveProperty('user1label', 'user1value')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(testFile)
		if (await user2FileRow.count() > 0) {
			const user2FileId = await user2Labels.getFileId(testFile)
			if (user2FileId) {
				const user2Data = await user2Labels.getLabelsViaAPI(user2FileId)
				expect(user2Data).not.toHaveProperty('user1label')
			}
		}
	})

	test('5.2: User cannot access another users labels on shared file', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(sharedFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(sharedFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'private', 'user1only')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		if (await user2FileRow.count() === 0) { test.skip(); return }
		const user2FileId = await user2Labels.getFileId(sharedFile)
		if (!user2FileId) { test.skip(); return }
		const user2Data = await user2Labels.getLabelsViaAPI(user2FileId)
		expect(user2Data).not.toHaveProperty('private')
	})

	test('5.3: Both users can set labels on shared file independently', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(sharedFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(sharedFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'owner', 'user1')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		if (await user2FileRow.count() === 0) { test.skip(); return }
		const user2FileId = await user2Labels.getFileId(sharedFile)
		if (!user2FileId) { test.skip(); return }
		await user2Labels.clearAllLabelsViaAPI(user2FileId)
		await user2Labels.setLabelViaAPI(user2FileId, 'viewer', 'user2')
		const user1Data = await labels.getLabelsViaAPI(fileId)
		expect(user1Data).toHaveProperty('owner', 'user1')
		expect(user1Data).not.toHaveProperty('viewer')
		const user2Data = await user2Labels.getLabelsViaAPI(user2FileId)
		expect(user2Data).toHaveProperty('viewer', 'user2')
		expect(user2Data).not.toHaveProperty('owner')
	})

	test('5.4: Deleting user1 label does not affect user2', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(sharedFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(sharedFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'common', 'user1version')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		if (await user2FileRow.count() === 0) { test.skip(); return }
		const user2FileId = await user2Labels.getFileId(sharedFile)
		if (!user2FileId) { test.skip(); return }
		await user2Labels.clearAllLabelsViaAPI(user2FileId)
		await user2Labels.setLabelViaAPI(user2FileId, 'common', 'user2version')
		await labels.deleteLabelViaAPI(fileId, 'common')
		const user1Data = await labels.getLabelsViaAPI(fileId)
		expect(user1Data).not.toHaveProperty('common')
		const user2Data = await user2Labels.getLabelsViaAPI(user2FileId)
		expect(user2Data).toHaveProperty('common', 'user2version')
	})

	test('5.5: Unauthorized file access returns 404', async ({ user2Labels, page }) => {
		await page.goto(`${config.baseUrl}/apps/files`)
		await page.waitForSelector(selectors.fileList, { timeout: 30000 })
		const privateFile = 'private-user1-file.jpg'
		const fileRow = page.locator(selectors.fileRow(privateFile)).first()
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await fileRow.getAttribute('data-cy-files-list-row-fileid') || await fileRow.getAttribute('data-file-id')
		if (!fileId) { test.skip(); return }
		const response = await user2Labels.page.request.get(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}`, {
			headers: { 'OCS-APIRequest': 'true' },
			failOnStatusCode: false
		})
		expect(response.status()).toBe(404)
	})

	test('5.6: Bulk operations only return accessible files', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'bulk', 'test')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		let user2FileId: number | null = null
		if (await user2FileRow.count() > 0) {
			user2FileId = await user2Labels.getFileId(sharedFile)
		}
		const fileIds = [fileId]
		if (user2FileId) fileIds.push(user2FileId)
		const bulkData = await user2Labels.getBulkLabelsViaAPI(fileIds)
		expect(bulkData[fileId.toString()]).toBeUndefined()
	})

	test('5.7: Same label key different values per user', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(sharedFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(sharedFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'priority', 'high')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		if (await user2FileRow.count() === 0) { test.skip(); return }
		const user2FileId = await user2Labels.getFileId(sharedFile)
		if (!user2FileId) { test.skip(); return }
		await user2Labels.clearAllLabelsViaAPI(user2FileId)
		await user2Labels.setLabelViaAPI(user2FileId, 'priority', 'low')
		const user1Data = await labels.getLabelsViaAPI(fileId)
		const user2Data = await user2Labels.getLabelsViaAPI(user2FileId)
		expect(user1Data).toHaveProperty('priority', 'high')
		expect(user2Data).toHaveProperty('priority', 'low')
	})

	test('5.8: User switching maintains label isolation', async ({ labels, user2Labels }) => {
		await labels.goToFiles()
		const fileRow = labels.getFileRow(sharedFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(sharedFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'switch-test', 'user1')
		await user2Labels.goToFiles()
		const user2FileRow = user2Labels.getFileRow(sharedFile)
		if (await user2FileRow.count() === 0) { test.skip(); return }
		const user2FileId = await user2Labels.getFileId(sharedFile)
		if (!user2FileId) { test.skip(); return }
		await user2Labels.clearAllLabelsViaAPI(user2FileId)
		await user2Labels.setLabelViaAPI(user2FileId, 'switch-test', 'user2')
		const user1DataCheck = await labels.getLabelsViaAPI(fileId)
		expect(user1DataCheck).toHaveProperty('switch-test', 'user1')
		const user2DataCheck = await user2Labels.getLabelsViaAPI(user2FileId)
		expect(user2DataCheck).toHaveProperty('switch-test', 'user2')
	})
})
