/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SchedulesSection.vue: the Add / Edit / Remove buttons and
 * the dialogs' events, driven through the rendered children, plus the
 * summaries for an unusual interval, another action and a missing sync.
 *
 * Spec: buildiq-schedules-authoring (REQ-OBSA-001, REQ-OBSA-005).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SchedulesSection from '../../src/components/SchedulesSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant'],
	emits: ['click'],
	template: '<button @click="$emit(\'click\')"><slot /></button>',
}

const ScheduleEditDialogStub = {
	name: 'ScheduleEditDialog',
	props: ['open', 'entry', 'existingIds'],
	emits: ['update:open', 'save'],
	template: '<div class="dialog-stub" />',
}

const ConfirmActionDialogStub = {
	name: 'ConfirmActionDialog',
	props: ['open', 'name', 'message', 'confirmLabel', 'destructive'],
	emits: ['update:open', 'confirm'],
	template: '<div class="confirm-stub" />',
}

const nightly = {
	id: 'nightly',
	interval: 86400,
	action: 'openconnector:synchronization',
	arguments: { synchronizationId: 'sync-1' },
}

const odd = { id: 'odd', interval: 900, action: 'custom:job', enabled: false }

/**
 * Mount the section.
 *
 * @param {object} manifest - the manifest.
 * @return {object} The wrapper.
 */
function factory(manifest) {
	return mount(SchedulesSection, {
		props: { manifest },
		global: {
			stubs: {
				NcButton: NcButtonStub,
				ScheduleEditDialog: ScheduleEditDialogStub,
				ConfirmActionDialog: ConfirmActionDialogStub,
			},
		},
	})
}

/**
 * A button by its text.
 *
 * @param {object} wrapper - the wrapper.
 * @param {string} text - the label.
 * @param {number} nth - which match.
 * @return {object} The button.
 */
function button(wrapper, text, nth = 0) {
	return wrapper.findAll('button').filter((b) => b.text() === text)[nth]
}

describe('SchedulesSection actions', () => {
	it('summarizes an unusual interval, another action, no synchronization and a disabled task', () => {
		const wrapper = factory({ schedules: [odd, { id: 'bare' }] })
		const items = wrapper.findAll('.ob-schedules-section__item')

		expect(items.at(0).text()).toContain('Every {seconds}s')
		expect(items.at(0).text()).toContain('custom:job')
		expect(items.at(0).text()).toContain('No synchronization')
		expect(items.at(0).find('.ob-schedules-section__enabled--off').text()).toBe('Disabled')
		expect(wrapper.vm.cadenceSummary(odd)).toBe('Every {seconds}s')
		expect(items.at(1).text()).toContain('No cadence')
		expect(items.at(1).text()).toContain('No action')
	})

	it('Add opens the dialog for a new task, and its save appends it', async () => {
		const wrapper = factory({ schedules: [nightly], pages: [] })
		const dialog = wrapper.findComponent(ScheduleEditDialogStub)

		await button(wrapper, 'Add scheduled task').trigger('click')
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('entry')).toBeNull()
		expect(dialog.props('existingIds')).toEqual(['nightly'])

		dialog.vm.$emit('save', odd)
		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({ schedules: [nightly, odd], pages: [] })
	})

	it('Edit opens the dialog on that task, and its save replaces it in place', async () => {
		const wrapper = factory({ schedules: [nightly, odd] })
		const dialog = wrapper.findComponent(ScheduleEditDialogStub)

		await button(wrapper, 'Edit', 1).trigger('click')
		expect(dialog.props('entry')).toEqual(odd)
		expect(dialog.props('existingIds')).toEqual(['nightly'])

		const changed = { ...odd, interval: 3600 }
		dialog.vm.$emit('save', changed)
		expect(wrapper.emitted('update:manifest')[0][0].schedules).toEqual([nightly, changed])
	})

	it('Remove asks first; confirming drops only that task', async () => {
		const wrapper = factory({ schedules: [nightly, odd] })
		const confirm = wrapper.findComponent(ConfirmActionDialogStub)

		await button(wrapper, 'Remove', 0).trigger('click')
		expect(confirm.props('open')).toBe(true)
		expect(wrapper.emitted('update:manifest')).toBeUndefined()

		confirm.vm.$emit('confirm')
		expect(wrapper.emitted('update:manifest')[0][0].schedules).toEqual([odd])
		expect(wrapper.vm.pendingRemoval).toBeNull()
	})

	it('a confirm with nothing pending changes nothing', () => {
		const wrapper = factory({ schedules: [nightly] })

		wrapper.findComponent(ConfirmActionDialogStub).vm.$emit('confirm')

		expect(wrapper.emitted('update:manifest')).toBeUndefined()
		expect(wrapper.vm.confirmRemoveOpen).toBe(false)
	})
})
