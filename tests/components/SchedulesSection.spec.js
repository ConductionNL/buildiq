/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SchedulesSection.vue.
 *
 * Spec: schedules-editor / openbuild-schedules-authoring
 * (REQ-OBSA-001, REQ-OBSA-005, REQ-OBSA-007).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SchedulesSection from '../../src/components/SchedulesSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const stubs = {
	NcButton: NcButtonStub,
	ScheduleEditDialog: { name: 'ScheduleEditDialog', template: '<div class="dialog-stub" />' },
}

const entry = {
	id: 'nightly-brp-sync',
	enabled: true,
	interval: 86400,
	action: 'openconnector:synchronization',
	arguments: { synchronizationId: '00000000-0000-0000-0000-000000000000' },
}

const factory = (manifest) => mount(SchedulesSection, {
	propsData: { manifest },
	stubs,
})

describe('SchedulesSection', () => {
	it('renders the empty state with no schedules', () => {
		const wrapper = factory({})
		expect(wrapper.find('.ob-schedules-section__empty').exists()).toBe(true)
	})

	it('renders the empty state for an empty schedules array', () => {
		const wrapper = factory({ schedules: [] })
		expect(wrapper.find('.ob-schedules-section__empty').exists()).toBe(true)
	})

	it('lists existing schedules with id + cadence summary', () => {
		const wrapper = factory({ schedules: [entry] })
		expect(wrapper.findAll('.ob-schedules-section__item')).toHaveLength(1)
		expect(wrapper.text()).toContain('nightly-brp-sync')
		expect(wrapper.text()).toContain('Daily')
	})

	it('summarizes a cron cadence', () => {
		const cronEntry = { ...entry, id: 'weekly', interval: undefined, cron: '0 3 * * 1' }
		delete cronEntry.interval
		const wrapper = factory({ schedules: [cronEntry] })
		// the test t() stub does not interpolate, but the raw key contains "Cron"
		expect(wrapper.text()).toContain('Cron')
	})

	it('adding a schedule emits an updated manifest with schedules[]', () => {
		const wrapper = factory({})
		wrapper.vm.openAdd()
		wrapper.vm.onDialogSave(entry)
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.schedules).toHaveLength(1)
		expect(emitted.schedules[0].id).toBe('nightly-brp-sync')
	})

	it('editing a schedule updates it in place preserving the id', () => {
		const wrapper = factory({ schedules: [entry] })
		wrapper.vm.openEdit(entry)
		wrapper.vm.onDialogSave({ ...entry, interval: 604800 })
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.schedules).toHaveLength(1)
		expect(emitted.schedules[0].id).toBe('nightly-brp-sync')
		expect(emitted.schedules[0].interval).toBe(604800)
	})

	it('removing a schedule asks first and emits nothing until confirmed', () => {
		const wrapper = factory({ schedules: [entry] })
		wrapper.vm.remove(entry)
		expect(wrapper.vm.confirmRemoveOpen).toBe(true)
		expect(wrapper.emitted()['update:manifest']).toBeUndefined()
	})

	it('removing a schedule deletes it once confirmed and drops the empty key', () => {
		const wrapper = factory({ schedules: [entry] })
		wrapper.vm.remove(entry)
		wrapper.vm.onConfirmRemove()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.schedules).toBeUndefined()
	})

	it('excludes the edited entry id from the dialog uniqueness list', () => {
		const wrapper = factory({ schedules: [entry] })
		wrapper.vm.openEdit(entry)
		expect(wrapper.vm.otherIds).not.toContain('nightly-brp-sync')
	})

	it('keeps other top-level manifest keys unchanged on save', () => {
		const wrapper = factory({ pages: [{ id: 'p1' }], theme: { source: 'nldesign' } })
		wrapper.vm.onDialogSave(entry)
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.pages).toEqual([{ id: 'p1' }])
		expect(emitted.theme).toEqual({ source: 'nldesign' })
	})
})
