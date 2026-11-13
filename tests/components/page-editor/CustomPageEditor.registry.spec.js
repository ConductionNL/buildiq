/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for CustomPageEditor's component-registry suggestions, its
 * rendered JSON textarea, and the re-seed watcher.
 *
 * Two things here are not reachable from `wrapper.vm`. The datalist of
 * registry keys only exists when the live preview exposes a registry, which
 * arrives through `useLivePreview()` — so it is mocked here, in both shapes
 * the computed accepts (an array and a plain object keyed by name). And the
 * `config.props` watcher only runs on a real prop change, which is what
 * happens on a page switch: it must re-seed the textarea from outside WITHOUT
 * clobbering half-typed JSON the author is still editing.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

let registry = null
vi.mock('../../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({ componentRegistry: registry }),
}))

const CustomPageEditor = (
	await import('../../../src/components/page-editor/CustomPageEditor.vue')
).default

/**
 * Mount the editor.
 *
 * @param {object} config The page's config block.
 * @return {object} The mounted wrapper.
 */
function mountEditor(config = {}) {
	return mount(CustomPageEditor, {
		propsData: { config, pageType: 'custom' },
		stubs: {
			InlineFieldMark: { template: '<span class="inline-field-stub" />' },
		},
	})
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

describe('CustomPageEditor registry suggestions', () => {
	it('offers no suggestions and shows the free-form hint without a registry', () => {
		registry = null
		const wrapper = mountEditor({})

		expect(wrapper.vm.registryKeys).toEqual([])
		expect(wrapper.findAll('datalist option')).toHaveLength(0)
		expect(wrapper.text()).toContain('customComponents map')
	})

	it('offers an array registry as the suggestion list', () => {
		registry = ['LaunchPadboard', 'TicketWall']
		const wrapper = mountEditor({})

		expect(wrapper.vm.registryKeys).toEqual(['LaunchPadboard', 'TicketWall'])
		const values = wrapper
			.findAll('datalist option')
			.map((option) => option.attributes('value'))
		expect(values).toEqual(['LaunchPadboard', 'TicketWall'])
	})

	it('offers the KEYS of an object registry, not its components', () => {
		registry = { LaunchPadboard: {}, TicketWall: {} }
		const wrapper = mountEditor({})

		expect(wrapper.vm.registryKeys).toEqual(['LaunchPadboard', 'TicketWall'])
		expect(wrapper.findAll('datalist option')).toHaveLength(2)
	})

	it('drops the free-form hint once suggestions exist', () => {
		registry = ['LaunchPadboard']

		expect(mountEditor({}).text()).not.toContain('customComponents map')
	})
})

describe('CustomPageEditor rendered fields', () => {
	it('the component input writes config.component', async () => {
		registry = null
		const wrapper = mountEditor({})
		const input = wrapper.find('input[type="text"]')

		input.element.value = 'LaunchPadboard'
		await input.trigger('input')

		expect(lastConfig(wrapper).component).toBe('LaunchPadboard')
	})

	it('typing valid JSON in the textarea writes parsed props', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X' })

		const textarea = wrapper.find('textarea')
		textarea.element.value = '{"a": 1}'
		await textarea.trigger('input')

		expect(lastConfig(wrapper).props).toEqual({ a: 1 })
		expect(wrapper.vm.propsError).toBe('')
	})

	it('typing invalid JSON reports an error and writes nothing', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X' })

		const textarea = wrapper.find('textarea')
		textarea.element.value = '{"a": '
		await textarea.trigger('input')

		expect(wrapper.vm.propsError).not.toBe('')
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})
})

describe('CustomPageEditor props re-seed watcher', () => {
	it('re-seeds the textarea when config.props changes from outside', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X', props: { a: 1 } })
		expect(wrapper.vm.propsDraft).toBe(JSON.stringify({ a: 1 }, null, 2))

		await wrapper.setProps({ config: { component: 'X', props: { b: 2 } } })

		expect(wrapper.vm.propsDraft).toBe(JSON.stringify({ b: 2 }, null, 2))
		expect(wrapper.find('textarea').element.value).toBe(
			JSON.stringify({ b: 2 }, null, 2),
		)
	})

	it('clears the error when the outside value replaces a bad draft', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X', props: { a: 1 } })

		const textarea = wrapper.find('textarea')
		textarea.element.value = 'not json'
		await textarea.trigger('input')
		expect(wrapper.vm.propsError).not.toBe('')

		await wrapper.setProps({ config: { component: 'X', props: { b: 2 } } })

		expect(wrapper.vm.propsError).toBe('')
	})

	it('empties the textarea when props is removed entirely', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X', props: { a: 1 } })

		await wrapper.setProps({ config: { component: 'X' } })

		expect(wrapper.vm.propsDraft).toBe('')
	})

	it('a value that stringifies the same leaves the draft alone', async () => {
		registry = null
		const wrapper = mountEditor({ component: 'X', props: { a: 1 } })
		const before = wrapper.vm.propsDraft

		// A different object that serialises identically — the author's caret
		// and half-typed text must not be reset by a no-op manifest update.
		await wrapper.setProps({ config: { component: 'Y', props: { a: 1 } } })

		expect(wrapper.vm.propsDraft).toBe(before)
	})

	it('stringifyProps yields an empty textarea for a value JSON cannot render', () => {
		registry = null
		const wrapper = mountEditor({})

		const cyclic = {}
		cyclic.self = cyclic

		expect(wrapper.vm.stringifyProps(cyclic)).toBe('')
		expect(wrapper.vm.stringifyProps(null)).toBe('')
		expect(wrapper.vm.stringifyProps(undefined)).toBe('')
	})
})
