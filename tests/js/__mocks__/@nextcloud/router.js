/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export const generateUrl = jest.fn((url, params) => {
	if (params) {
		return Object.entries(params).reduce(
			(acc, [key, value]) => acc.replace(`{${key}}`, value),
			`/index.php${url}`
		)
	}
	return `/index.php${url}`
})

export const generateOcsUrl = jest.fn((url, params) => {
	let result = `/ocs/v2.php/${url}`
	if (params) {
		result = Object.entries(params).reduce(
			(acc, [key, value]) => acc.replace(`{${key}}`, value),
			result
		)
	}
	return result
})
