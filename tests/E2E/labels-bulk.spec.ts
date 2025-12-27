/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Bulk Operations Tests - Batch get/set operations
 */

import { test, expect, config } from './fixtures/nextcloud'

test.describe('Bulk Operations', () => {
	const testFiles = ['test-labels-file.jpg', 'test-labels-file2.jpg', 'test-labels-file3.jpg']

	test.beforeEach(async ({ labels }) => { await labels.goToFiles() })

	test('2.1: Bulk get labels for multiple files', async ({ labels }) => {
		const fileIds: number[] = []
		for (const filename of testFiles) {
			const fileRow = labels.getFileRow(filename)
			if (await fileRow.count() === 0) continue
			const fileId = await labels.getFileId(filename)
			if (fileId) fileIds.push(fileId)
		}
		if (fileIds.length < 2) { test.skip(); return }
		for (const fileId of fileIds) {
			await labels.clearAllLabelsViaAPI(fileId)
			await labels.setLabelViaAPI(fileId, 'project', `file-${fileId}`)
		}
		const bulkLabels = await labels.getBulkLabelsViaAPI(fileIds)
		for (const fileId of fileIds) {
			expect(bulkLabels[fileId]).toBeDefined()
			expect(bulkLabels[fileId]).toHaveProperty('project', `file-${fileId}`)
		}
	})

	test('2.2: Bulk set labels on single file', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setBulkLabelsViaAPI(fileId, {
			category: 'photos',
			year: '2024',
			event: 'vacation'
		})
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('category', 'photos')
		expect(labelsData).toHaveProperty('year', '2024')
		expect(labelsData).toHaveProperty('event', 'vacation')
	})

	test('2.3: Bulk set replaces existing labels', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'old', 'value')
		await labels.setBulkLabelsViaAPI(fileId, { old: 'new-value', extra: 'added' })
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('old', 'new-value')
		expect(labelsData).toHaveProperty('extra', 'added')
	})

	test('2.4: Bulk get with empty file list', async ({ page }) => {
		const response = await page.request.post(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/bulk`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { fileIds: [] }
		})
		expect(response.ok()).toBeTruthy()
		const data = await response.json()
		expect(data.ocs?.data || {}).toEqual({})
	})

	test('2.5: Bulk get with non-existent file IDs', async ({ page }) => {
		const response = await page.request.post(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/bulk`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { fileIds: [999999998, 999999999] }
		})
		expect(response.ok()).toBeTruthy()
		const data = await response.json()
		const labels = data.ocs?.data || {}
		expect(Object.keys(labels)).toHaveLength(0)
	})

	test('2.6: Bulk get with mix of valid and invalid IDs', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'test', 'value')
		const response = await page.request.post(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/bulk`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { fileIds: [fileId, 999999999] }
		})
		expect(response.ok()).toBeTruthy()
		const data = await response.json()
		const labelsData = data.ocs?.data || {}
		expect(labelsData[fileId.toString()]).toHaveProperty('test', 'value')
		expect(labelsData['999999999']).toBeUndefined()
	})

	test('2.7: Bulk set with empty labels object', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'existing', 'value')
		await labels.setBulkLabelsViaAPI(fileId, {})
		const labelsData = await labels.getLabelsViaAPI(fileId)
		expect(labelsData).toHaveProperty('existing', 'value')
	})

	test('2.8: Bulk set with invalid key in batch', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		const response = await page.request.put(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { labels: { 'valid': 'ok', 'INVALID': 'fail' } },
			failOnStatusCode: false
		})
		expect(response.status()).toBe(400)
	})

	test('2.9: Bulk get performance with many files', async ({ labels }) => {
		const fileIds: number[] = []
		for (const filename of testFiles) {
			const fileRow = labels.getFileRow(filename)
			if (await fileRow.count() === 0) continue
			const fileId = await labels.getFileId(filename)
			if (fileId) fileIds.push(fileId)
		}
		if (fileIds.length === 0) { test.skip(); return }
		const startTime = Date.now()
		await labels.getBulkLabelsViaAPI(fileIds)
		const duration = Date.now() - startTime
		expect(duration).toBeLessThan(5000)
	})

	test('2.10: Bulk operations maintain data integrity', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFiles[0])
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFiles[0])
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		const testData = {
			alpha: 'first',
			beta: 'second',
			gamma: 'third',
			delta: 'fourth',
			epsilon: 'fifth'
		}
		await labels.setBulkLabelsViaAPI(fileId, testData)
		const labelsData = await labels.getLabelsViaAPI(fileId)
		for (const [key, value] of Object.entries(testData)) {
			expect(labelsData).toHaveProperty(key, value)
		}
	})
})
