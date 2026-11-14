/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DetailPageEditor (buildiq#9 task 7.1).
 *
 * Covers:
 *  - validatedConfigKeys is exactly [register, schema, sidebar, sidebarProps]
 *  - update(register, ...) emits with `register` set and clears any stale schema
 *  - update(schema, ...) emits with `schema` set
 *  - setSidebarShape('none' | 'boolean' | 'object') drives sidebar shape transitions
 *  - updateSidebarKey + updateSidebarPropsTabs preserve / clean their config slots
 *  - routeParams parses the parent route's `:name` markers
 *
 * The composable useRegisterPicker is stubbed so the test never hits OR.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([]),
	}),
}))

import DetailPageEditor from '../../../src/components/page-editor/DetailPageEditor.vue'
import SidebarTabBuilder from '../../../src/components/page-editor/fields/SidebarTabBuilder.vue'

function mountEditor(config = {}, propsOverrides = {}) {
	return mount(DetailPageEditor, {
		propsData: {
			config,
			parentRoute: '/messages/:id',
			appSlug: 'hello-world',
			pageType: 'detail',
			...propsOverrides,
		},
		stubs: {
			SidebarTabBuilder: {
				template: '<div class="sidebar-tab-builder-stub" />',
			},
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

describe('DetailPageEditor', () => {
	it('validatedConfigKeys is [register, schema, sidebar, sidebarProps]', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual([
			'register',
			'schema',
			'sidebar',
			'sidebarProps',
		])
	})

	it('routeParams extracts every :name placeholder from parentRoute', () => {
		const wrapper = mountEditor(
			{},
			{ parentRoute: '/messages/:id/comments/:commentId' },
		)
		expect(wrapper.vm.routeParams).toEqual(['id', 'commentId'])
		expect(wrapper.vm.routeHasParam).toBe(true)
	})

	it('routeHasParam is false when parentRoute carries no parameters', () => {
		const wrapper = mountEditor({}, { parentRoute: '/messages' })
		expect(wrapper.vm.routeHasParam).toBe(false)
	})

	it('update(register, X) emits and clears any stale schema selection', async () => {
		const wrapper = mountEditor({ register: 'oldreg', schema: 'oldsch' })
		wrapper.vm.update('register', 'buildiq')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')
		expect(emitted).toBeTruthy()
		const next = emitted[emitted.length - 1][0]
		expect(next.register).toBe('buildiq')
		expect(next).not.toHaveProperty('schema')
	})

	it('update(schema, X) emits with schema set', async () => {
		const wrapper = mountEditor({ register: 'buildiq' })
		wrapper.vm.update('schema', 'hello-message')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.schema).toBe('hello-message')
	})

	it('update(K, "") and update(K, null) delete the key', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.update('schema', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty(
			'schema',
		)

		wrapper.vm.update('register', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty(
			'register',
		)
	})

	it('setSidebarShape cycles none → boolean → object → none', async () => {
		const wrapper = mountEditor({})

		wrapper.vm.setSidebarShape('boolean')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0].sidebar).toBe(true)

		wrapper.vm.setSidebarShape('object')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0].sidebar).toEqual({
			enabled: true,
		})

		wrapper.vm.setSidebarShape('none')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty(
			'sidebar',
		)
	})

	it('updateSidebarKey preserves prior sidebar object fields', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true, mode: 'wide' } })
		wrapper.vm.updateSidebarKey('mode', 'narrow')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.sidebar).toEqual({ enabled: true, mode: 'narrow' })
	})

	it('updateSidebarPropsTabs([]) cleans up sidebarProps when no other keys remain', async () => {
		const wrapper = mountEditor({ sidebarProps: { tabs: [{ id: 't1' }] } })
		wrapper.vm.updateSidebarPropsTabs([])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next).not.toHaveProperty('sidebarProps')
	})

	it('updateSidebarPropsTabs([]) preserves sidebarProps when other keys remain', async () => {
		const wrapper = mountEditor({
			sidebarProps: { tabs: [{ id: 't1' }], other: 'keep' },
		})
		wrapper.vm.updateSidebarPropsTabs([])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.sidebarProps).toEqual({ other: 'keep' })
	})

	it('sidebarShape reflects the current sidebar value', () => {
		expect(mountEditor({}).vm.sidebarShape).toBe('none')
		expect(mountEditor({ sidebar: true }).vm.sidebarShape).toBe('boolean')
		expect(mountEditor({ sidebar: { enabled: true } }).vm.sidebarShape).toBe(
			'object',
		)
	})
})

