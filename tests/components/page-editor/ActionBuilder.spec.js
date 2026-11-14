/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the typed ActionBuilder (pages-action-buttons T01, T02, T04,
 * T05): one case per action type, each saved action validating against the
 * manifest schema's `$defs/action`; old `target` actions shown typed; a
 * "change fields" action refused on the lifecycle field; a `visibleWhen`
 * condition written on the action. Every edit is emitted as a new array.
 *
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md
 */

import schema from '@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { mount } from '@vue/test-utils'
import Ajv2020 from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'
import ActionBuilder from '../../../src/components/page-editor/fields/ActionBuilder.vue'

const ajv = new Ajv2020({ strict: false, allErrors: true })
ajv.addSchema(schema, 'manifest')
const validAction = ajv.getSchema('manifest#/$defs/action')

const pages = [{ id: 'requests', title: 'Requests' }, { id: 'request-detail' }]
const flows = [{ flow: 'flow-intake', label: 'Intake check' }]

function lastEmit(wrapper) {
	const emits = wrapper.emitted('update:modelValue')
	return emits[emits.length - 1][0]
}

function mountWith(modelValue, extra = {}) {
	return mount(ActionBuilder, { props: { modelValue, pages, flows, ...extra } })
}

/**
 * Pick a type on the first action and re-render with the emitted list.
 */
async function typed(type, base = { id: 'act', label: 'Act' }) {
	const wrapper = mountWith([base])
	await wrapper.find('.action-builder__type').setValue(type)
	await wrapper.setProps({ modelValue: lastEmit(wrapper) })
	return wrapper
}

