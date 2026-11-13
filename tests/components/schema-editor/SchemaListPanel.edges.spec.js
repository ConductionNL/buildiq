/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the edges of `SchemaListPanel.vue` the main spec
 * does not reach: the empty state's Add button, the row action Open, the
 * dialogs closing through cancel and update:open, slug and count
 * fallbacks, and the add errors (a 409 slug clash, a thrown message, and a
 * generic failure) shown in the add dialog.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SchemaListPanel from '../../../src/components/schema-editor/SchemaListPanel.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['type', 'variant', 'disabled'],
		template:
			'<button :data-nc-button-type="variant || type" :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template:
			'<div class="nc-empty-stub"><span class="empty-name">{{ name }}</span><span class="empty-description">{{ description }}</span><slot name="icon" /><slot name="action" /></div>',
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		template: '<div class="nc-loading-stub" />',
	},
	NcActions: {
		name: 'NcActions',
		template: '<div class="nc-actions-stub"><slot /></div>',
	},
	NcActionButton: {
		name: 'NcActionButton',
		template:
			'<button class="nc-action-stub" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	// Capture modal props so we can drive their `confirm` event.
	AddSchemaDialog: {
		name: 'AddSchemaDialog',
		props: ['open', 'submitting', 'slugError'],
		template: '<div class="add-stub" :data-open="open" />',
	},
	DeleteSchemaDialog: {
		name: 'DeleteSchemaDialog',
		props: ['open', 'schemaSlug'],
		template:
			'<div class="delete-stub" :data-open="open" :data-slug="schemaSlug" />',
	},
}

function makeSchema(overrides = {}) {
	return {
		slug: 'hello-message',
		title: 'Hello message',
		version: '1.0.0',
		properties: {
			subject: { type: 'string' },
			body: { type: 'string' },
		},
		'x-openregister-lifecycle': {
			initial: 'draft',
			states: [{ name: 'draft' }, { name: 'published' }],
		},
		...overrides,
	}
}

function mountPanel(props = {}) {
	return mount(SchemaListPanel, { props: { schemas: [], ...props }, global: { stubs } })
}

describe('SchemaListPanel — dialogs', () => {
	it('opens the add dialog from the empty state', async () => {
		const wrapper = mountPanel()
		await wrapper.find('.nc-empty-stub button').trigger('click')
		expect(wrapper.findComponent({ name: 'AddSchemaDialog' }).props('open')).toBe(true)
	})

	it('closes the add dialog on cancel and on update:open', async () => {
		const wrapper = mountPanel()
		const dialog = wrapper.findComponent({ name: 'AddSchemaDialog' })
		wrapper.vm.addOpen = true
		await dialog.vm.$emit('cancel')
		expect(wrapper.vm.addOpen).toBe(false)
		await dialog.vm.$emit('update:open', true)
		expect(wrapper.vm.addOpen).toBe(true)
		await dialog.vm.$emit('update:open', false)
		expect(wrapper.vm.addOpen).toBe(false)
	})

	it('follows update:open from the delete dialog', async () => {
		const wrapper = mountPanel({ schemas: [makeSchema()] })
		const dialog = wrapper.findComponent({ name: 'DeleteSchemaDialog' })
		await dialog.vm.$emit('update:open', true)
		expect(wrapper.vm.deleteOpen).toBe(true)
		await dialog.vm.$emit('update:open', false)
		expect(wrapper.vm.deleteOpen).toBe(false)
	})

	it('opens a schema from its Open action', async () => {
		const wrapper = mountPanel({ schemas: [makeSchema({ slug: 'invoice' })] })
		await wrapper.findAll('.nc-action-stub')[0].trigger('click')
		// The action stub forwards both its emit and the native click.
		expect(wrapper.emitted('open').at(-1)).toEqual(['invoice'])
	})
})

describe('SchemaListPanel — fallbacks', () => {
	it('takes the slug from @self, then the id, then nothing', () => {
		const { vm } = mountPanel()
		expect(vm.getSlug({ '@self': { slug: 'from-self' }, id: 7 })).toBe('from-self')
		expect(vm.getSlug({ '@self': {}, id: 7 })).toBe(7)
		expect(vm.getSlug({})).toBe('')
	})

	it('counts no properties for a schema without them', () => {
		const { vm } = mountPanel()
		expect(vm.propertyCount({})).toBe(0)
		expect(vm.propertyCount(null)).toBe(0)
		expect(vm.propertyCount({ properties: { a: {}, b: {}, c: {} } })).toBe(3)
	})

	it('falls back to the slug as the row title', () => {
		const wrapper = mountPanel({ schemas: [makeSchema({ title: '', version: '' })] })
		expect(wrapper.find('.buildiq-schema-list__row-title').text()).toBe('hello-message')
	})
})

describe('SchemaListPanel — add errors', () => {
	/**
	 * Run onAddConfirm on a fake instance whose $emit throws `error`.
	 *
	 * @param {*} error What the parent throws.
	 * @return {Promise<object>} The instance afterwards.
	 */
	async function addFailingWith(error) {
		const vm = {
			addOpen: true,
			addSubmitting: false,
			addSlugError: 'old',
			t: (_app, text) => text,
			$emit: () => {
				throw error
			},
		}
		await SchemaListPanel.methods.onAddConfirm.call(vm, { slug: 'x' })
		return vm
	}

	it('says the slug is taken on a 409, and keeps the dialog open', async () => {
		const vm = await addFailingWith({ status: 409 })
		expect(vm.addSlugError).toBe('A schema with this slug already exists in this app.')
		expect(vm.addOpen).toBe(true)
		expect(vm.addSubmitting).toBe(false)
	})

	it('shows the error message of any other failure', async () => {
		const vm = await addFailingWith(new Error('Register is read-only'))
		expect(vm.addSlugError).toBe('Register is read-only')
	})

	it('shows a generic message when the failure has none', async () => {
		expect((await addFailingWith({ status: 500 })).addSlugError).toBe('Failed to add schema.')
		expect((await addFailingWith(null)).addSlugError).toBe('Failed to add schema.')
	})

	it('clears the error and closes the dialog when adding succeeds', async () => {
		const vm = { addOpen: true, addSubmitting: false, addSlugError: 'old', t: (_a, s) => s, $emit: vi.fn(() => 'ok') }
		const result = await SchemaListPanel.methods.onAddConfirm.call(vm, { slug: 'x' })
		expect(vm.$emit).toHaveBeenCalledWith('add', { slug: 'x' })
		expect(result).toBe('ok')
		expect(vm.addSlugError).toBe('')
		expect(vm.addOpen).toBe(false)
		expect(vm.addSubmitting).toBe(false)
	})
})