describe('DetailPageEditor record history (data-restore-record-version)', () => {
	const lastConfig = (wrapper) => wrapper.emitted('update:config').slice(-1)[0][0]

	it('turning history on for a page whose sidebar was unset adds the history tab with restore off', async () => {
		const wrapper = mountEditor({})
		expect(wrapper.vm.showHistory).toBe(false)
		await wrapper.find('[data-testid="record-history-show"]').setChecked(true)
		expect(lastConfig(wrapper).sidebar).toEqual({
			enabled: true,
			tabs: [{ id: 'history', label: 'History', icon: 'History', widgets: [{ type: 'audit', props: { allowRestore: false } }] }],
		})
	})

	it('the restore switch is disabled while history is off', () => {
		const wrapper = mountEditor({})
		expect(wrapper.find('[data-testid="record-history-restore"]').attributes('disabled')).toBeDefined()
	})

	it('turning history on keeps the existing tabs', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true, tabs: [{ id: 'notes', label: 'Notes' }] } })
		wrapper.vm.setShowHistory(true)
		await wrapper.vm.$nextTick()
		const tabs = lastConfig(wrapper).sidebar.tabs
		expect(tabs.map((tab) => tab.id)).toEqual(['notes', 'history'])
	})

	it('turning history off removes only the history tab', async () => {
		const history = { id: 'history', label: 'History', widgets: [{ type: 'audit', props: { allowRestore: true } }] }
		const wrapper = mountEditor({ sidebar: { enabled: true, tabs: [{ id: 'notes' }, history] } })
		expect(wrapper.vm.showHistory).toBe(true)
		expect(wrapper.vm.allowRestore).toBe(true)
		await wrapper.find('[data-testid="record-history-show"]').setChecked(false)
		expect(lastConfig(wrapper).sidebar.tabs).toEqual([{ id: 'notes' }])
	})

	it('turning restore on sets allowRestore on the audit widget and keeps the rest of the tab', async () => {
		const history = { id: 'history', label: 'Geschiedenis', icon: 'History', widgets: [{ type: 'audit', props: { allowRestore: false, pageSize: 20 } }] }
		const wrapper = mountEditor({ sidebar: { enabled: true, show: false, tabs: [history] } })
		expect(wrapper.vm.allowRestore).toBe(false)
		await wrapper.find('[data-testid="record-history-restore"]').setChecked(true)
		const next = lastConfig(wrapper).sidebar
		expect(next.show).toBe(false)
		expect(next.tabs).toEqual([{ ...history, widgets: [{ type: 'audit', props: { allowRestore: true, pageSize: 20 } }] }])
	})

	it('turning restore on a history tab without an audit widget adds one', async () => {
		const wrapper = mountEditor({ sidebar: { tabs: [{ id: 'history', label: 'History' }] } })
		wrapper.vm.setAllowRestore(true)
		await wrapper.vm.$nextTick()
		expect(lastConfig(wrapper).sidebar.tabs[0].widgets).toEqual([{ type: 'audit', props: { allowRestore: true } }])
	})

	it('restore cannot be turned on without the history tab', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })
		wrapper.vm.setAllowRestore(true)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('editing another tab in the tab builder leaves the history tab and its allowRestore as they were', async () => {
		const history = { id: 'history', label: 'History', icon: 'History', widgets: [{ type: 'audit', props: { allowRestore: true } }] }
		const wrapper = mount(DetailPageEditor, {
			propsData: {
				config: { sidebar: { enabled: true, tabs: [{ id: 'notes', label: 'Notes' }, history] } },
				parentRoute: '/messages/:id',
				appSlug: 'hello-world',
				pageType: 'detail',
			},
			stubs: { InlineFieldMark: { template: '<span />' } },
			mocks: {
				$validator: {
					register: vi.fn(),
					unregister: vi.fn(),
					errorsForPathPrefix: () => ({ errors: [], warnings: [] }),
					summaryForPathPrefix: () => null,
				},
			},
		})
		const builder = wrapper.findAllComponents(SidebarTabBuilder).at(0)
		builder.vm.updateField(0, 'label', 'Comments')
		await wrapper.vm.$nextTick()
		const tabs = lastConfig(wrapper).sidebar.tabs
		expect(tabs[0]).toEqual({ id: 'notes', label: 'Comments' })
		expect(tabs[1]).toEqual(history)
		expect(tabs[1].widgets[0].props.allowRestore).toBe(true)
	})
})
