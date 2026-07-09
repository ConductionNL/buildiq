/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for IndexPageEditor (REQ-OBPD-004).
 *
 * Covers:
 *  - Register picker calls useRegisterPicker.fetchRegisters on mount.
 *  - Schema picker is disabled until a register is selected.
 *  - Picking a register clears the previously-selected schema.
 *  - ColumnBuilder add/remove/reorder forwards via update:config.
 *  - ActionBuilder add forwards via update:config.
 *  - Sidebar toggle adds/removes the `sidebar` config key.
 *
 * The `useRegisterPicker` composable is mocked at the module boundary so
 * the spec doesn't hit fetch().
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const fetchRegisters = vi.fn(async () => [
	{ slug: 'openbuild-hello-world', title: 'Hello World' },
	{ slug: 'openbuild', title: 'OpenBuild apps' },
])
const fetchSchemas = vi.fn(async () => [
	{ slug: 'page', title: 'Page' },
	{ slug: 'application', title: 'Application' },
])
const fetchSchemaProperties = vi.fn(async () => ({
	title: { type: 'string' },
	route: { type: 'string' },
}))
// Spies on the composable factory call itself — asserts the `dataRegisters`
// prop is forwarded into useRegisterPicker's opts (data-registers-runtime
// task 2.4), separate from the returned fetch-fn spies above.
const useRegisterPickerSpy = vi.fn()

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: (opts) => {
		useRegisterPickerSpy(opts)
		return {
			fetchRegisters,
			fetchSchemas,
			fetchSchemaProperties,
			resolveAppRegister: () => 'openbuild-hello-world',
		}
	},
}))

// Stub the field builders — they have their own specs; here we only
// verify that update:config propagates through them.
vi.mock('../../../src/components/page-editor/fields/ColumnBuilder.vue', () => ({
	default: {
		name: 'ColumnBuilder',
		props: ['modelValue', 'schemaProperties'],
		render(h) { return h('div', { staticClass: 'column-builder-stub' }) },
	},
}))
vi.mock('../../../src/components/page-editor/fields/ActionBuilder.vue', () => ({
	default: {
		name: 'ActionBuilder',
		props: ['modelValue'],
		render(h) { return h('div', { staticClass: 'action-builder-stub' }) },
	},
}))
vi.mock('../../../src/components/page-editor/fields/SidebarSectionBuilder.vue', () => ({
	default: {
		name: 'SidebarSectionBuilder',
		props: ['modelValue'],
		render(h) { return h('div', { staticClass: 'sidebar-section-builder-stub' }) },
	},
}))

const IndexPageEditor = (await import('../../../src/components/page-editor/IndexPageEditor.vue')).default

function mountEditor(config = {}, appSlug = 'hello-world', dataRegisters) {
	const propsData = { config, appSlug }
	if (dataRegisters !== undefined) {
		propsData.dataRegisters = dataRegisters
	}
	return mount(IndexPageEditor, { propsData })
}

describe('IndexPageEditor', () => {
	beforeEach(() => {
		fetchRegisters.mockClear()
		fetchSchemas.mockClear()
		fetchSchemaProperties.mockClear()
		useRegisterPickerSpy.mockClear()
	})

	it('renders the editor title', () => {
		const wrapper = mountEditor()
		expect(wrapper.text()).toContain('Index page')
	})

	it('calls fetchRegisters on mount', async () => {
		mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchRegisters).toHaveBeenCalled()
	})

	it('renders register options after fetch resolves', async () => {
		const wrapper = mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		await wrapper.vm.$nextTick()
		const options = wrapper.findAll('option')
		const slugs = options.wrappers.map((w) => w.element.value)
		expect(slugs).toContain('openbuild-hello-world')
		expect(slugs).toContain('openbuild')
	})

	it('schema picker is disabled until a register is selected', () => {
		const wrapper = mountEditor({})
		const selects = wrapper.findAll('select')
		// First select = register, second = schema. Schema should be disabled.
		expect(selects.at(1).element.disabled).toBe(true)
	})

	it('schema picker is enabled when a register is set', async () => {
		const wrapper = mountEditor({ register: 'openbuild-hello-world' })
		await wrapper.vm.$nextTick()
		const selects = wrapper.findAll('select')
		expect(selects.at(1).element.disabled).toBe(false)
	})

	it('picking a register clears the previously-set schema', async () => {
		const wrapper = mountEditor({ register: 'openbuild-hello-world', schema: 'page' })
		wrapper.vm.update('register', 'openbuild')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')
		expect(emitted).toBeTruthy()
		const next = emitted[0][0]
		expect(next.register).toBe('openbuild')
		expect(next).not.toHaveProperty('schema')
	})

	it('column add via ColumnBuilder forwards through update:config', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's', columns: [] })
		const cb = wrapper.findComponent({ name: 'ColumnBuilder' })
		cb.vm.$emit('update:modelValue', [{ key: 'title' }, { key: 'route' }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.columns).toHaveLength(2)
		expect(next.columns[0].key).toBe('title')
	})

	it('clearing columns to [] deletes the key from config', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's', columns: [{ key: 'foo' }] })
		const cb = wrapper.findComponent({ name: 'ColumnBuilder' })
		cb.vm.$emit('update:modelValue', [])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('columns')
	})

	it('action add via ActionBuilder forwards through update:config', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's', actions: [] })
		const ab = wrapper.findComponent({ name: 'ActionBuilder' })
		ab.vm.$emit('update:modelValue', [{ id: 'edit', label: 'Edit' }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.actions).toHaveLength(1)
		expect(next.actions[0].id).toBe('edit')
	})

	it('sidebar toggle adds the `sidebar` key with enabled:true', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.onSidebarToggle(true)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.sidebar).toEqual({ enabled: true })
	})

	it('sidebar untoggle deletes the `sidebar` key', async () => {
		const wrapper = mountEditor({
			register: 'r',
			schema: 's',
			sidebar: { enabled: true, columnGroups: [] },
		})
		wrapper.vm.onSidebarToggle(false)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('sidebar')
	})

	it('updates `cardComponent` via the optional input', async () => {
		const wrapper = mountEditor({ register: 'r' })
		wrapper.vm.update('cardComponent', 'OpenBuildDefaultCard')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.cardComponent).toBe('OpenBuildDefaultCard')
	})

	// data-registers-runtime task 2.4/2.1: dataRegisters prop pass-through.
	it('forwards the dataRegisters prop into useRegisterPicker', () => {
		const dataRegisters = [{ register: 'spectr', label: 'Spectr market intelligence data' }]
		mountEditor({}, 'hello-world', dataRegisters)
		expect(useRegisterPickerSpy).toHaveBeenCalledWith({ appSlug: 'hello-world', dataRegisters })
	})

	it('defaults dataRegisters to [] when the prop is not passed', () => {
		mountEditor({}, 'hello-world')
		expect(useRegisterPickerSpy).toHaveBeenCalledWith({ appSlug: 'hello-world', dataRegisters: [] })
	})
})
