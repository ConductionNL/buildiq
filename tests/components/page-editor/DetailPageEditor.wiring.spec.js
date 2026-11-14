/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the TEMPLATE WIRING of DetailPageEditor's sidebar controls.
 *
 * DetailPageEditor.spec.js drives the sidebar through `wrapper.vm`
 * (`setSidebarShape`, `updateSidebarKey`), so the methods are specified but
 * the markup meant to call them is not. The sidebar is the place that matters
 * most here, because `config.sidebar` carries THREE different shapes —
 * absent, a bare boolean (legacy) and an object — and the radio that switches
 * between them, the checkboxes inside each shape, and the two different tab
 * paths (`sidebar.tabs` vs `sidebarProps.tabs`) are all distinct bindings that
 * look alike. A control wired to the neighbouring one would keep every
 * existing test green.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([]),
	}),
}))

const DetailPageEditor = (
	await import('../../../src/components/page-editor/DetailPageEditor.vue')
).default

/**
 * Mount the editor with its field builders stubbed.
 *
 * @param {object} config The page's config block.
 * @return {object} The mounted wrapper.
 */
function mountEditor(config = {}) {
	return mount(DetailPageEditor, {
		propsData: {
			config,
			parentRoute: '/messages/:id',
			appSlug: 'hello-world',
			pageType: 'detail',
		},
		stubs: {
			InlineFieldMark: { template: '<span class="inline-field-stub" />' },
			AppliesToPanel: {
				name: 'AppliesToPanel',
				props: ['modelValue', 'register', 'schema', 'targetApp'],
				template: '<div class="applies-to-stub" />',
			},
			ScreenOverrideList: { template: '<div class="override-list-stub" />' },
			RegistrationFormList: { template: '<div class="reg-form-stub" />' },
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

/**
 * The control inside the `<label>` whose text contains `text`.
 *
 * @param {object} wrapper The mounted editor.
 * @param {string} text A distinctive part of the label's text.
 * @return {object} The control wrapper.
 */
function field(wrapper, text) {
	const matching = wrapper
		.findAll('label')
		.filter((candidate) => candidate.text().includes(text))
	if (matching.length === 0) {
		throw new Error(`no label containing "${text}"`)
	}
	return matching[0].find('input')
}

/**
 * Fire a change on a radio or checkbox after setting its checked state.
 *
 * @param {object} control The control wrapper.
 * @param {boolean} checked The checked state to set.
 * @return {Promise<void>}
 */
async function toggle(control, checked = true) {
	control.element.checked = checked
	await control.trigger('change')
}

/**
 * The payload of the editor's last `update:config` emission.
 *
 * @param {object} wrapper The mounted editor.
 * @return {object} The emitted config.
 */
function lastConfig(wrapper) {
	const events = wrapper.emitted('update:config')
	expect(events, 'the editor emitted no update:config').toBeTruthy()
	return events[events.length - 1][0]
}

describe('DetailPageEditor sidebar shape radios', () => {
	it('the object radio writes the object shape', async () => {
		const wrapper = mountEditor({ sidebar: true })

		await toggle(field(wrapper, 'Object form (preferred)'))

		expect(lastConfig(wrapper).sidebar).toEqual({ enabled: true })
	})

	it('the boolean radio writes the bare legacy boolean', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })

		await toggle(field(wrapper, 'Boolean form (legacy)'))

		expect(lastConfig(wrapper).sidebar).toBe(true)
	})

	it('the not-set radio removes the key entirely', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })

		await toggle(field(wrapper, 'Not set'))

		expect(lastConfig(wrapper)).not.toHaveProperty('sidebar')
	})

	it('the shape the config is in is the one shown as checked', () => {
		expect(
			field(mountEditor({}), 'Not set').element.checked,
		).toBe(true)
		expect(
			field(mountEditor({ sidebar: true }), 'Boolean form (legacy)').element
				.checked,
		).toBe(true)
		expect(
			field(mountEditor({ sidebar: { enabled: true } }), 'Object form (preferred)')
				.element.checked,
		).toBe(true)
	})
})

