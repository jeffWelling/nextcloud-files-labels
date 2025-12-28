/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { shallowMount, createLocalVue } from '@vue/test-utils'
import AdminSettings from '../../../src/views/AdminSettings.vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'

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

describe('AdminSettings', () => {
	let wrapper

	beforeEach(() => {
		jest.clearAllMocks()
		loadState.mockReturnValue({ maxLabelsPerUser: 10000 })
	})

	afterEach(() => {
		if (wrapper) {
			wrapper.destroy()
		}
	})

	describe('Component Mounting', () => {
		it('renders the admin settings panel', () => {
			wrapper = shallowMount(AdminSettings, { localVue })

			expect(wrapper.find('.files-labels-admin-settings').exists()).toBe(true)
			expect(wrapper.text()).toContain('File Labels')
			expect(wrapper.text()).toContain('Rate Limiting')
		})

		it('loads initial state from Nextcloud', () => {
			wrapper = shallowMount(AdminSettings, { localVue })

			expect(loadState).toHaveBeenCalledWith('files_labels', 'admin-settings', expect.any(Object))
			expect(wrapper.vm.maxLabelsPerUser).toBe(10000)
		})

		it('displays the current max labels per user value', () => {
			loadState.mockReturnValue({ maxLabelsPerUser: 5000 })

			wrapper = shallowMount(AdminSettings, { localVue })

			const input = wrapper.find('#max-labels-per-user')
			expect(input.element.value).toBe('5000')
		})
	})

	describe('Saving Settings', () => {
		it('saves settings on input change', async () => {
			axios.put.mockResolvedValue({ data: { maxLabelsPerUser: 20000 } })

			wrapper = shallowMount(AdminSettings, { localVue })

			// Set the value through Vue data instead of DOM
			await wrapper.setData({ maxLabelsPerUser: 20000 })
			await wrapper.vm.saveSettings()
			await wrapper.vm.$nextTick()

			expect(axios.put).toHaveBeenCalledWith(
				expect.stringContaining('/admin/settings/max-labels'),
				{ value: 20000 }
			)
		})

		it('shows saving state during save', async () => {
			axios.put.mockImplementation(() => new Promise(() => {})) // Never resolves

			wrapper = shallowMount(AdminSettings, { localVue })
			wrapper.vm.maxLabelsPerUser = 20000
			wrapper.vm.saveSettings()

			await wrapper.vm.$nextTick()

			expect(wrapper.vm.saving).toBe(true)
			expect(wrapper.text()).toContain('Saving...')
		})

		it('shows saved status on success', async () => {
			axios.put.mockResolvedValue({ data: { maxLabelsPerUser: 20000 } })

			wrapper = shallowMount(AdminSettings, { localVue })
			await wrapper.vm.saveSettings()
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.saved).toBe(true)
			expect(wrapper.text()).toContain('Saved')
		})

		it('shows error message on failure', async () => {
			axios.put.mockRejectedValue({
				response: { data: { error: 'Value must be a number' } },
			})

			wrapper = shallowMount(AdminSettings, { localVue })
			await wrapper.vm.saveSettings()
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.error).toBe('Value must be a number')
		})
	})

	describe('Input Validation', () => {
		it('has min value of 100', () => {
			wrapper = shallowMount(AdminSettings, { localVue })

			const input = wrapper.find('#max-labels-per-user')
			expect(input.attributes('min')).toBe('100')
		})

		it('has max value of 1000000', () => {
			wrapper = shallowMount(AdminSettings, { localVue })

			const input = wrapper.find('#max-labels-per-user')
			expect(input.attributes('max')).toBe('1000000')
		})

		it('disables input while saving', async () => {
			axios.put.mockImplementation(() => new Promise(() => {})) // Never resolves

			wrapper = shallowMount(AdminSettings, { localVue })
			wrapper.vm.saveSettings()
			await wrapper.vm.$nextTick()

			const input = wrapper.find('#max-labels-per-user')
			expect(input.attributes('disabled')).toBeDefined()
		})
	})
})
