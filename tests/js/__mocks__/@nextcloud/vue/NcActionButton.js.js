/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export default {
	name: 'NcActionButton',
	template: '<button @click="$emit(\'click\')"><slot /></button>',
	props: ['icon', 'ariaLabel'],
}