describe('DetailPageEditor sidebar shape bodies', () => {
	it('the legacy shape shows one checkbox that writes the bare boolean', async () => {
		const wrapper = mountEditor({ sidebar: true })

		const box = field(wrapper, 'Sidebar enabled')
		await toggle(box, false)

		expect(lastConfig(wrapper).sidebar).toBe(false)
	})

	it('the object shape writes enabled and show as separate keys', async () => {
		const enabled = mountEditor({ sidebar: { enabled: true, show: true } })
		await toggle(field(enabled, 'Enabled'), false)
		expect(lastConfig(enabled).sidebar).toEqual({ enabled: false, show: true })

		const shown = mountEditor({ sidebar: { enabled: true, show: true } })
		await toggle(field(shown, 'Show'), false)
		expect(lastConfig(shown).sidebar).toEqual({ enabled: true, show: false })
	})

	it('the object checkboxes default to checked when the key is absent', () => {
		const wrapper = mountEditor({ sidebar: {} })

		expect(field(wrapper, 'Enabled').element.checked).toBe(true)
		expect(field(wrapper, 'Show').element.checked).toBe(true)
	})

	it('the legacy checkbox is not rendered for the object shape', () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })

		expect(
			wrapper
				.findAll('label')
				.some((l) => l.text().includes('Sidebar enabled')),
		).toBe(false)
	})
})

describe('DetailPageEditor tab builders write to their own paths', () => {
	it('the sidebar builder writes sidebar.tabs', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })
		const tabs = [{ id: 't1' }]

		const builders = wrapper.findAllComponents({ name: 'SidebarTabBuilder' })
		expect(builders.length).toBeGreaterThan(1)
		builders[0].vm.$emit('update:modelValue', tabs)
		await wrapper.vm.$nextTick()

		const config = lastConfig(wrapper)
		expect(config.sidebar.tabs).toEqual(tabs)
		expect(config).not.toHaveProperty('sidebarProps')
	})

	it('the alternate builder writes sidebarProps.tabs, not sidebar.tabs', async () => {
		const wrapper = mountEditor({ sidebar: { enabled: true } })
		const tabs = [{ id: 'alt' }]

		const builders = wrapper.findAllComponents({ name: 'SidebarTabBuilder' })
		builders[builders.length - 1].vm.$emit('update:modelValue', tabs)
		await wrapper.vm.$nextTick()

		const config = lastConfig(wrapper)
		expect(config.sidebarProps).toEqual({ tabs })
		expect(config.sidebar).not.toHaveProperty('tabs')
	})

	it('clearing the alternate tabs drops sidebarProps when nothing else is in it', async () => {
		const wrapper = mountEditor({
			sidebar: { enabled: true },
			sidebarProps: { tabs: [{ id: 'alt' }] },
		})

		const builders = wrapper.findAllComponents({ name: 'SidebarTabBuilder' })
		builders[builders.length - 1].vm.$emit('update:modelValue', [])
		await wrapper.vm.$nextTick()

		expect(lastConfig(wrapper)).not.toHaveProperty('sidebarProps')
	})

	it('clearing the alternate tabs keeps sidebarProps when it carries other keys', async () => {
		const wrapper = mountEditor({
			sidebar: { enabled: true },
			sidebarProps: { tabs: [{ id: 'alt' }], width: 320 },
		})

		const builders = wrapper.findAllComponents({ name: 'SidebarTabBuilder' })
		builders[builders.length - 1].vm.$emit('update:modelValue', [])
		await wrapper.vm.$nextTick()

		expect(lastConfig(wrapper).sidebarProps).toEqual({ width: 320 })
	})
})

describe('DetailPageEditor pageLayout panel', () => {
	it('the applies-to panel writes config.pageLayout', async () => {
		const wrapper = mountEditor({})
		const layout = { scope: 'app' }

		wrapper
			.findComponent({ name: 'AppliesToPanel' })
			.vm.$emit('update:modelValue', layout)
		await wrapper.vm.$nextTick()

		expect(lastConfig(wrapper).pageLayout).toEqual(layout)
	})
})
