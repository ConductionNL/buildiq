/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AddSchemaDialog — the new-schema header form.
 *
 * Covers:
 *  - validity: a lowercase slug starting with a letter, a non-blank title
 *    and a semver version.
 *  - confirm emits a copy of the form only when valid.
 *  - input merges into the form; re-opening resets it.
 *  - cancel and closing the dialog cancel.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AddSchemaDialog from '../../src/modals/AddSchemaDialog.vue'

function mountDialog(props = {}) {
	return shallowMount(AddSchemaDialog, { props: { open: false, ...props } })
}

describe('AddSchemaDialog', () => {
	it('starts empty at version 0.1.0 and invalid', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.local).toEqual({ slug: '', title: '', description: '', version: '0.1.0' })
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('is valid with a slug, a title and a semver version', () => {
		const wrapper = mountDialog()
		wrapper.vm.onInput({ slug: 'case-file', title: 'Case file' })
		expect(wrapper.vm.isValid).toBe(true)
		expect(wrapper.vm.local.description).toBe('')
	})

	it.each([
		[{ slug: 'Case' }, 'an uppercase slug'],
		[{ slug: '1case' }, 'a slug starting with a digit'],
		[{ slug: 'case_file' }, 'a slug with an underscore'],
		[{ title: '   ' }, 'a blank title'],
		[{ version: '1.0' }, 'a two-part version'],
		[{ version: 'v1.0.0' }, 'a prefixed version'],
	])('is invalid with %o (%s)', (patch) => {
		const wrapper = mountDialog()
		wrapper.vm.onInput({ slug: 'case', title: 'Case', ...patch })
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('does not confirm an invalid form', () => {
		const wrapper = mountDialog()
		wrapper.vm.onConfirm()
		expect(wrapper.emitted('confirm')).toBeFalsy()
	})

	it('confirms with a copy of the form', () => {
		const wrapper = mountDialog()
		wrapper.vm.onInput({ slug: 'case', title: 'Case', description: 'Cases', version: '1.2.3' })
		wrapper.vm.onConfirm()
		const [payload] = wrapper.emitted('confirm')[0]
		expect(payload).toEqual({ slug: 'case', title: 'Case', description: 'Cases', version: '1.2.3' })
		expect(payload).not.toBe(wrapper.vm.local)
	})

	it('resets the form when opened again', async () => {
		const wrapper = mountDialog()
		wrapper.vm.onInput({ slug: 'case', title: 'Case', version: '2.0.0' })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.local).toEqual({ slug: '', title: '', description: '', version: '0.1.0' })
	})

	it('keeps the form when closed', async () => {
		const wrapper = mountDialog({ open: true })
		wrapper.vm.onInput({ slug: 'case' })
		await wrapper.setProps({ open: false })
		expect(wrapper.vm.local.slug).toBe('case')
	})

	it('cancel and an outside close cancel', () => {
		const wrapper = mountDialog({ open: true })
		wrapper.vm.onCancel()
		wrapper.vm.onOpenUpdate(true)
		wrapper.vm.onOpenUpdate(false)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(wrapper.emitted('update:open')).toEqual([[true], [false]])
	})
})
