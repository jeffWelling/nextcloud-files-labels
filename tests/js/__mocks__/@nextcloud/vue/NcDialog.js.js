/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export default {
	name: 'NcDialog',
	template: '<div class="nc-dialog"><slot /></div>',
	props: ['name', 'message', 'buttons'],
}
