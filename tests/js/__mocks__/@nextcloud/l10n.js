/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export const translate = jest.fn((app, text, vars) => {
	if (vars) {
		return text.replace(/\{(\w+)\}/g, (match, key) => vars[key] || match)
	}
	return text
})

export const translatePlural = jest.fn((app, singular, plural, count) => {
	return count === 1 ? singular : plural
})
