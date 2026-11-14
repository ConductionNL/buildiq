/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `DataModelDiagram.vue` (data-model-diagram T03, T04): the
 * read-only canvas, its schema box, the fold threshold, opening a schema by
 * click or Enter, and the relation table under the canvas.
 *
 * `CnGraphCanvas` is the vitest stand-in from
 * `tests/vitest/stubs/conduction-nextcloud-vue.js`, which renders nodes the
 * way Vue Flow wraps them (`.vue-flow__node[data-id]` around the focusable
 * `.cn-flow-node`) and passes the host's `#node` slot through.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DataModelDiagram from '../../../src/components/schema-editor/DataModelDiagram.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant'],
		emits: ['click'],
		template: '<button type="button" class="nc-button" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty-stub">{{ name }}</div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div class="loading-stub" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note-stub"><slot /></div>' },
}

const MODEL = {
	nodes: [
		{ id: '1', slug: 'permit', title: 'Permit', external: false, readable: true, properties: [{ name: 'subject', type: 'string' }] },
		{ id: '2', slug: 'applicant', title: 'Applicant', external: false, readable: true, properties: [{ name: 'name', type: 'string' }] },
		{ id: '3', slug: 'document', title: 'Document', external: false, readable: true, properties: [{ name: 'file', type: 'string' }] },
		{ id: '4', slug: 'note', title: 'Note', external: false, readable: true, properties: [] },
		{ id: '99', slug: null, title: null, external: true, readable: false, properties: [] },
	],
	edges: [
		{ from: '1', to: '2', property: 'applicant', cardinality: 'one', inverse: null, source: 'declared', target: 'applicant', dangling: false },
		{ from: '1', to: '3', property: 'documents', cardinality: 'many', inverse: null, source: 'property', target: 'document', dangling: false },
		{ from: '1', to: '99', property: 'owner', cardinality: 'one', inverse: null, source: 'property', target: 'x', dangling: false },
	],
}

/**
 * Mount the diagram.
 *
 * @param {object} props Props.
 * @return {object} The wrapper.
 */
function mountDiagram(props = {}) {
	return mount(DataModelDiagram, {
		props: { model: MODEL, ...props },
		global: { stubs },
		attachTo: document.body,
	})
}

/**
 * The focusable node element of a box.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} id Node id.
 * @return {object} The DOM wrapper.
 */
function box(wrapper, id) {
	return wrapper.find(`.vue-flow__node[data-id="${id}"] .cn-flow-node`)
}

describe('DataModelDiagram', () => {
	it('runs the canvas read only with one schema box per node', () => {
		const wrapper = mountDiagram()
		const canvas = wrapper.findComponent({ name: 'CnGraphCanvas' })

		expect(canvas.props('readOnly')).toBe(true)
		expect(canvas.props('nodes')).toHaveLength(5)
		expect(canvas.props('edges')).toHaveLength(3)
		expect(box(wrapper, '1').text()).toContain('Permit')
		expect(box(wrapper, '1').text()).toContain('subject')
		expect(box(wrapper, '99').text()).toContain('Schema you may not read')
		wrapper.unmount()
	})

	it('labels a line with the relation name and its marker', () => {
		const wrapper = mountDiagram()
		const labels = wrapper.findAll('.vue-flow__edge').map((e) => e.text())

		expect(labels).toEqual(['applicant (1)', 'documents (n)', 'owner (1)'])
		wrapper.unmount()
	})

	it('says how many fields a box leaves out after twelve', () => {
		const properties = Array.from({ length: 15 }, (_, i) => ({ name: `f${i}`, type: 'string' }))
		const wrapper = mountDiagram({
			model: { nodes: [{ id: '1', slug: 'wide', title: 'Wide', readable: true, properties }], edges: [] },
		})

		expect(wrapper.findAll('[data-testid="data-model-field"]')).toHaveLength(12)
		expect(wrapper.find('[data-testid="data-model-more"]').text()).toBe('and {count} more')
		wrapper.unmount()
	})

	it('folds the boxes to their titles above the threshold and keeps the table complete', () => {
		const wrapper = mountDiagram({ foldThreshold: 3 })

		expect(wrapper.find('.note-stub').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="data-model-field"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-testid="data-model-relation-row"]')).toHaveLength(3)
		wrapper.unmount()

		const unfolded = mountDiagram({ foldThreshold: 4 })
		expect(unfolded.find('.note-stub').exists()).toBe(false)
		expect(unfolded.findAll('[data-testid="data-model-field"]').length).toBeGreaterThan(0)
		unfolded.unmount()
	})

	it('emits open with the slug when Enter is pressed on a box', async () => {
		const wrapper = mountDiagram()

		await box(wrapper, '3').trigger('keydown', { key: 'Enter' })

		expect(wrapper.emitted('open')).toEqual([['document']])
		wrapper.unmount()
	})

	it('emits open with the slug when a box is clicked', async () => {
		const wrapper = mountDiagram()

		await wrapper.find('.vue-flow__node[data-id="2"]').trigger('click')

		expect(wrapper.emitted('open')).toEqual([['applicant']])
		wrapper.unmount()
	})

	it('does nothing when a schema the maker may not read is clicked or entered', async () => {
		const wrapper = mountDiagram()

		await wrapper.find('.vue-flow__node[data-id="99"]').trigger('click')
		await box(wrapper, '99').trigger('keydown', { key: 'Enter' })

		expect(wrapper.emitted('open')).toBeUndefined()
		wrapper.unmount()
	})

	it('lists every relation and every unlinked schema as a table row', () => {
		const wrapper = mountDiagram()
		const rows = wrapper.findAll('[data-testid="data-model-relation-row"]').map((row) =>
			row.findAll('td').slice(0, 4).map((cell) => cell.text()),
		)

		expect(rows).toEqual([
			['Permit', 'applicant', 'Applicant', 'one'],
			['Permit', 'documents', 'Document', 'many'],
			['Permit', 'owner', 'Schema you may not read', 'one'],
		])
		const unlinked = wrapper.findAll('[data-testid="data-model-unlinked-row"]')
		expect(unlinked).toHaveLength(1)
		expect(unlinked[0].text()).toContain('Note')
		wrapper.unmount()
	})

	it('gives every row an Open button reachable by Tab that opens the schema', async () => {
		const wrapper = mountDiagram()
		const rows = wrapper.findAll('[data-testid="data-model-relation-row"], [data-testid="data-model-unlinked-row"]')

		for (const row of rows) {
			const button = row.find('button')
			expect(button.exists()).toBe(true)
			// A native button with no negative tabindex is in the Tab order.
			expect(button.attributes('tabindex')).not.toBe('-1')
			expect(button.text()).toBe('Open')
		}
		await rows[3].find('button').trigger('click')
		expect(wrapper.emitted('open')).toEqual([['note']])
		wrapper.unmount()
	})

	it('shows the empty state for a register without schemas', () => {
		const wrapper = mountDiagram({ model: { nodes: [], edges: [] } })

		expect(wrapper.find('.empty-stub').text()).toBe('This register has no schemas yet')
		expect(wrapper.findComponent({ name: 'CnGraphCanvas' }).exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows the error instead of the canvas when the model failed to load', () => {
		const wrapper = mountDiagram({ model: null, error: 'Register not found' })

		expect(wrapper.find('.empty-stub').text()).toBe('The data model could not be loaded')
		wrapper.unmount()
	})
})
