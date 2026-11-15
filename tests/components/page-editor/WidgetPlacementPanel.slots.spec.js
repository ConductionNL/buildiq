/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning WidgetPlacementPanel's `slotOptions` (the slots an
 * author may choose) and `dataContext` (the object context handed to the
 * data sub-form) across every page-config shape.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WidgetPlacementPanel from '../../../src/components/page-editor/WidgetPlacementPanel.vue'

const LITERALS = ['body', 'sidebar', 'header-actions', 'footer', 'modal']

/**
 * The panel's view model on a page.
 *
 * @param {object} page - the page under edit.
 * @return {object} the component instance.
 */
function vmOn(page) {
	return mount(WidgetPlacementPanel, { props: { page } }).vm
}

describe('WidgetPlacementPanel slotOptions', () => {
	it('offers only the literals on a page without config or placements', () => {
		expect(vmOn({ type: 'dashboard' }).slotOptions).toEqual(LITERALS)
		expect(vmOn({ type: 'dashboard', config: 'nope' }).slotOptions).toEqual(LITERALS)
		expect(vmOn({ type: 'dashboard', config: { tabs: 'x', sections: {} } }).slotOptions).toEqual(LITERALS)
	})

	it('adds declared tabs, then sections, then slots in use, once each and only when valid', () => {
		const page = {
			type: 'detail',
			config: {
				tabs: [{ id: 'info' }, null, { id: '' }, {}, { id: 'info' }],
				sections: [{ id: 'main' }, { label: 'no id' }],
			},
			widgets: [
				{ id: 'w1', type: 'x', slot: 'tab:extra' },
				null,
				{ id: 'w2', type: 'x', slot: 'section:main' },
				{ id: 'w3', type: 'x', slot: 'nowhere' },
				{ id: 'w4', type: 'x', slot: 'body' },
				{ id: 'w5', type: 'x' },
			],
		}
		expect(vmOn(page).slotOptions).toEqual([
			...LITERALS,
			'tab:info',
			'section:main',
			'tab:extra',
		])
	})
})

describe('WidgetPlacementPanel dataContext', () => {
	it.each([
		[{ type: 'dashboard' }, null],
		[{ type: 'detail', config: null }, null],
		[{ type: 'detail', config: 'x' }, null],
		[{ type: 'detail', config: { register: 'r' } }, null],
		[{ type: 'detail', config: { schema: 's' } }, null],
		[{ type: 'detail', config: { register: '', schema: 's' } }, null],
		[{ type: 'detail', config: { register: 'r', schema: 's', other: 1 } }, { register: 'r', schema: 's' }],
	])('on %j is %j', (page, expected) => {
		expect(vmOn(page).dataContext).toEqual(expected)
	})
})
