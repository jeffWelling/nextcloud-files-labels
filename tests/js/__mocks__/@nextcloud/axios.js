/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const axios = {
	get: jest.fn(() => Promise.resolve({ data: { ocs: { data: {} } } })),
	post: jest.fn(() => Promise.resolve({ data: { ocs: { data: {} } } })),
	put: jest.fn(() => Promise.resolve({ data: { ocs: { data: {} } } })),
	delete: jest.fn(() => Promise.resolve({ data: { ocs: { data: {} } } })),
	patch: jest.fn(() => Promise.resolve({ data: { ocs: { data: {} } } })),
}

export default axios
