/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { shallowMount, createLocalVue } from '@vue/test-utils'
import LabelsSidebarTab from '../../../src/views/LabelsSidebarTab.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'

const localVue = createLocalVue()

// Add t mixin
localVue.mixin({
	methods: {
		t: (app, text, vars) => {
			if (vars) {
				return text.replace(/\{(\w+)\}/g, (match, key) => vars[key] || match)
			}
			return text
		},
	},
})

describe('LabelsSidebarTab', () => {
	let wrapper

	const mockFileInfo = {
		id: 123,
		name: 'test-file.txt',
	}

	beforeEach(() => {
		jest.clearAllMocks()
	})

	afterEach(() => {
		if (wrapper) {
			wrapper.destroy()
		}
	})

	describe('Component Mounting', () => {
		it('renders loading state initially', async () => {
			axios.get.mockImplementation(() => new Promise(() => {})) // Never resolves

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			expect(wrapper.find('.loading-container').exists()).toBe(true)
		})

		it('renders empty state when no labels', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.empty-content').exists()).toBe(true)
			expect(wrapper.text()).toContain('No labels yet')
		})

		it('renders labels when they exist', async () => {
			axios.get.mockResolvedValue({
				data: {
					ocs: {
						data: {
							category: 'work',
							priority: 'high',
						},
					},
				},
			})

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			expect(wrapper.find('.labels-list').exists()).toBe(true)
			expect(wrapper.findAll('.label-item').length).toBe(2)
		})
	})

	describe('Loading Labels', () => {
		it('fetches labels on mount', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/labels/123')
			)
		})

		it('shows error on fetch failure', async () => {
			axios.get.mockRejectedValue(new Error('Network error'))

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			expect(showError).toHaveBeenCalled()
			expect(wrapper.find('.error-message').exists()).toBe(true)
		})

		it('reloads labels when fileInfo changes', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()

			const newFileInfo = { id: 456, name: 'other-file.txt' }
			await wrapper.setProps({ fileInfo: newFileInfo })
			await wrapper.vm.$nextTick()

			expect(axios.get).toHaveBeenCalledTimes(2)
			expect(axios.get).toHaveBeenLastCalledWith(
				expect.stringContaining('/labels/456')
			)
		})
	})

	describe('Adding Labels', () => {
		beforeEach(async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })
			axios.put.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()
		})

		it('adds a new label successfully', async () => {
			await wrapper.setData({ newKey: 'category', newValue: 'work' })
			await wrapper.find('form').trigger('submit.prevent')
			await wrapper.vm.$nextTick()

			expect(axios.put).toHaveBeenCalledWith(
				expect.stringContaining('/labels/123/category'),
				{ value: 'work' }
			)
			expect(showSuccess).toHaveBeenCalled()
			expect(emit).toHaveBeenCalledWith('files_labels:label-changed', expect.any(Object))
		})

		it('clears form after successful add', async () => {
			await wrapper.setData({ newKey: 'category', newValue: 'work' })
			await wrapper.find('form').trigger('submit.prevent')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.newKey).toBe('')
			expect(wrapper.vm.newValue).toBe('')
		})

		it('shows error when add fails', async () => {
			axios.put.mockRejectedValue({
				response: { data: { ocs: { meta: { message: 'Rate limit exceeded' } } } },
			})

			await wrapper.setData({ newKey: 'category', newValue: 'work' })
			await wrapper.find('form').trigger('submit.prevent')
			await wrapper.vm.$nextTick()

			expect(showError).toHaveBeenCalled()
		})

		it('prevents adding duplicate key', async () => {
			wrapper.vm.labels = { category: 'existing' }
			await wrapper.setData({ newKey: 'category', newValue: 'work' })
			await wrapper.find('form').trigger('submit.prevent')

			expect(axios.put).not.toHaveBeenCalled()
			expect(showError).toHaveBeenCalledWith(
				expect.stringContaining('already exists')
			)
		})
	})

	describe('Deleting Labels', () => {
		beforeEach(async () => {
			axios.get.mockResolvedValue({
				data: { ocs: { data: { category: 'work' } } },
			})
			axios.delete.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()
		})

		it('shows confirmation dialog before deleting', async () => {
			wrapper.vm.deleteLabel('category')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.deleteConfirmKey).toBe('category')
		})

		it('deletes label after confirmation', async () => {
			wrapper.vm.deleteLabel('category')
			await wrapper.vm.$nextTick()

			await wrapper.vm.confirmDelete()
			await wrapper.vm.$nextTick()

			expect(axios.delete).toHaveBeenCalledWith(
				expect.stringContaining('/labels/123/category')
			)
			expect(showSuccess).toHaveBeenCalled()
		})

		it('emits event after deletion', async () => {
			wrapper.vm.deleteLabel('category')
			await wrapper.vm.confirmDelete()
			await wrapper.vm.$nextTick()

			expect(emit).toHaveBeenCalledWith('files_labels:label-changed', {
				fileId: 123,
				labels: {},
			})
		})

		it('clears deleteConfirmKey after deletion', async () => {
			wrapper.vm.deleteLabel('category')
			await wrapper.vm.confirmDelete()
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.deleteConfirmKey).toBeNull()
		})
	})

	describe('Accessibility', () => {
		it('has ARIA live region for announcements', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()

			const liveRegion = wrapper.find('[aria-live="polite"]')
			expect(liveRegion.exists()).toBe(true)
		})

		it('has error alert role', async () => {
			axios.get.mockRejectedValue(new Error('Network error'))

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			const errorDiv = wrapper.find('[role="alert"]')
			expect(errorDiv.exists()).toBe(true)
		})

		it('updates status announcement on label add', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })
			axios.put.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.setData({ newKey: 'test', newValue: 'value' })
			await wrapper.find('form').trigger('submit.prevent')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.statusAnnouncement).toContain('test')
		})
	})

	describe('Input Validation', () => {
		it('has maxlength of 255 on key input', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			const keyInput = wrapper.find('input[placeholder="Key"]')
			expect(keyInput.attributes('maxlength')).toBe('255')
		})

		it('has maxlength of 255 on value input', async () => {
			axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })

			wrapper = shallowMount(LabelsSidebarTab, {
				localVue,
				propsData: { fileInfo: mockFileInfo },
			})

			await wrapper.vm.$nextTick()
			await wrapper.vm.$nextTick()

			const valueInput = wrapper.find('input[placeholder="Value"]')
			expect(valueInput.attributes('maxlength')).toBe('255')
		})
	})
})
