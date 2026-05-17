/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DetailPageEditor (openbuilt#9 task 7.1).
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

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([]),
	}),
}))

import DetailPageEditor from '../../../src/components/page-editor/DetailPageEditor.vue'

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
			SidebarTabBuilder: { template: '<div class="sidebar-tab-builder-stub" />' },
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
		expect(mountEditor().vm.validatedConfigKeys).toEqual(['register', 'schema', 'sidebar', 'sidebarProps'])
	})

	it('routeParams extracts every :name placeholder from parentRoute', () => {
		const wrapper = mountEditor({}, { parentRoute: '/messages/:id/comments/:commentId' })
		expect(wrapper.vm.routeParams).toEqual(['id', 'commentId'])
		expect(wrapper.vm.routeHasParam).toBe(true)
	})

	it('routeHasParam is false when parentRoute carries no parameters', () => {
		const wrapper = mountEditor({}, { parentRoute: '/messages' })
		expect(wrapper.vm.routeHasParam).toBe(false)
	})

	it('update(register, X) emits and clears any stale schema selection', async () => {
		const wrapper = mountEditor({ register: 'oldreg', schema: 'oldsch' })
		wrapper.vm.update('register', 'openbuilt')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')
		expect(emitted).toBeTruthy()
		const next = emitted[emitted.length - 1][0]
		expect(next.register).toBe('openbuilt')
		expect(next).not.toHaveProperty('schema')
	})

	it('update(schema, X) emits with schema set', async () => {
		const wrapper = mountEditor({ register: 'openbuilt' })
		wrapper.vm.update('schema', 'hello-message')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.schema).toBe('hello-message')
	})

	it('update(K, "") and update(K, null) delete the key', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.update('schema', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty('schema')

		wrapper.vm.update('register', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty('register')
	})

	it('setSidebarShape cycles none → boolean → object → none', async () => {
		const wrapper = mountEditor({})

		wrapper.vm.setSidebarShape('boolean')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0].sidebar).toBe(true)

		wrapper.vm.setSidebarShape('object')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0].sidebar).toEqual({ enabled: true })

		wrapper.vm.setSidebarShape('none')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config').slice(-1)[0][0]).not.toHaveProperty('sidebar')
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
		const wrapper = mountEditor({ sidebarProps: { tabs: [{ id: 't1' }], other: 'keep' } })
		wrapper.vm.updateSidebarPropsTabs([])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.sidebarProps).toEqual({ other: 'keep' })
	})

	it('sidebarShape reflects the current sidebar value', () => {
		expect(mountEditor({}).vm.sidebarShape).toBe('none')
		expect(mountEditor({ sidebar: true }).vm.sidebarShape).toBe('boolean')
		expect(mountEditor({ sidebar: { enabled: true } }).vm.sidebarShape).toBe('object')
	})
})