describe('ActionBuilder', () => {
	it('offers the six typed behaviours and only them', () => {
		const wrapper = mountWith([{ id: 'a', label: 'A' }])
		const values = wrapper.find('.action-builder__type').findAll('option').map((o) => o.element.value)

		expect(values).toEqual(['', 'object-op', 'run-node', 'open-form', 'open-page', 'navigate', 'export'])
	})

	it('authors a "start a flow" button with the app\'s flow and a node (run-node)', async () => {
		const wrapper = await typed('run-node')
		await wrapper.find('.action-builder__flow').setValue('flow-intake')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('.action-builder__node').setValue('start')
		const action = lastEmit(wrapper)[0]

		expect(action).toEqual({ id: 'act', label: 'Act', type: 'run-node', flowId: 'flow-intake', nodeId: 'start' })
		expect(validAction(action)).toBe(true)
	})

	it('authors a "change fields" button (object-op patch) with typed values and a confirm', async () => {
		const wrapper = await typed('object-op')
		await wrapper.find('.action-builder__new-key').setValue('priority')
		await wrapper.find('.action-builder__add-field').trigger('click')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('.action-builder__detail input[aria-label="Value for {field}"]').setValue('3')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('.action-builder__confirm').setValue(true)
		const action = lastEmit(wrapper)[0]

		expect(action).toEqual({ id: 'act', label: 'Act', type: 'object-op', op: 'patch', values: { priority: 3 }, confirm: true })
		expect(validAction(action)).toBe(true)
	})

	it('refuses a "change fields" button that sets the lifecycle field', async () => {
		const wrapper = await typed('object-op')
		const before = wrapper.emitted('update:modelValue').length
		await wrapper.find('.action-builder__new-key').setValue('status')
		await wrapper.find('.action-builder__add-field').trigger('click')

		expect(wrapper.emitted('update:modelValue')).toHaveLength(before)
		expect(wrapper.find('.action-builder__error').text()).toBe('status moves through the lifecycle. Use a status button instead.')
	})

	it('authors "open a form", "go to a page", "open a link" and "export"', async () => {
		let wrapper = await typed('open-form')
		await wrapper.find('.action-builder__schema').setValue('request')
		expect(lastEmit(wrapper)[0]).toEqual({ id: 'act', label: 'Act', type: 'open-form', schema: 'request' })
		expect(validAction(lastEmit(wrapper)[0])).toBe(true)

		wrapper = await typed('open-page')
		await wrapper.find('.action-builder__page').setValue('requests')
		expect(lastEmit(wrapper)[0]).toEqual({ id: 'act', label: 'Act', type: 'open-page', target: 'requests' })
		expect(validAction(lastEmit(wrapper)[0])).toBe(true)

		wrapper = await typed('navigate')
		await wrapper.find('.action-builder__link').setValue('https://example.org')
		expect(lastEmit(wrapper)[0]).toEqual({ id: 'act', label: 'Act', type: 'navigate', target: 'https://example.org' })
		expect(validAction(lastEmit(wrapper)[0])).toBe(true)

		wrapper = await typed('export')
		await wrapper.findAll('.action-builder__detail input[type="checkbox"]')[0].setValue(true)
		expect(lastEmit(wrapper)[0]).toEqual({ id: 'act', label: 'Act', type: 'export', formats: ['csv'] })
		expect(validAction(lastEmit(wrapper)[0])).toBe(true)
	})

	it('drops the old type\'s keys when the type changes', async () => {
		const wrapper = mountWith([{ id: 'a', label: 'A', icon: 'Play', type: 'run-node', flowId: 'f', nodeId: 'n' }])
		await wrapper.find('.action-builder__type').setValue('export')

		expect(lastEmit(wrapper)).toEqual([{ id: 'a', label: 'A', icon: 'Play', type: 'export' }])
	})

	it('shows old target actions typed and writes them typed on the next edit', async () => {
		const wrapper = mountWith([
			{ id: 'open', label: 'Open', target: 'navigate', route: 'request-detail' },
			{ id: 'emit', label: 'Emit', target: 'emit' },
			{ id: 'noop', label: 'Noop', target: 'none' },
		])

		expect(wrapper.find('.action-builder__note').text()).toContain('%n action that did nothing was removed.')
		expect(wrapper.find('.action-builder__page').element.value).toBe('request-detail')
		expect(wrapper.find('.action-builder__readonly').exists()).toBe(true)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()

		await wrapper.findAll('input[aria-label="Icon"]')[0].setValue('Eye')
		expect(lastEmit(wrapper)).toEqual([
			{ id: 'open', label: 'Open', type: 'open-page', target: 'request-detail', icon: 'Eye' },
			{ id: 'emit', label: 'Emit', type: 'handler', handler: 'emit' },
		])
	})

	it('writes a visibleWhen condition on the action', async () => {
		const wrapper = mount(ActionBuilder, {
			props: { modelValue: [{ id: 'a', label: 'A', type: 'run-node', flowId: 'f', nodeId: 'n' }], fieldOptions: ['status'] },
		})
		wrapper.findComponent({ name: 'VisibleWhenBuilder' }).vm.$emit('update:modelValue', { field: 'status', value: 'submitted' })
		const action = lastEmit(wrapper)[0]

		expect(action.visibleWhen).toEqual({ field: 'status', value: 'submitted' })
		expect(validAction(action)).toBe(true)
	})

	it('marks a flow the app no longer declares, and names what is missing', () => {
		const wrapper = mountWith([{ id: 'a', label: 'A', type: 'run-node', flowId: 'gone', nodeId: '' }])

		expect(wrapper.find('.action-builder__error').text()).toBe('This flow is no longer part of the app.')
		expect(wrapper.find('.action-builder__warn').text()).toBe('Pick the flow and the node to start.')
	})

	it('adds an empty action at the end and removes the clicked one', async () => {
		const actions = [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }]
		const wrapper = mountWith(actions)

		await wrapper.findAll('.action-builder__add').at(-1).trigger('click')
		expect(lastEmit(wrapper)).toEqual([...actions, { id: '', label: '' }])

		await wrapper.findAll('.action-builder__remove')[0].trigger('click')
		expect(lastEmit(wrapper)).toEqual([actions[1]])
	})
})
