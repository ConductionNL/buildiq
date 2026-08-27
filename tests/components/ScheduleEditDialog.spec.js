/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ScheduleEditDialog.vue.
 *
 * Spec: schedules-editor / buildiq-schedules-authoring
 * (REQ-OBSA-002, REQ-OBSA-003, REQ-OBSA-004, REQ-OBSA-006).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import ScheduleEditDialog from '../../src/dialogs/ScheduleEditDialog.vue'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel', 'label', 'clearable'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label', 'placeholder', 'type', 'error', 'helperText'],
	template:
		'<input class="nctextfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}
const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['checked', 'type'],
	template: '<label class="ncswitch-stub"><slot /></label>',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcModalStub = {
	name: 'NcModal',
	props: ['name'],
	template: '<div class="ncmodal-stub"><slot /></div>',
}

const stubs = {
	NcModal: NcModalStub,
	NcSelect: NcSelectStub,
	NcTextField: NcTextFieldStub,
	NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
	NcButton: NcButtonStub,
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const factory = (propsData = {}) =>
	mount(ScheduleEditDialog, {
		propsData: { open: false, ...propsData },
		stubs,
	})

/** Open the dialog (fires the watcher → hydrate + fetch). */
const openDialog = async (wrapper) => {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

const cadence = (wrapper, id) => wrapper.vm.cadenceOptions.find((o) => o.id === id)

describe('ScheduleEditDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
	})

	it('a non-custom preset writes an interval and no cron', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.label = 'Nightly BRP sync'
		wrapper.vm.cadenceOption = cadence(wrapper, 'daily')
		wrapper.vm.syncId = '00000000-0000-0000-0000-000000000000'
		const entry = wrapper.vm.candidateEntry
		expect(entry.interval).toBe(86400)
		expect(entry.cron).toBeUndefined()
		expect(entry.id).toBe('nightly-brp-sync')
	})

	it('custom cron writes a validated cron and no interval', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.label = 'Weekly report'
		wrapper.vm.cadenceOption = cadence(wrapper, 'custom-cron')
		wrapper.vm.cron = '0 3 * * 1'
		wrapper.vm.syncId = 'abc'
		const entry = wrapper.vm.candidateEntry
		expect(entry.cron).toBe('0 3 * * 1')
		expect(entry.interval).toBeUndefined()
		expect(wrapper.vm.valid).toBe(true)
	})

	it('a malformed cron blocks saving', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.label = 'Bad'
		wrapper.vm.cadenceOption = cadence(wrapper, 'custom-cron')
		wrapper.vm.cron = '0 2 * *'
		wrapper.vm.syncId = 'abc'
		expect(wrapper.vm.valid).toBe(false)
	})

	it('the sync action writes action + arguments.synchronizationId', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.label = 'Sync it'
		wrapper.vm.cadenceOption = cadence(wrapper, 'hourly')
		wrapper.vm.syncId = 'sync-123'
		const entry = wrapper.vm.candidateEntry
		expect(entry.action).toBe('openconnector:synchronization')
		expect(entry.arguments.synchronizationId).toBe('sync-123')
	})

	it('enabled defaults on and toggles off', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		expect(wrapper.vm.enabled).toBe(true)
		wrapper.vm.enabled = false
		wrapper.vm.label = 'Off one'
		wrapper.vm.cadenceOption = cadence(wrapper, 'daily')
		wrapper.vm.syncId = 'abc'
		expect(wrapper.vm.candidateEntry.enabled).toBe(false)
	})

	it('save is gated on validity — invalid entries do not emit', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		// no cadence, no sync id
		wrapper.vm.label = 'Incomplete'
		expect(wrapper.vm.valid).toBe(false)
		wrapper.vm.onSave()
		expect(wrapper.emitted().save).toBeFalsy()
	})

	it('a valid entry emits save and closes', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.label = 'Nightly'
		wrapper.vm.cadenceOption = cadence(wrapper, 'daily')
		wrapper.vm.syncId = 'abc'
		wrapper.vm.onSave()
		const emitted = wrapper.emitted().save[0][0]
		expect(emitted.id).toBe('nightly')
		expect(emitted.interval).toBe(86400)
		expect(wrapper.emitted()['update:open'].pop()).toEqual([false])
	})

	it('degrades to a free-text sync id field when the list cannot load', async () => {
		axios.get.mockRejectedValueOnce(new Error('network'))
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.actionOption = wrapper.vm.actionOptions[0]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.syncFetchFailed).toBe(true)
		expect(wrapper.vm.syncPickerAvailable).toBe(false)
		expect(wrapper.find('.ob-schedule-edit__sync-manual').exists()).toBe(true)
	})

	it('populates the sync picker when the list loads', async () => {
		axios.get.mockResolvedValueOnce({
			data: { results: [{ id: 's1', name: 'BRP sync' }] },
		})
		const wrapper = factory()
		await openDialog(wrapper)
		expect(wrapper.vm.syncPickerAvailable).toBe(true)
		expect(wrapper.vm.syncOptions).toEqual([{ id: 's1', label: 'BRP sync' }])
	})

	it('enforces id uniqueness against other entries', async () => {
		const wrapper = factory({ existingIds: ['nightly'] })
		await openDialog(wrapper)
		wrapper.vm.label = 'Nightly'
		wrapper.vm.cadenceOption = cadence(wrapper, 'daily')
		wrapper.vm.syncId = 'abc'
		// slug auto-suffixes to nightly-2, which is unique → valid
		expect(wrapper.vm.derivedId).toBe('nightly-2')
		expect(wrapper.vm.valid).toBe(true)
	})

	it('reverse-maps an existing entry: preset interval selects its preset', async () => {
		const entry = {
			id: 'weekly-one',
			enabled: true,
			interval: 604800,
			action: 'openconnector:synchronization',
			arguments: { synchronizationId: 'abc' },
		}
		const wrapper = factory({ entry })
		await openDialog(wrapper)
		expect(wrapper.vm.cadenceOption.id).toBe('weekly')
		expect(wrapper.vm.candidateEntry.id).toBe('weekly-one')
	})

	it('reverse-maps a non-preset interval to the custom-interval escape hatch', async () => {
		const entry = {
			id: 'odd-one',
			enabled: true,
			interval: 43200,
			action: 'openconnector:synchronization',
			arguments: { synchronizationId: 'abc' },
		}
		const wrapper = factory({ entry })
		await openDialog(wrapper)
		expect(wrapper.vm.cadenceOption.id).toBe('custom-interval')
		expect(wrapper.vm.intervalSeconds).toBe('43200')
		expect(wrapper.vm.candidateEntry.interval).toBe(43200)
	})

	it('reverse-maps a cron entry to custom-cron', async () => {
		const entry = {
			id: 'cron-one',
			enabled: false,
			cron: '0 3 * * 1',
			action: 'openconnector:synchronization',
			arguments: { synchronizationId: 'abc' },
		}
		const wrapper = factory({ entry })
		await openDialog(wrapper)
		expect(wrapper.vm.cadenceOption.id).toBe('custom-cron')
		expect(wrapper.vm.cron).toBe('0 3 * * 1')
		expect(wrapper.vm.enabled).toBe(false)
	})
})
