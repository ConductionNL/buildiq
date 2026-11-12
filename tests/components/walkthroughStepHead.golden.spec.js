// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Pins the step header rows of WalkthroughDesigner, in the tour editor and
 * in the setup wizard editor: the markup (step number, id field, move up,
 * move down and delete with their disabled states) and the manifest each
 * control emits. Scoped-style `data-v-*` attributes are left out.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WalkthroughDesigner from '../../src/components/walkthrough-editor/WalkthroughDesigner.vue'

const stubs = {
	CnTabs: { template: '<div class="tabs"><slot /></div>' },
	CnTab: { template: '<section class="tab"><slot /></section>' },
	NcTextField: {
		props: ['label', 'modelValue'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: {
		props: ['variant', 'disabled', 'ariaLabel'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: { template: '<div class="select" />' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	WalkthroughRecorder: { template: '<div />' },
}

const MANIFEST = {
	version: '1.0.0',
	menu: [],
	pages: [],
	walkthrough: { enabled: true, tours: [{ id: 'tour', steps: [{ id: 's1' }, { id: 's2' }, { id: 's3' }] }] },
	setup: { enabled: true, steps: [{ id: 'a', type: 'info' }, { id: 'b', type: 'info' }] },
}

/**
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountDesigner() {
	return mount(WalkthroughDesigner, {
		props: { manifest: MANIFEST },
		global: { stubs, mocks: { $route: { query: {} } } },
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper the designer.
 * @return {Array<import('@vue/test-utils').DOMWrapper>} the step header rows.
 */
function heads(wrapper) {
	return wrapper.findAll('.wt-designer__step-head')
}

describe('WalkthroughDesigner step header rows', () => {
	it('renders the tour and setup step headers', () => {
		const wrapper = mountDesigner()
		expect(heads(wrapper).map((h) => h.html().replace(/ data-v-[0-9a-f]+=""/g, ''))).toMatchSnapshot()
	})

	it('emits the manifest of every control', async () => {
		const wrapper = mountDesigner()
		for (const index of [1, 3]) {
			const head = heads(wrapper)[index]
			await head.find('input').setValue('renamed')
			const buttons = head.findAll('button')
			for (const button of buttons) {
				await button.trigger('click')
			}
		}
		const emitted = wrapper.emitted('update:manifest').map(([m]) => ({
			tour: m.walkthrough.tours[0].steps.map((s) => s.id),
			setup: m.setup.steps.map((s) => s.id),
		}))
		expect(emitted).toMatchSnapshot()
	})
})
