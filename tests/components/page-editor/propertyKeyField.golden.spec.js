/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the property-key fields of the Map and Wiki page editors (marker
 * latitude / longitude / popup; content, title, tree-children and sidebar
 * title): the markup with a bound schema (a select over the property keys)
 * and without one (a text input), and the `update:config` a change emits.
 * Scoped-style `data-v-*` attributes are left out.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [],
		fetchSchemas: async () => [],
		fetchSchemaProperties: async () => ({ lat: {}, lng: {}, name: {} }),
		resolveAppRegister: () => '',
	}),
}))

import MapPageEditor from '../../../src/components/page-editor/MapPageEditor.vue'
import WikiPageEditor from '../../../src/components/page-editor/WikiPageEditor.vue'

const BOUND_MARKERS = { dataSource: { register: 'r', schema: 's' }, latField: 'lat' }

const CASES = [
	{
		name: 'MapPageEditor',
		Editor: MapPageEditor,
		labels: ['Latitude field', 'Longitude field', 'Popup field'],
		bound: { markers: BOUND_MARKERS },
		unbound: { markers: { dataSource: { url: 'https://x' }, lngField: 'lng' } },
	},
	{
		name: 'WikiPageEditor',
		Editor: WikiPageEditor,
		labels: ['Content field', 'Title field', 'Tree children field', 'Sidebar title field'],
		bound: { register: 'r', schema: 's', sidebarSchema: 's', titleField: 'name' },
		unbound: { treeField: 'kids' },
	},
]

/**
 * @param {object} Editor the editor.
 * @param {object} config the config prop.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountEditor(Editor, config) {
	const wrapper = mount(Editor, { props: { config, appSlug: 'app' } })
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper the editor.
 * @param {string} text the label text.
 * @return {import('@vue/test-utils').DOMWrapper} the label.
 */
function labelOf(wrapper, text) {
	return wrapper.findAll('label').find((l) => l.text().startsWith(text))
}

describe.each(CASES)('$name property-key fields', ({ Editor, labels, bound, unbound }) => {
	it.each([['bound', bound], ['unbound', unbound]])('renders the %s shape', async (_shape, config) => {
		const wrapper = await mountEditor(Editor, config)
		const html = labels.map((text) =>
			labelOf(wrapper, text).html().replace(/ data-v-[0-9a-f]+=""/g, ''))
		expect(html).toMatchSnapshot()
	})

	it.each([['bound', bound], ['unbound', unbound]])('emits each %s field change', async (shape, config) => {
		const wrapper = await mountEditor(Editor, config)
		for (const text of labels) {
			const control = labelOf(wrapper, text).find(shape === 'bound' ? 'select' : 'input[type="text"]')
			await control.setValue(shape === 'bound' ? 'name' : 'typed')
		}
		expect(wrapper.emitted('update:config')).toMatchSnapshot()
	})
})
