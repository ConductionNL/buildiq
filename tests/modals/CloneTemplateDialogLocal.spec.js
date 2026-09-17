/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CloneTemplateDialog for a built-in template (store-shows-built-in-templates):
 * the slug follows the name until the user edits it, the dialog offers an
 * optional description, and the description is not offered for a GitHub app.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

const stubs = {
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template:
			'<input class="field" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

function mountDialog(props = {}) {
	return mount(CloneTemplateDialog, {
		props: {
			open: false,
			template: { slug: 'permit-tracker', title: 'Permit Tracker' },
			...props,
		},
		global: { stubs },
	})
}

async function openDialog(wrapper) {
	await wrapper.setProps({ open: true })
	await wrapper.vm.$nextTick()
}

function field(wrapper, label) {
	return wrapper
		.findAll('input.field')
		.find((f) => f.attributes('data-label') === label)
}

describe('CloneTemplateDialog for a built-in template', () => {
	it('lets the slug follow the name', async () => {
		const wrapper = mountDialog()
		await openDialog(wrapper)

		await field(wrapper, 'Application name').setValue('Bouwvergunningen Noord')

		expect(field(wrapper, 'Slug (kebab-case, max 32 chars)').element.value).toBe(
			'bouwvergunningen-noord',
		)
	})

	it('keeps a slug the user typed when the name changes again', async () => {
		const wrapper = mountDialog()
		await openDialog(wrapper)

		await field(wrapper, 'Slug (kebab-case, max 32 chars)').setValue(
			'permits-north',
		)
		await field(wrapper, 'Application name').setValue('Something else')

		expect(field(wrapper, 'Slug (kebab-case, max 32 chars)').element.value).toBe(
			'permits-north',
		)
	})

	it('offers a description and submits it with the name and slug', async () => {
		const wrapper = mountDialog()
		await openDialog(wrapper)

		await field(wrapper, 'Application name').setValue('My permits')
		await field(wrapper, 'Description (optional)').setValue(
			'  Permits for the north district  ',
		)
		await wrapper.vm.submit()

		expect(wrapper.emitted('submit')[0][0]).toEqual({
			name: 'My permits',
			slug: 'my-permits',
			description: 'Permits for the north district',
		})
	})

	it('labels the primary action Create, as the tutorial does', async () => {
		const wrapper = mountDialog()
		await openDialog(wrapper)

		const labels = wrapper.findAll('button').map((b) => b.text())
		expect(labels).toContain('Create')
	})

	it('starts each opening with a slug that follows the name again', async () => {
		const wrapper = mountDialog()
		await openDialog(wrapper)
		await field(wrapper, 'Slug (kebab-case, max 32 chars)').setValue(
			'typed-slug',
		)

		await wrapper.setProps({ open: false })
		await openDialog(wrapper)
		await field(wrapper, 'Application name').setValue('Fresh name')

		expect(field(wrapper, 'Slug (kebab-case, max 32 chars)').element.value).toBe(
			'fresh-name',
		)
	})

	it('offers no description for a GitHub app', async () => {
		const wrapper = mountDialog({
			github: true,
			githubRepo: { owner: 'o', repo: 'r' },
		})
		await openDialog(wrapper)

		expect(field(wrapper, 'Description (optional)')).toBeUndefined()
	})
})
