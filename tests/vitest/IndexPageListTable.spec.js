/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the list page table (capability-requirements-backfill T06,
 * REQ-BQCV-006). A maker adds a list page, binds it to a register and schema
 * and picks its columns; the editor writes `register`, `schema` and
 * `columns` and no view mode, so nextcloud-vue's CnIndexPage draws its
 * default table view with column sorting and paging. The page's facets are
 * the schema's facetable properties (OpenRegister computes facets for those
 * only), so the maker gives the list a status facet with the field editor's
 * "Users can filter on this field" switch, which writes `facetable: true`.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-shows-records-in-a-table-with-sorting-filtering-and-paging-req-bqcv-006
 */
import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import stubDraggable from './stubs/vuedraggable.js'

vi.mock('../../src/composables/useEditorRegisterPicker.js', () => ({
	useEditorRegisterPicker: () => ({
		picker: {
			fetchRegisters: async () => [],
			fetchSchemas: async () => [],
			fetchSchemaProperties: async () => [],
		},
	}),
}))

const IndexPageEditor = (await import('../../src/components/page-editor/IndexPageEditor.vue')).default
const PageListEditor = (await import('../../src/components/page-editor/PageListEditor.vue')).default
const FieldEditorModule = await import('../../src/components/schema-editor/FieldEditor.vue')
const FieldEditor = FieldEditorModule.default
const { fieldsToSchema, schemaToFields } = FieldEditorModule

const t = (app, text) => text

/**
 * Apply one emitted config back onto the editor, as PageDesigner does.
 *
 * @param {object} wrapper The editor wrapper.
 * @return {Promise<object>} The config it emitted last.
 */
async function takeConfig(wrapper) {
	const config = wrapper.emitted('update:config').at(-1)[0]
	await wrapper.setProps({ config })
	return config
}

/**
 * Mount the field editor on rows, apply edits through its handlers, and
 * return the rows it emits.
 *
 * @param {Array<object>} fields The starting rows.
 * @param {Function} edit The edits, given an apply function.
 * @return {Promise<Array<object>>} The rows after the edits.
 */
async function editedFields(fields, edit) {
	const wrapper = shallowMount(FieldEditor, {
		props: { fields, schemaSlugs: [] },
		global: { mocks: { t } },
	})
	const apply = async (fn) => {
		fn(wrapper.vm)
		await wrapper.setProps({ fields: wrapper.emitted('update:fields').at(-1)[0] })
	}
	await edit(apply)
	return wrapper.props('fields')
}

describe('REQ-BQCV-006 list page table', () => {
	it('a maker adds a list of permits with the columns title, status and created', async () => {
		const list = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		list.vm.startAdd()
		list.vm.addingType = 'index'
		list.vm.confirmAdd()
		await list.vm.$nextTick()
		const page = list.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('index')

		const wrapper = shallowMount(IndexPageEditor, { props: { config: page.config } })
		wrapper.vm.update('register', 'vergunningen')
		await takeConfig(wrapper)
		wrapper.vm.update('schema', 'permit')
		await takeConfig(wrapper)
		wrapper.findComponent({ name: 'ColumnBuilder' }).vm.$emit('update:modelValue', [
			{ key: 'title' },
			{ key: 'status' },
			{ key: '@self.created' },
		])
		const config = await takeConfig(wrapper)

		expect(config).toMatchObject({
			register: 'vergunningen',
			schema: 'permit',
			columns: [{ key: 'title' }, { key: 'status' }, { key: '@self.created' }],
		})
		// No view mode and no paging override: CnIndexPage opens on its
		// default table view and pages through the remaining records itself.
		expect(config).not.toHaveProperty('viewMode')
		expect(config).not.toHaveProperty('viewModes')
		expect(config).not.toHaveProperty('pagination')
	})

	it('a maker gives the list a status facet: the saved property is facetable', async () => {
		const fields = await editedFields(
			[{ _key: 'status', name: 'status', type: 'text', required: false, default: null, description: '', validation: {} }],
			async (apply) => {
				await apply((vm) => vm.updateField(0, 'facetable', true))
			},
		)
		expect(fieldsToSchema(fields).properties.status).toEqual({ type: 'string', facetable: true })
	})

	it('the facet switch shows a loaded facet, survives a save and switches off', async () => {
		const loaded = schemaToFields({ properties: { status: { type: 'string', facetable: true, title: 'Status' } } })
		expect(loaded[0].facetable).toBe(true)
		expect(fieldsToSchema(loaded).properties.status).toEqual({ type: 'string', facetable: true, title: 'Status' })

		const wrapper = shallowMount(FieldEditor, {
			props: { fields: loaded, schemaSlugs: [] },
			global: { mocks: { t } },
		})
		const toggle = wrapper.find('[data-testid="schema-field-facetable"]')
		expect(toggle.exists()).toBe(true)
		expect(toggle.attributes('modelvalue')).toBe('true')

		wrapper.vm.updateField(0, 'facetable', false)
		const off = wrapper.emitted('update:fields').at(-1)[0]
		expect(fieldsToSchema(off).properties.status).toEqual({ type: 'string', title: 'Status' })
	})

	it('a property that is not a facet keeps its shape', () => {
		const loaded = schemaToFields({ properties: { title: { type: 'string' } } })
		expect(loaded[0]).not.toHaveProperty('facetable')
		expect(fieldsToSchema(loaded).properties.title).toEqual({ type: 'string' })
	})
})
