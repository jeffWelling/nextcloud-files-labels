/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import LabelsSidebarTab from './views/LabelsSidebarTab.vue'

// Custom icon for Labels tab (bookmark-outline - distinct from tag icon)
const labelIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M17,3H7A2,2 0 0,0 5,5V21L12,18L19,21V5A2,2 0 0,0 17,3M17,18L12,15.82L7,18V5H17Z"/></svg>'

// Create the Vue component class
const LabelsSidebarTabView = Vue.extend(LabelsSidebarTab)

// Register the sidebar tab with the Files app
window.addEventListener('DOMContentLoaded', () => {
	if (window.OCA?.Files?.Sidebar) {
		window.OCA.Files.Sidebar.registerTab(new window.OCA.Files.Sidebar.Tab({
			id: 'files_labels',
			name: t('files_labels', 'Labels'),
			iconSvg: labelIconSvg,

			async mount(el, fileInfo, context) {
				if (this.component) {
					this.component.$destroy()
				}
				this.component = new LabelsSidebarTabView({
					propsData: {
						fileInfo,
					},
				})
				this.component.$mount(el)
			},
			update(fileInfo) {
				if (this.component) {
					this.component.fileInfo = fileInfo
				}
			},
			destroy() {
				if (this.component) {
					this.component.$destroy()
					this.component = null
				}
			},
			enabled(fileInfo) {
				// Only show for files, not folders
				return fileInfo && fileInfo.type === 'file'
			},
		}))
	}
})
