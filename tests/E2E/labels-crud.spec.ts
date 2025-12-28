/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Labels CRUD Tests - Create, Read, Update, Delete operations
 */

import { test, expect, config } from './fixtures/nextcloud'

test.describe('Labels CRUD Operations', () => {
	const testFile = 'test-labels-file.jpg'

	test.beforeEach(async ({ labels }) => { await labels.goToFiles() })

	test('1.1: Create label via API', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'category', 'photos')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('category', 'photos')
	})

	test('1.2: Read labels via API', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'project', 'vacation')
		await labels.setLabelViaAPI(fileId, 'year', '2024')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('project', 'vacation')
		expect(labelsData).toHaveProperty('year', '2024')
	})

	test('1.3: Update label via API', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'status', 'draft')
		let labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('status', 'draft')
		await labels.setLabelViaAPI(fileId, 'status', 'published')
		labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('status', 'published')
	})

	test('1.4: Delete label via API', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'tag1', 'value1')
		await labels.setLabelViaAPI(fileId, 'tag2', 'value2')
		await labels.deleteLabelViaAPI(fileId, 'tag1')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).not.toHaveProperty('tag1')
		expect(labelsData).toHaveProperty('tag2', 'value2')
	})

	test('1.5: New file has no labels', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(Object.keys(labelsData)).toHaveLength(0)
	})

	test('1.6: Label key must be lowercase', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		const response = await page.request.put(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}/UPPERCASE`, { headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: { value: 'test' }, failOnStatusCode: false })
		expect(response.status()).toBe(400)
	})

	test('1.7: Label key cannot contain spaces', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		const response = await page.request.put(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}/has%20spaces`, { headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: { value: 'test' }, failOnStatusCode: false })
		expect(response.status()).toBe(400)
	})

	test('1.8: Label key with valid special characters', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		const validKeys = ['simple', 'with-dash', 'with_underscore', 'with.dot', 'with:colon']
		for (const key of validKeys) await labels.setLabelViaAPI(fileId, key, 'value')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		for (const key of validKeys) expect(labelsData).toHaveProperty(key, 'value')
	})

	test('1.9: Label value can be empty', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'flag', '')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('flag', '')
	})

	test('1.10: Label value supports Unicode', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'greeting', '你好世界 🎉')
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('greeting', '你好世界 🎉')
	})

	test('1.11: 404 for non-existent file', async ({ page }) => {
		const response = await page.request.get(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/999999999`, { headers: { 'OCS-APIRequest': 'true' }, failOnStatusCode: false })
		expect(response.status()).toBe(404)
	})

	test('1.12: Delete non-existent label returns 404', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		const response = await page.request.delete(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}/nonexistent`, { headers: { 'OCS-APIRequest': 'true' }, failOnStatusCode: false })
		expect(response.status()).toBe(404)
	})
})
