import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: ScheduleEditDialog numbers a slug that is already taken, takes the
 * typed label and the picked synchronization, and closes on request
 * (spec openbuild-schedules-authoring REQ-OBSA-001/003/004).
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: { results: [] } })) },
}))

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

function factory(propsData = {}) {
	return mount(ScheduleEditDialog, {
		propsData: { open: false, ...propsData },
		stubs,
	})
}

/** Open the dialog (fires the watcher → hydrate + fetch). */
async function openDialog(wrapper) {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

describe('ScheduleEditDialog slugs and pickers', () => {
	it('numbers a new schedule slug past every one already taken', async () => {
		const wrapper = factory({ existingIds: ['nightly-sync', 'nightly-sync-2'] })
		await openDialog(wrapper)

		wrapper.vm.onLabelInput('Nightly sync')
		expect(wrapper.vm.label).toBe('Nightly sync')
		expect(wrapper.vm.derivedId).toBe('nightly-sync-3')

		wrapper.vm.onLabelInput('Weekly report')
		expect(wrapper.vm.derivedId).toBe('weekly-report')
	})

	it('takes the picked synchronization, and clears it on an empty pick', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.onSyncSelect({ id: 'sync-7', label: 'BRP' })
		expect(wrapper.vm.syncId).toBe('sync-7')

		wrapper.vm.onSyncSelect(null)
		expect(wrapper.vm.syncId).toBe('')

		wrapper.vm.onSyncSelect({ label: 'no id' })
		expect(wrapper.vm.syncId).toBe('')
	})

	it('closes on request without saving', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.onClose()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})
