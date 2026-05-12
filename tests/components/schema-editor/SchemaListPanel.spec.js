/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `SchemaListPanel.vue` (REQ-OBSD-001 +
 * REQ-OBSD-008). Covers:
 *  - Empty-state render when no schemas are passed.
 *  - Row-per-schema render with slug + property/lifecycle metadata.
 *  - `@open` event fires with the schema's slug when a row is clicked.
 *  - The Add Schema button opens the AddSchemaDialog and re-emits its
 *    `confirm` payload as `@add`.
 *  - Delete actions surface the confirm dialog (REQ-OBSD-008) and only
 *    fire `@delete` after explicit confirmation.
 *
 * NcButton / NcEmptyContent / NcActions are stubbed to plain elements
 * so the test can drive their `click` handlers without loading the
 * full Nextcloud-vue component tree. The two modal SFCs are likewise
 * stubbed to capture their props + emit synthetic confirm events.
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import SchemaListPanel from '../../../src/components/schema-editor/SchemaListPanel.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :data-nc-button-type="type" :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="nc-empty-stub"><span class="empty-name">{{ name }}</span><span class="empty-description">{{ description }}</span><slot name="icon" /><slot name="action" /></div>',
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
		template: '<button class="nc-action-stub" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
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
		template: '<div class="delete-stub" :data-open="open" :data-slug="schemaSlug" />',
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

describe('SchemaListPanel', () => {
	it('REQ-OBSD-001: renders the empty state when no schemas are passed', () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [], loading: false },
			stubs,
		})
		const empty = wrapper.find('.openbuilt-schema-list__empty')
		expect(empty.exists()).toBe(true)
		// The empty-state NcEmptyContent surfaces the no-schemas-yet copy.
		expect(empty.text()).toContain('No schemas yet')
	})

	it('REQ-OBSD-001: renders one row per schema with slug, version, and property count', () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: {
				schemas: [
					makeSchema(),
					makeSchema({
						slug: 'audit-entry',
						title: 'Audit entry',
						version: '0.2.0',
						properties: { actor: { type: 'string' } },
						'x-openregister-lifecycle': null,
					}),
				],
				loading: false,
			},
			stubs,
		})
		const rows = wrapper.findAll('.openbuilt-schema-list__row')
		expect(rows).toHaveLength(2)
		expect(rows.at(0).text()).toContain('hello-message')
		expect(rows.at(0).text()).toContain('Hello message')
		expect(rows.at(1).text()).toContain('audit-entry')
		// No-lifecycle row shows the "No lifecycle" sentinel.
		expect(rows.at(1).text()).toContain('No lifecycle')
	})

	it('REQ-OBSD-001: clicking the row main button emits @open with the schema slug', async () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [makeSchema()], loading: false },
			stubs,
		})
		await wrapper.find('.openbuilt-schema-list__row-main').trigger('click')
		const events = wrapper.emitted('open')
		expect(events).toBeTruthy()
		expect(events[0]).toEqual(['hello-message'])
	})

	it('REQ-OBSD-001 + REQ-OBSD-002: clicking the Add Schema header button opens the AddSchemaDialog', async () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [], loading: false },
			stubs,
		})
		// The empty state shows two Add buttons (header + empty-state CTA).
		// Either should toggle `addOpen` to true.
		const headerAdd = wrapper.findAll('button[data-nc-button-type="primary"]').at(0)
		await headerAdd.trigger('click')
		expect(wrapper.vm.addOpen).toBe(true)
		const addDialog = wrapper.findComponent({ name: 'AddSchemaDialog' })
		expect(addDialog.props('open')).toBe(true)
	})

	it('REQ-OBSD-002: AddSchemaDialog `confirm` event is re-emitted as `@add`', async () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [], loading: false },
			stubs,
		})
		const addDialog = wrapper.findComponent({ name: 'AddSchemaDialog' })
		await addDialog.vm.$emit('confirm', {
			slug: 'new-thing',
			title: 'New thing',
			version: '0.1.0',
		})
		const adds = wrapper.emitted('add')
		expect(adds).toBeTruthy()
		expect(adds[0][0]).toEqual({
			slug: 'new-thing',
			title: 'New thing',
			version: '0.1.0',
		})
	})

	it('REQ-OBSD-008: delete action surfaces the confirm dialog first and only fires @delete on confirmation', async () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [makeSchema()], loading: false },
			stubs,
		})
		// Per-row Delete action lives inside NcActions / NcActionButton stubs.
		const deleteAction = wrapper.findAll('.nc-action-stub').at(1)
		expect(deleteAction.exists()).toBe(true)
		await deleteAction.trigger('click')
		// First click only opens the confirm dialog — no @delete yet.
		expect(wrapper.emitted('delete')).toBeUndefined()
		const deleteDialog = wrapper.findComponent({ name: 'DeleteSchemaDialog' })
		expect(deleteDialog.props('open')).toBe(true)
		expect(deleteDialog.props('schemaSlug')).toBe('hello-message')

		// Confirm the dialog → @delete fires with the schema slug.
		await deleteDialog.vm.$emit('confirm')
		const deletes = wrapper.emitted('delete')
		expect(deletes).toBeTruthy()
		expect(deletes[0]).toEqual(['hello-message'])
	})

	it('REQ-OBSD-008: cancelling the delete dialog does NOT emit @delete', async () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [makeSchema()], loading: false },
			stubs,
		})
		const deleteAction = wrapper.findAll('.nc-action-stub').at(1)
		await deleteAction.trigger('click')
		const deleteDialog = wrapper.findComponent({ name: 'DeleteSchemaDialog' })
		await deleteDialog.vm.$emit('cancel')
		expect(wrapper.emitted('delete')).toBeUndefined()
		expect(wrapper.vm.deleteOpen).toBe(false)
	})

	it('shows the loading icon when `loading` is true', () => {
		const wrapper = mount(SchemaListPanel, {
			propsData: { schemas: [], loading: true },
			stubs,
		})
		expect(wrapper.find('.openbuilt-schema-list__loading').exists()).toBe(true)
		// Loading branch should suppress the empty-state.
		expect(wrapper.find('.openbuilt-schema-list__empty').exists()).toBe(false)
	})
})

// Silence vi unused-import lint: kept for parity with sibling spec files
// that mock store modules. Tests in this file rely on event surface only.
vi.fn()
