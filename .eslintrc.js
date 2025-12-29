// SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

module.exports = {
	root: true,
	env: {
		browser: true,
		es2021: true,
		node: true,
	},
	extends: [
		'eslint:recommended',
		'plugin:vue/recommended',
	],
	parserOptions: {
		ecmaVersion: 'latest',
		sourceType: 'module',
	},
	plugins: ['vue'],
	rules: {
		'vue/html-indent': ['error', 'tab'],
		'vue/max-attributes-per-line': 'off',
		'vue/singleline-html-element-content-newline': 'off',
		indent: ['error', 'tab'],
		semi: ['error', 'never'],
		quotes: ['error', 'single'],
		'comma-dangle': ['error', 'always-multiline'],
		'no-console': process.env.NODE_ENV === 'production' ? 'warn' : 'off',
		'no-debugger': process.env.NODE_ENV === 'production' ? 'error' : 'off',
	},
	globals: {
		OC: 'readonly',
		OCA: 'readonly',
		OCP: 'readonly',
		t: 'readonly',
		n: 'readonly',
	},
}
