/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/DeleteAppDialog.vue` — the owner
 * confirmation modal for deleting an app.
 *
 * The dialog owns exactly one piece of state: the opt-in `deleteData`
 * toggle that decides whether the app's registers and their data are wiped
 * as well. Because that toggle gates a destructive, irreversible data purge,
 * these tests pin down its safe-by-default contract:
 *   - the "also delete all data" checkbox is unchecked on open
 *   - a previous "delete data" choice never carries over: re-opening resets it
 *   - Cancel closes the dialog and NEVER emits `confirm` (no accidental delete)
 *   - Delete emits `confirm` with the current toggle value (false by default,
 *     true only after the owner explicitly ticks the box)
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

const baseStubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'noClose'],
		template: '<div class="nc-dialog-stub"><slot /><div class="nc-dialog-actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'disabled'],
		template: '<label class="nc-checkbox-stub"><input type="checkbox" :checked="checked" :disabled="disabled" @change="$emit(\'update:checked\', $event.target.checked)"><slot /></label>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="nc-loading-stub" />' },
}

const DeleteAppDialog = (await import('../../src/dialogs/DeleteAppDialog.vue')).default

function mountDialog(propsData = {}) {
	return mount(DeleteAppDialog, {
		propsData: { open: true, appName: 'Demo App', busy: false, ...propsData },
		stubs: baseStubs,
	})
}

// The single checkbox input inside the stubbed NcCheckboxRadioSwitch.
function checkbox(wrapper) {
	return wrapper.find('input[type="checkbox"]')
}

// Cancel is the first action button, Delete the second (type="error").
function deleteButton(wrapper) {
	return wrapper.find('button[data-type="error"]')
}
function cancelButton(wrapper) {
	return wrapper.findAll('button').at(0)
}

describe('DeleteAppDialog — destructive-data opt-in contract', () => {
	it('renders the confirmation copy with the app name', () => {
		const wrapper = mountDialog({ appName: 'My Store' })
		expect(wrapper.text()).toContain('Delete "{name}" and all of its versions? This cannot be undone.')
	})

	it('defaults the "also delete all data" checkbox to unchecked on open', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.deleteData).toBe(false)
		expect(checkbox(wrapper).element.checked).toBe(false)
	})

	it('resets a previously ticked "delete data" choice when the dialog is re-opened', async () => {
		const wrapper = mountDialog({ open: true })

		// Owner ticks the destructive-data box.
		await checkbox(wrapper).setChecked(true)
		expect(wrapper.vm.deleteData).toBe(true)

		// Dialog closes, then re-opens for a different (or the same) app.
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.deleteData).toBe(false)
		expect(checkbox(wrapper).element.checked).toBe(false)
	})

	it('Cancel closes the dialog and never emits confirm', async () => {
		const wrapper = mountDialog()

		// Even with the destructive box ticked, Cancel must not delete anything.
		await checkbox(wrapper).setChecked(true)
		await cancelButton(wrapper).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('Delete emits confirm(false) on the default (data-preserving) path', async () => {
		const wrapper = mountDialog()
		await deleteButton(wrapper).trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[false]])
	})

	it('Delete emits confirm(true) only after the box is explicitly ticked', async () => {
		const wrapper = mountDialog()
		await checkbox(wrapper).setChecked(true)
		await deleteButton(wrapper).trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[true]])
	})

	it('disables the actions and shows a progress label while a delete is in flight', () => {
		const wrapper = mountDialog({ busy: true })
		expect(wrapper.text()).toContain('Deleting…')
		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()
		expect(cancelButton(wrapper).attributes('disabled')).toBeDefined()
	})
})
