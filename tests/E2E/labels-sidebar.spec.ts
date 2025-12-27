/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Sidebar UI Tests - Labels sidebar tab interaction
 */

import { test, expect, selectors } from './fixtures/nextcloud'

test.describe('Labels Sidebar UI', () => {
	const testFile = 'test-labels-file.jpg'

	test.beforeEach(async ({ labels }) => { await labels.goToFiles() })

	test('3.1: Sidebar tab is visible for file', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		await labels.openSidebar(testFile)
		const labelsTab = page.locator(selectors.labelsSidebarTab)
		await expect(labelsTab).toBeVisible({ timeout: 10000 })
	})

	test('3.2: Open labels tab shows container', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		await labels.openLabelsSidebar(testFile)
		const container = page.locator(selectors.labelsContainer)
		await expect(container).toBeVisible()
	})

	test('3.3: Add label via UI', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.openLabelsSidebar(testFile)
		await labels.addLabelViaUI('category', 'test-photos')
		const hasLabel = await labels.hasLabelInUI('category', 'test-photos')
		expect(hasLabel).toBe(true)
	})

	test('3.4: Labels list displays all labels', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'key1', 'value1')
		await labels.setLabelViaAPI(fileId, 'key2', 'value2')
		await labels.setLabelViaAPI(fileId, 'key3', 'value3')
		await labels.openLabelsSidebar(testFile)
		const count = await labels.countLabelsInUI()
		expect(count).toBe(3)
	})

	test('3.5: Edit label via UI', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'editable', 'original')
		await labels.openLabelsSidebar(testFile)
		await labels.editLabelViaUI('editable', 'modified')
		const hasNewValue = await labels.hasLabelInUI('editable', 'modified')
		expect(hasNewValue).toBe(true)
	})

	test('3.6: Delete label via UI', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'deleteme', 'value')
		await labels.openLabelsSidebar(testFile)
		expect(await labels.hasLabelInUI('deleteme')).toBe(true)
		await labels.deleteLabelViaUI('deleteme')
		expect(await labels.hasLabelInUI('deleteme')).toBe(false)
	})

	test('3.7: Empty state when no labels', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.openLabelsSidebar(testFile)
		const count = await labels.countLabelsInUI()
		expect(count).toBe(0)
	})

	test('3.8: Add label form has key and value inputs', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		await labels.openLabelsSidebar(testFile)
		const keyInput = page.locator(selectors.labelKeyInput)
		const valueInput = page.locator(selectors.labelValueInput)
		await expect(keyInput).toBeVisible()
		await expect(valueInput).toBeVisible()
	})

	test('3.9: Add button is present', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		await labels.openLabelsSidebar(testFile)
		const addButton = page.locator(selectors.addLabelButton)
		await expect(addButton).toBeVisible()
	})

	test('3.10: Label shows key and value separately', async ({ labels, page }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.setLabelViaAPI(fileId, 'mykey', 'myvalue')
		await labels.openLabelsSidebar(testFile)
		const labelItem = page.locator(selectors.labelItem).first()
		const keyElement = labelItem.locator(selectors.labelKey)
		const valueElement = labelItem.locator(selectors.labelValue)
		await expect(keyElement).toContainText('mykey')
		await expect(valueElement).toContainText('myvalue')
	})

	test('3.11: UI syncs with API changes', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		await labels.openLabelsSidebar(testFile)
		expect(await labels.countLabelsInUI()).toBe(0)
		await labels.setLabelViaAPI(fileId, 'apiset', 'value')
		await labels.goToFiles()
		await labels.openLabelsSidebar(testFile)
		expect(await labels.hasLabelInUI('apiset', 'value')).toBe(true)
	})

	test('3.12: Multiple labels display correctly', async ({ labels }) => {
		const fileRow = labels.getFileRow(testFile)
		if (await fileRow.count() === 0) { test.skip(); return }
		const fileId = await labels.getFileId(testFile)
		if (!fileId) { test.skip(); return }
		await labels.clearAllLabelsViaAPI(fileId)
		const testLabels = [
			{ key: 'alpha', value: 'first' },
			{ key: 'beta', value: 'second' },
			{ key: 'gamma', value: 'third' },
			{ key: 'delta', value: 'fourth' },
			{ key: 'epsilon', value: 'fifth' }
		]
		for (const label of testLabels) {
			await labels.setLabelViaAPI(fileId, label.key, label.value)
		}
		await labels.openLabelsSidebar(testFile)
		const uiLabels = await labels.getLabelsFromUI()
		expect(uiLabels.length).toBe(5)
		for (const label of testLabels) {
			expect(uiLabels.some(l => l.key === label.key && l.value === label.value)).toBe(true)
		}
	})
})
