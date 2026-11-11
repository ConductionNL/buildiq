/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning AutomationActionListEditor.vue's two shape conversions:
 * stored action record → flat editor shape (`toEditorShape`) and flat editor
 * shape → stored action record (`emittedValue`), for every action type and
 * every missing-field and invalid-JSON fallback.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AutomationActionListEditor from '../../src/components/AutomationActionListEditor.vue'

/**
 * Mount the editor on a list of stored actions.
 *
 * @param {Array<object>} modelValue Stored actions.
 * @return {object} The component instance.
 */
function editorOn(modelValue) {
	return shallowMount(AutomationActionListEditor, {
		propsData: { modelValue, label: 'On approve' },
	}).vm
}

describe('AutomationActionListEditor toEditorShape', () => {
	it('fills every missing field with its default', () => {
		const shape = editorOn([]).toEditorShape({})
		expect(shape._key).toMatch(/^follow-up-\d+$/)
		const { _key, ...rest } = shape
		expect(Object.keys(shape)).toEqual([
			'_key',
			'type',
			'subjectEn',
			'subjectNl',
			'operation',
			'schema',
			'fieldMappingText',
			'url',
			'payloadTemplateText',
		])
		expect(rest).toEqual({
			type: 'send-notification',
			subjectEn: '',
			subjectNl: '',
			operation: 'update',
			schema: '',
			fieldMappingText: '{}',
			url: '',
			payloadTemplateText: '{}',
		})
	})

	it('copies every stored field and pretty-prints the JSON parts', () => {
		const vm = editorOn([])
		const shape = vm.toEditorShape({
			type: 'webhook',
			subject: { en: 'Hi', nl: 'Hoi' },
			operation: 'create',
			schema: 'case',
			fieldMapping: { a: '{{ x }}' },
			url: 'https://example.test/hook',
			payloadTemplate: { b: 1 },
		})
		expect(shape).toMatchObject({
			type: 'webhook',
			subjectEn: 'Hi',
			subjectNl: 'Hoi',
			operation: 'create',
			schema: 'case',
			fieldMappingText: '{\n  "a": "{{ x }}"\n}',
			url: 'https://example.test/hook',
			payloadTemplateText: '{\n  "b": 1\n}',
		})
		expect(vm.toEditorShape({}).
			_key).not.toBe(shape._key)
	})
})

describe('AutomationActionListEditor emittedValue', () => {
	const item = (fields) => ({
		_key: 'k',
		type: 'send-notification',
		subjectEn: 'E',
		subjectNl: 'N',
		operation: 'update',
		schema: 's',
		fieldMappingText: '{}',
		url: 'u',
		payloadTemplateText: '{}',
		...fields,
	})

	it('emits each type in its stored shape', () => {
		const vm = editorOn([])
		vm.items = [
			item({}),
			item({ type: 'object-op', fieldMappingText: '{"a":1}' }),
			item({ type: 'object-op', fieldMappingText: '' }),
			item({ type: 'object-op', fieldMappingText: 'not json' }),
			item({ type: 'webhook', payloadTemplateText: '{"b":2}' }),
			item({ type: 'webhook', payloadTemplateText: '' }),
			item({ type: 'webhook', payloadTemplateText: '{bad' }),
			item({ type: 'something-else' }),
			item({ type: 'constructor' }),
		]
		expect(vm.emittedValue()).toEqual([
			{ type: 'send-notification', subject: { en: 'E', nl: 'N' } },
			{ type: 'object-op', operation: 'update', schema: 's', fieldMapping: { a: 1 } },
			{ type: 'object-op', operation: 'update', schema: 's' },
			{ type: 'object-op', operation: 'update', schema: 's' },
			{ type: 'webhook', url: 'u', payloadTemplate: { b: 2 } },
			{ type: 'webhook', url: 'u' },
			{ type: 'webhook', url: 'u' },
			{ type: 'webhook', url: 'u' },
			{ type: 'webhook', url: 'u' },
		])
	})

	it('round-trips stored actions through the editor shape', () => {
		const stored = [
			{ type: 'send-notification', subject: { en: 'a', nl: 'b' } },
			{ type: 'object-op', operation: 'create', schema: 'x', fieldMapping: { f: 1 } },
			{ type: 'webhook', url: 'https://example.test', payloadTemplate: { p: true } },
		]
		expect(editorOn(stored).emittedValue()).toEqual(stored)
	})
})
