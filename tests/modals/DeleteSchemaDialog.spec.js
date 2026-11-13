/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DeleteSchemaDialog — typing the slug to confirm a delete.
 *
 * Covers:
 *  - delete is allowed only once the exact slug is typed, never for an empty slug.
 *  - confirm emits only when allowed and clears the typed text.
 *  - cancel and closing the dialog clear the text and emit cancel.
 *  - closing through the open prop clears the text.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DeleteSchemaDialog from '../../src/modals/DeleteSchemaDialog.vue'

function mountDialog(props = {}) {
	return shallowMount(DeleteSchemaDialog, { props: { open: true, schemaSlug: 'case', ...props } })
}

describe('DeleteSchemaDialog', () => {
	it('allows delete only once the exact slug is typed', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.canDelete).toBe(false)
		wrapper.vm.typed = 'cas'
		expect(wrapper.vm.canDelete).toBe(false)
		wrapper.vm.typed = 'Case'
		expect(wrapper.vm.canDelete).toBe(false)
		wrapper.vm.typed = 'case'
		expect(wrapper.vm.canDelete).toBe(true)
	})

	it('never allows delete for an empty slug', () => {
		const wrapper = mountDialog({ schemaSlug: '' })
		wrapper.vm.typed = ''
		expect(wrapper.vm.canDelete).toBe(false)
	})

	it('does not confirm before the slug is typed', () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'wrong'
		wrapper.vm.onConfirm()
		expect(wrapper.emitted('confirm')).toBeFalsy()
		expect(wrapper.vm.typed).toBe('wrong')
	})

	it('confirms and clears the text once the slug matches', () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'case'
		wrapper.vm.onConfirm()
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.vm.typed).toBe('')
	})

	it('cancel clears the text', () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'ca'
		wrapper.vm.onCancel()
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.vm.typed).toBe('')
	})

	it('closing the dialog forwards it, clears the text and cancels', () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'case'
		wrapper.vm.onOpenUpdate(false)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.vm.typed).toBe('')
	})

	it('re-opening forwards without cancelling', () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'ca'
		wrapper.vm.onOpenUpdate(true)
		expect(wrapper.emitted('update:open')).toEqual([[true]])
		expect(wrapper.emitted('cancel')).toBeFalsy()
		expect(wrapper.vm.typed).toBe('ca')
	})

	it('clears the text when the parent closes it', async () => {
		const wrapper = mountDialog()
		wrapper.vm.typed = 'case'
		await wrapper.setProps({ open: false })
		expect(wrapper.vm.typed).toBe('')
	})
})
