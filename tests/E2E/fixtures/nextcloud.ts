/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test as base, expect, Page } from '@playwright/test'

export const config = {
	baseUrl: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
	testUser1: process.env.TEST_USER || 'testuser1',
	testPassword1: process.env.TEST_PASSWORD || 'testpass123',
	testUser2: process.env.TEST_USER_2 || 'testuser2',
	testPassword2: process.env.TEST_PASSWORD_2 || 'testpass123',
}

export const selectors = {
	fileRow: (filename: string) => `[data-cy-files-list-row-name="${filename}"], [data-cy-files-list-row]:has-text("${filename}")`,
	fileRowByName: '[data-cy-files-list-row]',
	fileList: '[data-cy-files-list]',
	fileActions: '[data-cy-files-list-row-actions]',
	fileSidebar: '[data-cy-sidebar]',
	sidebarTab: (id: string) => `[data-cy-sidebar-tab-id="${id}"]`,
	labelsSidebarTab: '[data-cy-sidebar-tab-id="files_labels"]',
	labelsContainer: '.labels-sidebar-tab',
	labelsList: '.labels-list',
	labelItem: '.label-item',
	labelItemByKey: (key: string) => `.label-item[data-label-key="${key}"], .label-item:has-text("${key}")`,
	labelKey: '.label-key',
	labelValue: '.label-value',
	addLabelForm: '.add-label-form',
	labelKeyInput: 'input[placeholder*="key"], input.label-key-input',
	labelValueInput: 'input[placeholder*="value"], input.label-value-input',
	addLabelButton: 'button:has-text("Add"), .add-label-button',
	editLabelButton: '.edit-label-button, button[aria-label*="Edit"]',
	deleteLabelButton: '.delete-label-button, button[aria-label*="Delete"]',
	saveLabelButton: 'button:has-text("Save")',
	cancelButton: 'button:has-text("Cancel")',
	successNotification: '.toastify.toast-success, .success',
	errorNotification: '.toastify.toast-error, .error',
	breadcrumb: '.files-list__header-breadcrumbs',
}

export interface Label { key: string; value: string }

export class NextcloudLabelsPage {
	constructor(public readonly page: Page) {}

	async goToFiles(folder: string = '/') {
		const url = folder === '/' ? `${config.baseUrl}/apps/files` : `${config.baseUrl}/apps/files/?dir=${encodeURIComponent(folder)}`
		await this.page.goto(url)
		await this.page.waitForSelector(selectors.fileList, { timeout: 30000 })
	}

	getFileRow(filename: string) { return this.page.locator(selectors.fileRow(filename)).first() }

	async getFileId(filename: string): Promise<number | null> {
		const fileRow = this.getFileRow(filename)
		const fileId = await fileRow.getAttribute('data-cy-files-list-row-fileid') || await fileRow.getAttribute('data-file-id') || await fileRow.getAttribute('data-id')
		return fileId ? parseInt(fileId, 10) : null
	}

	async openSidebar(filename: string) {
		await this.getFileRow(filename).click()
		await this.page.waitForSelector(selectors.fileSidebar, { timeout: 10000 })
	}

	async openLabelsTab() {
		await this.page.locator(selectors.labelsSidebarTab).click()
		await this.page.waitForSelector(selectors.labelsContainer, { timeout: 5000 })
	}

	async openLabelsSidebar(filename: string) { await this.openSidebar(filename); await this.openLabelsTab() }

	async getLabelsViaAPI(fileId: number): Promise<Record<string, string>> {
		const response = await this.page.request.get(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(response.ok()).toBeTruthy()
		return (await response.json()).ocs?.data || {}
	}

	async getBulkLabelsViaAPI(fileIds: number[]): Promise<Record<number, Record<string, string>>> {
		const response = await this.page.request.post(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/bulk`, { headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: { fileIds } })
		expect(response.ok()).toBeTruthy()
		return (await response.json()).ocs?.data || {}
	}

	async setLabelViaAPI(fileId: number, key: string, value: string) {
		const response = await this.page.request.put(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}/${key}`, { headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: { value } })
		expect(response.ok()).toBeTruthy()
	}

	async setBulkLabelsViaAPI(fileId: number, labels: Record<string, string>) {
		const response = await this.page.request.put(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}`, { headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: { labels } })
		expect(response.ok()).toBeTruthy()
	}

	async deleteLabelViaAPI(fileId: number, key: string) {
		const response = await this.page.request.delete(`${config.baseUrl}/ocs/v2.php/apps/files_labels/api/v1/labels/${fileId}/${key}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(response.ok()).toBeTruthy()
	}

	async clearAllLabelsViaAPI(fileId: number) {
		const labels = await this.getLabelsViaAPI(fileId)
		for (const key of Object.keys(labels)) await this.deleteLabelViaAPI(fileId, key)
	}

	async addLabelViaUI(key: string, value: string) {
		await this.page.fill(selectors.labelKeyInput, key)
		await this.page.fill(selectors.labelValueInput, value)
		await this.page.click(selectors.addLabelButton)
	}

	async getLabelsFromUI(): Promise<Label[]> {
		const labels: Label[] = []
		const items = this.page.locator(selectors.labelItem)
		for (let i = 0; i < await items.count(); i++) {
			const item = items.nth(i)
			labels.push({ key: (await item.locator(selectors.labelKey).textContent() || '').trim(), value: (await item.locator(selectors.labelValue).textContent() || '').trim() })
		}
		return labels
	}

	async hasLabelInUI(key: string, value?: string): Promise<boolean> {
		const labels = await this.getLabelsFromUI()
		return labels.some(l => l.key === key && (value === undefined || l.value === value))
	}

	async deleteLabelViaUI(key: string) { await this.page.locator(selectors.labelItemByKey(key)).locator(selectors.deleteLabelButton).click() }

	async editLabelViaUI(key: string, newValue: string) {
		const item = this.page.locator(selectors.labelItemByKey(key))
		await item.locator(selectors.editLabelButton).click()
		await item.locator('input').fill(newValue)
		await item.locator(selectors.saveLabelButton).click()
	}

	async waitForSuccess(timeout = 5000) { await this.page.waitForSelector(selectors.successNotification, { timeout }) }
	async waitForError(timeout = 5000) { await this.page.waitForSelector(selectors.errorNotification, { timeout }) }
	async countLabelsInUI() { return await this.page.locator(selectors.labelItem).count() }
}

export const test = base.extend<{ labels: NextcloudLabelsPage }>({
	labels: async ({ page }, use) => { await use(new NextcloudLabelsPage(page)) },
})

export { expect }
