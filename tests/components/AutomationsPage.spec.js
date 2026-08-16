/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationsPage.vue.
 *
 * Spec: automation-designer (REQ-AUTD-001, REQ-AUTD-006).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationsPage from '../../src/views/AutomationsPage.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel', 'disabled'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['checked', 'type'],
	template:
		'<label class="ncswitch-stub" @click="$emit(\'update:checked\', !checked)"><slot /></label>',
}
const NcLoadingIconStub = {
	name: 'NcLoadingIcon',
	template: '<div class="ncloading-stub" />',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="ncempty-stub">{{ name }}</div>',
}
const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="ncnotecard-stub"><slot /></div>',
}

const stubs = {
	NcButton: NcButtonStub,
	NcSelect: NcSelectStub,
	NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
	NcLoadingIcon: NcLoadingIconStub,
	NcEmptyContent: NcEmptyContentStub,
	NcNoteCard: NcNoteCardStub,
	AutomationEditDialog: {
		name: 'AutomationEditDialog',
		template: '<div class="edit-dialog-stub" />',
	},
	AutomationTestPanelModal: {
		name: 'AutomationTestPanelModal',
		template: '<div class="test-panel-stub" />',
	},
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const application = { slug: 'permit-tracker', name: 'Permit Tracker' }
const version = {
	id: 'version-1',
	name: 'Development',
	register: 'openbuild-permit-tracker-development',
}

const automation = (overrides = {}) => ({
	id: 'aut-1',
	slug: 'notify-caseworkers',
	name: 'Notify case workers',
	applicationSlug: 'permit-tracker',
	versionUuid: 'version-1',
	enabled: true,
	trigger: { type: 'object-created', schema: 'permit' },
	actions: [{ type: 'send-notification' }],
	...overrides,
})

describe('AutomationsPage', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications') && !url.includes('versions')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			if (url.includes('/versions')) {
				return Promise.resolve({ data: { results: [version] } })
			}
			if (url.includes('/status')) {
				return Promise.resolve({ data: { drift: false } })
			}
			if (url.includes('/automation')) {
				return Promise.resolve({ data: { results: [automation()] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	})

	it('REQ-AUTD-001: renders each automation with name/trigger/action summary for the selected version', async () => {
		const wrapper = mount(AutomationsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		wrapper.vm.selectedVersion = version
		wrapper.vm.onVersionChange()
		await flush()
		await flush()

		const rows = wrapper.findAll('[data-testid="automation-row"]')
		expect(rows).toHaveLength(1)
		expect(rows.at(0).text()).toContain('Notify case workers')
	})

	it('REQ-AUTD-001: empty state renders without error for a version with no automations', async () => {
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications') && !url.includes('versions')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			if (url.includes('/versions')) {
				return Promise.resolve({ data: { results: [version] } })
			}
			if (url.includes('/automation')) {
				return Promise.resolve({ data: { results: [] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = mount(AutomationsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		wrapper.vm.selectedVersion = version
		wrapper.vm.onVersionChange()
		await flush()
		await flush()

		expect(wrapper.find('.ncempty-stub').exists()).toBe(true)
		expect(wrapper.find('.ncnotecard-stub').exists()).toBe(false)
	})

	it('REQ-AUTD-006: toggling the switch calls the enable/disable controller endpoint', async () => {
		const wrapper = mount(AutomationsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		wrapper.vm.selectedVersion = version
		wrapper.vm.onVersionChange()
		await flush()
		await flush()

		axios.post.mockResolvedValue({ data: {} })
		await wrapper.vm.toggleEnabled(automation(), false)

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/openbuild/api/automations/aut-1/disable'),
			{},
		)
	})

	it('drift badge renders when status reports drift', async () => {
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications') && !url.includes('versions')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			if (url.includes('/versions')) {
				return Promise.resolve({ data: { results: [version] } })
			}
			if (url.includes('/status')) {
				return Promise.resolve({ data: { drift: true } })
			}
			if (url.includes('/automation')) {
				return Promise.resolve({ data: { results: [automation()] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = mount(AutomationsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		wrapper.vm.selectedVersion = version
		wrapper.vm.onVersionChange()
		await flush()
		await flush()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="drift-badge"]').exists()).toBe(true)
	})

	it("REQ-AUTD-001: switching versions refetches and shows only that version's automations", async () => {
		const otherVersion = {
			id: 'version-2',
			name: 'Production',
			register: 'openbuild-permit-tracker-production',
		}
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications') && !url.includes('versions')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			if (url.includes('/versions')) {
				return Promise.resolve({
					data: { results: [version, otherVersion] },
				})
			}
			if (url.includes('/automation')) {
				return Promise.resolve({
					data: {
						results: [
							automation({
								id: 'aut-1',
								versionUuid: 'version-1',
								name: 'Draft automation',
							}),
							automation({
								id: 'aut-2',
								versionUuid: 'version-2',
								name: 'Prod automation',
							}),
						],
					},
				})
			}
			if (url.includes('/status')) {
				return Promise.resolve({ data: { drift: false } })
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = mount(AutomationsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()

		wrapper.vm.selectedVersion = version
		wrapper.vm.onVersionChange()
		await flush()
		await flush()
		expect(wrapper.vm.automations.map((a) => a.id)).toEqual(['aut-1'])

		wrapper.vm.selectedVersion = otherVersion
		wrapper.vm.onVersionChange()
		await flush()
		await flush()
		expect(wrapper.vm.automations.map((a) => a.id)).toEqual(['aut-2'])
	})
})
