/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DeleteFieldDialog — confirming a property delete.
 *
 * Covers:
 *  - confirm and cancel emit their event.
 *  - closing the dialog forwards the open state and cancels; opening does not.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DeleteFieldDialog from '../../src/modals/DeleteFieldDialog.vue'

function mountDialog() {
	return shallowMount(DeleteFieldDialog, { props: { open: true, fieldName: 'title' } })
}

describe('DeleteFieldDialog', () => {
	it('confirm emits confirm only', () => {
		const wrapper = mountDialog()
		wrapper.vm.onConfirm()
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toBeFalsy()
	})

	it('cancel emits cancel only', () => {
		const wrapper = mountDialog()
		wrapper.vm.onCancel()
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('confirm')).toBeFalsy()
	})

	it('closing forwards the state and cancels', () => {
		const wrapper = mountDialog()
		wrapper.vm.onOpenUpdate(false)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	it('opening forwards the state without cancelling', () => {
		const wrapper = mountDialog()
		wrapper.vm.onOpenUpdate(true)
		expect(wrapper.emitted('update:open')).toEqual([[true]])
		expect(wrapper.emitted('cancel')).toBeFalsy()
	})
})
