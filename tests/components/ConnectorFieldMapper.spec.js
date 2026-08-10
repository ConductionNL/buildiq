/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ConnectorFieldMapper.vue (click-to-map + dead-selector flag).
 *
 * Spec: openconnector-api-sources (REQ-OCAS-003).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ConnectorFieldMapper from '../../src/components/page-editor/ConnectorFieldMapper.vue'

// `emits: ['click']` keeps the parent's `@click` out of `$attrs` so it does not
// also fall through onto the root <button> and fire the handler twice.
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const factory = (props) => mount(ConnectorFieldMapper, {
	propsData: props,
	stubs: { NcButton: NcButtonStub },
})

describe('ConnectorFieldMapper', () => {
	// window.prompt is no longer used — the field name is collected by
	// PromptTextDialog, driven directly in the test below.

	it('emits itemsPath when an array node is clicked', async () => {
		const wrapper = factory({
			binding: { fields: {} },
			sample: { resultaten: [{ naam: 'Acme' }], totaal: 1 },
		})
		// VTU v2 `findAll` returns a plain Array, not a v1 WrapperArray — the
		// `.wrappers` accessor is gone and reads as undefined.
		const arrayBtn = wrapper.findAll('button').find((b) => b.text().includes('resultaten'))
		await arrayBtn.trigger('click')
		expect(wrapper.emitted()['update:itemsPath'][0]).toEqual(['resultaten'])
	})

	it('asks for the display name before emitting a field mapping', async () => {
		const wrapper = factory({
			binding: { itemsPath: 'resultaten', fields: {} },
			sample: { resultaten: [{ naam: 'Acme', kvkNummer: '123' }] },
		})
		const leaf = wrapper.findAll('button').find((b) => b.text().includes('naam'))
		await leaf.trigger('click')
		// Clicking a leaf opens the prompt pre-filled with the leaf key and
		// emits nothing yet — the mapping only exists once a name is given.
		expect(wrapper.vm.promptOpen).toBe(true)
		expect(wrapper.vm.promptSuggestion).toBe('naam')
		expect(wrapper.emitted()['update:fields']).toBeUndefined()

		wrapper.vm.onPromptSubmit('naam')
		expect(wrapper.emitted()['update:fields'][0][0]).toEqual({ naam: 'naam' })
	})

	it('a dismissed prompt adds no mapping', async () => {
		const wrapper = factory({
			binding: { itemsPath: 'resultaten', fields: {} },
			sample: { resultaten: [{ naam: 'Acme' }] },
		})
		const leaf = wrapper.findAll('button').find((b) => b.text().includes('naam'))
		await leaf.trigger('click')
		wrapper.vm.promptOpen = false
		expect(wrapper.emitted()['update:fields']).toBeUndefined()
	})

	it('round-trips an existing mapping with live sample values', () => {
		const wrapper = factory({
			binding: { itemsPath: 'resultaten', fields: { name: 'naam' } },
			sample: { resultaten: [{ naam: 'Acme' }] },
		})
		expect(wrapper.text()).toContain('Acme')
	})

	it('flags a dead selector after a sample mutation without changing the mapping', () => {
		const wrapper = factory({
			binding: { itemsPath: 'resultaten', fields: { kvk: 'kvkNummer' } },
			sample: { resultaten: [{ kvk_nummer: '123' }] }, // renamed upstream
		})
		expect(wrapper.find('.connector-field-mapper__warn').exists()).toBe(true)
		// mapping untouched — no update emitted
		expect(wrapper.emitted()['update:fields']).toBeUndefined()
	})
})
