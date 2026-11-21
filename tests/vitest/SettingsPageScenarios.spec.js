/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for adding a settings page (capability-requirements-backfill
 * T11, REQ-BQCV-011), as far as buildiq builds it: the page list adds a page
 * of type `settings`, the maker adds a section holding the option reply
 * period, and the editor writes it into `config.sections[].fields[]`, the
 * shape nextcloud-vue's CnSettingsPage renders. A field's `default` is what
 * CnSettingsPage shows before anything was saved.
 *
 * Not asserted here, because it is not built: CnSettingsPage PUTs a save to
 * `config.saveEndpoint` (free text, no buildiq endpoint behind it) and reads
 * its values only from the `initialValues` prop, which nothing loads; so a
 * user's saved reply period is not shown on the next visit. That gap is
 * reported on T11.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import stubDraggable from './stubs/vuedraggable.js'

vi.mock('../../src/components/page-editor/fields/FormFieldBuilder.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'FormFieldBuilder',
			props: ['modelValue'],
			render() {
				return h('div', { class: 'form-field-builder-stub' })
			},
		},
	}
})

const SettingsPageEditor = (await import('../../src/components/page-editor/SettingsPageEditor.vue')).default

const REPLY_PERIOD = { key: 'replyPeriod', type: 'number', label: 'Reply period (days)', default: 14 }

/**
 * The config the editor emitted last.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The config.
 */
function lastConfig(wrapper) {
	return wrapper.emitted('update:config').at(-1)[0]
}

describe('a maker adds a settings page (REQ-BQCV-011)', () => {
	it('adds a page of type settings with an empty section list', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'settings'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()

		const page = wrapper.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('settings')
		expect(page.config).toEqual({ sections: [] })
	})

	it('writes a section holding the option reply period into config.sections', async () => {
		const wrapper = mount(SettingsPageEditor, { props: { config: { sections: [] } } })

		await wrapper.find('.settings-section-builder__add').trigger('click')
		await wrapper.setProps({ config: lastConfig(wrapper) })
		await wrapper.find('.settings-section-builder__field').setValue('Replies')
		await wrapper.setProps({ config: lastConfig(wrapper) })
		wrapper.findComponent({ name: 'FormFieldBuilder' }).vm.$emit('update:modelValue', [REPLY_PERIOD])

		expect(lastConfig(wrapper)).toEqual({
			sections: [{ title: 'Replies', fields: [REPLY_PERIOD] }],
		})
	})

	it('shows the saved option again when the page is opened in the editor', () => {
		const wrapper = mount(SettingsPageEditor, {
			props: { config: { sections: [{ title: 'Replies', fields: [REPLY_PERIOD] }] } },
		})

		expect(wrapper.find('.settings-section-builder__field').element.value).toBe('Replies')
		expect(wrapper.findComponent({ name: 'FormFieldBuilder' }).props('modelValue')).toEqual([REPLY_PERIOD])
	})

	it('keeps the sections when the maker sets where a save is sent', async () => {
		const sections = [{ title: 'Replies', fields: [REPLY_PERIOD] }]
		const wrapper = mount(SettingsPageEditor, { props: { config: { sections } } })
		await wrapper.find('.settings-page-editor__group-row input').setValue('/apps/permits/api/settings')

		expect(lastConfig(wrapper)).toEqual({ sections, saveEndpoint: '/apps/permits/api/settings' })
	})
})
