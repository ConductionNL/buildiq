/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SettingsPageEditor (task 4.5 — `type: "settings"`).
 *
 * Covers:
 *  - `saveEndpoint` propagates and clears on empty.
 *  - sections/tabs XOR: switching modes drops the inactive key (and
 *    seeds an empty array for the active one).
 *  - SettingsSectionBuilder forwards through update:config (flat mode).
 *  - Tab add / field-edit / remove flows in tabbed mode.
 *  - Lossless round-trip of an unsurfaced config key.
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('../../../src/components/page-editor/fields/SettingsSectionBuilder.vue', () => ({
	default: {
		name: 'SettingsSectionBuilder',
		props: ['modelValue'],
		render(h) { return h('div', { staticClass: 'settings-section-builder-stub' }) },
	},
}))

const SettingsPageEditor = (await import('../../../src/components/page-editor/SettingsPageEditor.vue')).default

function mountEditor(config = {}) {
	return mount(SettingsPageEditor, { propsData: { config } })
}

describe('SettingsPageEditor', () => {
	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Settings page')
	})

	it('saveEndpoint propagates and clears on empty', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('saveEndpoint', '/api/x/settings')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].saveEndpoint).toBe('/api/x/settings')
		wrapper.vm.update('saveEndpoint', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[1][0]).not.toHaveProperty('saveEndpoint')
	})

	it('defaults to the flat-sections layout', () => {
		expect(mountEditor({}).vm.layoutShape).toBe('sections')
		expect(mountEditor({ sections: [] }).vm.layoutShape).toBe('sections')
	})

	it('a config with only `tabs` reports the tabbed layout', () => {
		expect(mountEditor({ tabs: [] }).vm.layoutShape).toBe('tabs')
	})

	it('switching to tabbed mode drops sections and seeds tabs:[]', async () => {
		const wrapper = mountEditor({ sections: [{ title: 't', fields: [] }] })
		wrapper.vm.setLayoutShape('tabs')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('sections')
		expect(next.tabs).toEqual([])
	})

	it('switching to flat mode drops tabs and seeds sections:[]', async () => {
		const wrapper = mountEditor({ tabs: [{ id: 'a', label: 'A', sections: [] }] })
		wrapper.vm.setLayoutShape('sections')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('tabs')
		expect(next.sections).toEqual([])
	})

	it('SettingsSectionBuilder forwards through update:config in flat mode', async () => {
		const wrapper = mountEditor({ sections: [] })
		wrapper.findComponent({ name: 'SettingsSectionBuilder' }).vm
			.$emit('update:modelValue', [{ title: 'General', fields: [{ key: 'name', label: 'Name', type: 'string' }] }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.sections).toHaveLength(1)
		expect(next.sections[0].title).toBe('General')
	})

	it('addTab appends a tab and drops sections', async () => {
		const wrapper = mountEditor({ tabs: [] })
		wrapper.vm.addTab()
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.tabs).toHaveLength(1)
		expect(next.tabs[0]).toHaveProperty('sections')
		expect(next).not.toHaveProperty('sections')
	})

	it('updateTabField edits a tab in place', async () => {
		const wrapper = mountEditor({ tabs: [{ id: '', label: '', sections: [] }] })
		wrapper.vm.updateTabField(0, 'label', 'settings.tab.general')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.tabs[0].label).toBe('settings.tab.general')
	})

	it('removeTab drops a tab', async () => {
		const wrapper = mountEditor({ tabs: [{ id: 'a' }, { id: 'b' }] })
		wrapper.vm.removeTab(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.tabs).toHaveLength(1)
		expect(next.tabs[0].id).toBe('b')
	})

	it('preserves unsurfaced config keys on update (lossless round-trip)', async () => {
		const wrapper = mountEditor({ sections: [], unknownThing: 42 })
		wrapper.vm.update('saveEndpoint', '/x')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].unknownThing).toBe(42)
	})
})
