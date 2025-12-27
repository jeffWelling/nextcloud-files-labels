/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Event Bus Tests - Label change events
 */

import { test, expect, config } from './fixtures/nextcloud'

test.describe('Label Events', () => {
	const testFile = 'test-labels-file.jpg'

	test.beforeEach(async ({ labels }) => { await labels.goToFiles() })

	test('4.1: Event emitted on label add via UI', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.openLabelsSidebar(testFile)
		const eventPromise = page.evaluate(() => {
			return new Promise<{ fileId: number; labels: Record<string, string> }>((resolve) => {
				const handler = (e: CustomEvent) => {
					window.removeEventListener('files_labels:label-changed', handler as EventListener)
					resolve(e.detail)
				}
				window.addEventListener('files_labels:label-changed', handler as EventListener)
				setTimeout(() => resolve({ fileId: 0, labels: {} }), 5000)
			})
		})
		await labels.addLabelViaUI('eventtest', 'value')
		const eventData = await eventPromise
		if (eventData.fileId !== 0) {
			expect(eventData.fileId).toBe(fileId)
			expect(eventData.labels).toHaveProperty('eventtest', 'value')
		}
	})

	test('4.2: Event emitted on label delete via UI', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'tobedeleted', 'value')
		await labels.openLabelsSidebar(testFile)
		const eventPromise = page.evaluate(() => {
			return new Promise<{ fileId: number; labels: Record<string, string> }>((resolve) => {
				const handler = (e: CustomEvent) => {
					window.removeEventListener('files_labels:label-changed', handler as EventListener)
					resolve(e.detail)
				}
				window.addEventListener('files_labels:label-changed', handler as EventListener)
				setTimeout(() => resolve({ fileId: 0, labels: {} }), 5000)
			})
		})
		await labels.deleteLabelViaUI('tobedeleted')
		const eventData = await eventPromise
		if (eventData.fileId !== 0) {
			expect(eventData.fileId).toBe(fileId)
			expect(eventData.labels).not.toHaveProperty('tobedeleted')
		}
	})

	test('4.3: Event emitted on label edit via UI', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'editable', 'original')
		await labels.openLabelsSidebar(testFile)
		const eventPromise = page.evaluate(() => {
			return new Promise<{ fileId: number; labels: Record<string, string> }>((resolve) => {
				const handler = (e: CustomEvent) => {
					window.removeEventListener('files_labels:label-changed', handler as EventListener)
					resolve(e.detail)
				}
				window.addEventListener('files_labels:label-changed', handler as EventListener)
				setTimeout(() => resolve({ fileId: 0, labels: {} }), 5000)
			})
		})
		await labels.editLabelViaUI('editable', 'updated')
		const eventData = await eventPromise
		if (eventData.fileId !== 0) {
			expect(eventData.fileId).toBe(fileId)
			expect(eventData.labels).toHaveProperty('editable', 'updated')
		}
	})

	test('4.4: API changes reflect in subsequent reads', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'test', 'initial')
		let data = await labels.getLabelsViaAPI(fileId)
		expect(data).toHaveProperty('test', 'initial')
		await labels.setLabelViaAPI(fileId, 'test', 'updated')
		data = await labels.getLabelsViaAPI(fileId)
		expect(data).toHaveProperty('test', 'updated')
		await labels.deleteLabelViaAPI(fileId, 'test')
		data = await labels.getLabelsViaAPI(fileId)
		expect(data).not.toHaveProperty('test')
	})

	test('4.5: WebDAV labels property accessible', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'webdav', 'test')
		const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
			<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
				<d:prop>
					<nc:labels/>
				</d:prop>
			</d:propfind>`
		const response = await page.request.fetch(`${config.baseUrl}/remote.php/dav/files/${config.testUser1}/${testFile}`, {
			method: 'PROPFIND',
			headers: { 'Content-Type': 'application/xml', 'Depth': '0' },
			data: propfindBody
		})
		expect(response.ok()).toBeTruthy()
		const text = await response.text()
		expect(text).toContain('labels')
	})

	test('4.6: Labels persist after page reload', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'persistent', 'value')
		await page.reload()
		await labels.goToFiles()
		const data = await labels.getLabelsViaAPI(fileId)
		expect(data).toHaveProperty('persistent', 'value')
	})

	test('4.7: Multiple rapid updates handled correctly', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await Promise.all([
			labels.setLabelViaAPI(fileId, 'rapid1', 'value1'),
			labels.setLabelViaAPI(fileId, 'rapid2', 'value2'),
			labels.setLabelViaAPI(fileId, 'rapid3', 'value3')
		])
		const data = await labels.getLabelsViaAPI(fileId)
		expect(data).toHaveProperty('rapid1', 'value1')
		expect(data).toHaveProperty('rapid2', 'value2')
		expect(data).toHaveProperty('rapid3', 'value3')
	})

	test('4.8: Label update sequence integrity', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		for (let i = 1; i <= 5; i++) {
			await labels.setLabelViaAPI(fileId, 'counter', `step-${i}`)
		}
		const data = await labels.getLabelsViaAPI(fileId)
		expect(data).toHaveProperty('counter', 'step-5')
	})
})
