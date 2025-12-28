/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

module.exports = {
	testEnvironment: 'jsdom',
	moduleFileExtensions: ['js', 'vue', 'json'],
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest',
		'^.+\\.js$': 'babel-jest',
	},
	moduleNameMapper: {
		// Mock Nextcloud packages
		'^@nextcloud/axios$': '<rootDir>/tests/js/__mocks__/@nextcloud/axios.js',
		'^@nextcloud/router$': '<rootDir>/tests/js/__mocks__/@nextcloud/router.js',
		'^@nextcloud/dialogs$': '<rootDir>/tests/js/__mocks__/@nextcloud/dialogs.js',
		'^@nextcloud/l10n$': '<rootDir>/tests/js/__mocks__/@nextcloud/l10n.js',
		'^@nextcloud/event-bus$': '<rootDir>/tests/js/__mocks__/@nextcloud/event-bus.js',
		'^@nextcloud/initial-state$': '<rootDir>/tests/js/__mocks__/@nextcloud/initial-state.js',
		// Mock Nextcloud Vue components
		'^@nextcloud/vue/dist/Components/(.*)$': '<rootDir>/tests/js/__mocks__/@nextcloud/vue/$1.js',
		// Mock vue-material-design-icons
		'^vue-material-design-icons/(.*)$': '<rootDir>/tests/js/__mocks__/vue-material-design-icons.js',
	},
	testMatch: ['**/tests/js/**/*.spec.js'],
	setupFilesAfterEnv: ['<rootDir>/tests/js/setup.js'],
	collectCoverageFrom: [
		'src/**/*.{js,vue}',
		'!src/main.js',
		'!src/admin.js',
	],
	coverageReporters: ['text', 'lcov', 'html'],
}
