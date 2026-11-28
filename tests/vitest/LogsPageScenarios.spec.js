/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for adding a log page (capability-requirements-backfill T10,
 * REQ-BQCV-010), as far as buildiq builds it: the page list adds a page of
 * type `logs`, and LogsPageEditor binds it to a register and schema, writing
 * `config.register` and `config.schema`, the keys nextcloud-vue's CnLogsPage
 * reads.
 *
 * The running app's half, the changes to the bound schema's records newest
 * first, is BuildiqLogsPage's: see tests/components/runtime/BuildiqLogsPage.spec.js.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
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

const LogsPageEditor = (await import('../../src/components/page-editor/LogsPageEditor.vue')).default

/**
 * The config the editor emitted last.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The config.
 */
function lastConfig(wrapper) {
	return wrapper.emitted('update:config').at(-1)[0]
}

describe('a maker adds a log page (REQ-BQCV-010)', () => {
	it('adds a page of type logs waiting for a register and schema', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'logs'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()

		const page = wrapper.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('logs')
		expect(page.config).toEqual({ register: '', schema: '', columns: [] })
	})

	it('binds the page to the register and the schema permit', async () => {
		const wrapper = mount(LogsPageEditor, { props: { config: { register: '', schema: '', columns: [] } } })
		const fields = wrapper.findComponent({ name: 'RegisterSchemaFields' })

		fields.vm.$emit('register', 'vergunningen')
		await wrapper.setProps({ config: lastConfig(wrapper) })
		fields.vm.$emit('schema', 'permit')

		expect(lastConfig(wrapper)).toEqual({ register: 'vergunningen', schema: 'permit', columns: [] })
		expect(wrapper.vm.sourceShape).toBe('register')
	})

	it('drops a free-text source when the maker switches to register and schema', async () => {
		const wrapper = mount(LogsPageEditor, { props: { config: { source: '/api/x/audit', columns: ['action'] } } })
		await wrapper.find('input[type="radio"][value="register"]').trigger('change')

		expect(lastConfig(wrapper)).toEqual({ columns: ['action'] })
	})
})
