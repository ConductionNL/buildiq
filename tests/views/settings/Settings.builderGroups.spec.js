/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin Configuration section offers a Builder groups picker
 * (REQ-OBRBAC-008, docs/tutorials/admin/01-rbac.md steps 2 to 4): it shows
 * the stored groups and saves the picked ones with the other settings.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../../src/store/modules/settings.js'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		template: '<section><slot /></section>',
	},
}))

import Settings from '../../../src/views/settings/Settings.vue'

const SelectGroupStub = {
	name: 'NcSettingsSelectGroup',
	props: ['modelValue', 'label', 'placeholder', 'id'],
	emits: ['update:modelValue'],
	template: '<div class="select-group-stub" :data-label="label" />',
}

function mountSettings(settings) {
	const store = useSettingsStore()
	store.settings = settings
	store.saveSettings = vi.fn().mockResolvedValue({ ...settings })
	const wrapper = mount(Settings, {
		global: {
			stubs: {
				NcSettingsSelectGroup: SelectGroupStub,
				NcButton: { template: '<button type="submit"><slot /></button>' },
			},
		},
	})
	return { wrapper, store }
}

describe('Settings builder groups', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows a Builder groups picker with the stored groups', () => {
		const { wrapper } = mountSettings({
			register: 'buildiq',
			builder_groups: ['buildiq-builders'],
		})
		const picker = wrapper.findComponent(SelectGroupStub)

		expect(picker.exists()).toBe(true)
		expect(picker.props('label')).toBe('Builder groups')
		expect(picker.props('modelValue')).toEqual(['buildiq-builders'])
	})

	it('starts empty when no builder groups are stored', () => {
		const { wrapper } = mountSettings({ register: 'buildiq' })
		expect(wrapper.findComponent(SelectGroupStub).props('modelValue')).toEqual(
			[],
		)
	})

	it('saves the picked groups with the other settings', async () => {
		const { wrapper, store } = mountSettings({
			register: 'buildiq',
			builder_groups: [],
		})

		await wrapper
			.findComponent(SelectGroupStub)
			.vm.$emit('update:modelValue', ['buildiq-builders', 'team-alpha'])
		await wrapper.find('form').trigger('submit')

		expect(store.saveSettings).toHaveBeenCalledTimes(1)
		expect(store.saveSettings.mock.calls[0][0].builder_groups).toEqual([
			'buildiq-builders',
			'team-alpha',
		])
		expect(store.saveSettings.mock.calls[0][0].register).toBe('buildiq')
	})
})
