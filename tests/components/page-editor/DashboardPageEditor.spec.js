/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DashboardPageEditor (openbuilt#9 task 7.1).
 *
 * Asserts the simple `update(key, value)` round-trip:
 *  - assigning a non-empty array emits `update:config` with the value set.
 *  - assigning an empty array or falsy value DELETES the key (REQ-OBPD-005
 *    parity with the other sub-editors).
 *  - the validated key-set is exactly `['widgets', 'layout']` (the contract
 *    that `pageEditorValidationMixin` reads for inline-mark routing).
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DashboardPageEditor from '../../../src/components/page-editor/DashboardPageEditor.vue'

function mountEditor(config = {}) {
	// Stub the heavy widget/layout builders + the inline mark — DashboardPageEditor
	// only owns the `update()` round-trip; the children are tested separately.
	return mount(DashboardPageEditor, {
		propsData: { config, pageType: 'dashboard', appSlug: 'hello-world', parentRoute: '/' },
		stubs: {
			WidgetBuilder: { template: '<div class="widget-builder-stub" />' },
			LayoutItemBuilder: { template: '<div class="layout-builder-stub" />' },
			InlineFieldMark: { template: '<span class="inline-field-stub" />' },
		},
		mocks: {
			$validator: {
				register: vi.fn(),
				unregister: vi.fn(),
				errorsForPathPrefix: () => ({ errors: [], warnings: [] }),
				summaryForPathPrefix: () => null,
			},
		},
	})
}

describe('DashboardPageEditor', () => {
	it('validatedConfigKeys is exactly [widgets, layout]', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual(['widgets', 'layout'])
	})

	it('update(widgets, [...]) emits update:config with widgets set', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('widgets', [{ id: 'w1' }])
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0]).toEqual({ widgets: [{ id: 'w1' }] })
	})

	it('update(layout, []) deletes the key (empty-array contract)', async () => {
		const wrapper = mountEditor({ layout: [{ id: 'l1', gridX: 0, gridY: 0 }] })
		wrapper.vm.update('layout', [])
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')[0][0]
		expect(emitted).not.toHaveProperty('layout')
	})

	it('update(widgets, null) deletes the key (falsy contract)', async () => {
		const wrapper = mountEditor({ widgets: [{ id: 'w1' }] })
		wrapper.vm.update('widgets', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0]).not.toHaveProperty('widgets')
	})

	it('successive updates preserve previously-set keys', async () => {
		const wrapper = mountEditor({ widgets: [{ id: 'w1' }] })
		wrapper.vm.update('layout', [{ id: 'l1', gridX: 0, gridY: 0 }])
		await wrapper.vm.$nextTick()
		const out = wrapper.emitted('update:config')[0][0]
		expect(out.widgets).toEqual([{ id: 'w1' }])
		expect(out.layout).toEqual([{ id: 'l1', gridX: 0, gridY: 0 }])
	})
})
