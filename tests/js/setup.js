/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Global mocks for Nextcloud environment
global.t = (app, text, vars) => {
	if (vars) {
		return text.replace(/\{(\w+)\}/g, (match, key) => vars[key] || match)
	}
	return text
}

global.n = (app, singular, plural, count) => {
	return count === 1 ? singular : plural
}

// Mock OC global
global.OC = {
	generateUrl: (url) => `/index.php${url}`,
	imagePath: (app, image) => `/apps/${app}/img/${image}`,
}
