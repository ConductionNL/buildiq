/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `SchemaDesigner.vue` (REQ-OBSD-001 ..
 * REQ-OBSD-008 integration).
 *
 * The view is the top-level surface that owns the staged schema copy
 * and proxies all CRUD via `useSchemasStore`. These tests:
 *  - mock the schemas store factory (`useSchemasStore`) so the test
 *    drives `saveObject` / `deleteObject` / `fetchCollection` /
 *    `fetchObject` deterministically;
 *  - mock `@nextcloud/dialogs` so `showError` / `showSuccess` are
 *    spies the test can assert against;
 *  - stub every sub-editor (FieldEditor / LifecycleEditor / etc.) so
 *    the test focuses on the orchestration code in SchemaDesigner
 *    itself, not on the children we already cover individually.
 *
 * Covers:
 *  - List mount loads schemas via the store (`fetchCollection`).
 *  - Field edits flow through `onFieldsChange` and update `staged`.
 *  - Save calls `store.saveObject('schema', body)` with the composed
 *    JSON schema body (slug, title, properties, lifecycle, etc.).
 *  - Delete only fires when the SchemaListPanel emits a confirmed
 *    `@delete` event — the SchemaDesigner re-emits to
 *    `store.deleteObject` and refreshes the list.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// `vi.hoisted` lets us define shared mock spies that the
// `vi.mock(...)` factories can reference before the SUT is imported.
const storeMocks = vi.hoisted(() => {
	return {
		fetchCollection: vi.fn(),
		fetchObject: vi.fn(),
		saveObject: vi.fn(),
		deleteObject: vi.fn(),
		errors: {},
		objectTypeRegistry: {},
		registerObjectType: vi.fn(),
	}
})

const dialogMocks = vi.hoisted(() => {
	return {
		showError: vi.fn(),
		showSuccess: vi.fn(),
	}
})

vi.mock('../../src/store/schemas.js', () => {
	return {
		useSchemasStore: () => storeMocks,
		registerSlugForApp: (appSlug) => `openbuilt-${appSlug}`,
		STORE_ID: 'openbuilt-schemas',
	}
})

vi.mock('@nextcloud/dialogs', () => {
	return dialogMocks
})

// Import the view AFTER mocks are registered.
const { default: SchemaDesigner } = await import('../../src/views/SchemaDesigner.vue')

// Mute the sub-editor children — they each have their own spec.
const editorStubs = {
	SchemaListPanel: {
		name: 'SchemaListPanel',
		props: ['schemas', 'loading'],
		template: '<div class="schema-list-stub"><button class="emit-add" @click="$emit(\'add\', { slug: \'new\', title: \'New\', version: \'0.1.0\' })" /><button class="emit-open" @click="$emit(\'open\', \'hello\')" /><button class="emit-delete" @click="$emit(\'delete\', \'hello\')" /></div>',
	},
	SchemaHeaderForm: {
		name: 'SchemaHeaderForm',
		props: ['value', 'lockedSlug'],
		template: '<div class="schema-header-stub" />',
	},
	FieldEditor: { name: 'FieldEditor', props: ['fields', 'schemaSlugs'], template: '<div class="field-editor-stub" />' },
	LifecycleEditor: { name: 'LifecycleEditor', props: ['states', 'transitions'], template: '<div class="lifecycle-editor-stub" />' },
	RelationEditor: { name: 'RelationEditor', props: ['relations', 'schemaSlugs'], template: '<div class="relation-editor-stub" />' },
	WidgetEditor: { name: 'WidgetEditor', props: ['widgets'], template: '<div class="widget-editor-stub" />' },
	AggregationEditor: { name: 'AggregationEditor', props: ['aggregations'], template: '<div class="agg-stub" />' },
	CalculationEditor: { name: 'CalculationEditor', props: ['calculations'], template: '<div class="calc-stub" />' },
	NotificationEditor: { name: 'NotificationEditor', props: ['notifications'], template: '<div class="notif-stub" />' },
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty-stub" />',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div class="loading-stub" />' },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note-stub" :data-type="type"><slot /></div>',
	},
}

function makeRouter({ slug = 'hello-world', schemaId = '', version = undefined } = {}) {
	return {
		params: { slug, schemaId },
		query: version ? { _version: version } : {},
	}
}

// The persisted body intentionally mirrors `composeSchemaBody`'s exact
// output shape (no `required` when empty, `x-property-order` present)
// so the initial `hasStagedChanges` is false. Otherwise the round-trip
// through `bodyToStaged → composeSchemaBody` produces a structurally
// equivalent but key-ordered-different JSON string and the
// `JSON.stringify` diff in the SUT (correctly) reports a change.
const persistedSchema = {
	slug: 'hello',
	title: 'Hello',
	description: '',
	version: '0.1.0',
	type: 'object',
	properties: { subject: { type: 'string' } },
	'x-property-order': ['subject'],
}

beforeEach(() => {
	storeMocks.fetchCollection.mockReset()
	storeMocks.fetchObject.mockReset()
	storeMocks.saveObject.mockReset()
	storeMocks.deleteObject.mockReset()
	storeMocks.errors = {}
	dialogMocks.showError.mockReset()
	dialogMocks.showSuccess.mockReset()
})

describe('SchemaDesigner', () => {
	it('REQ-OBSD-001: list-mount fetches the schema collection via the store', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push: vi.fn() },
			},
		})
		// Wait for mounted() to settle.
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(storeMocks.fetchCollection).toHaveBeenCalledWith('schema')
		expect(wrapper.vm.schemas).toHaveLength(1)
		expect(wrapper.vm.schemas[0].slug).toBe('hello')
	})

	it('REQ-OBSD-001: surfaces a showError toast when the store reports a list error', async () => {
		storeMocks.fetchCollection.mockResolvedValue([])
		storeMocks.errors = { schema: 'boom' }
		mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(dialogMocks.showError).toHaveBeenCalled()
	})

	it('REQ-OBSD-002: addSchema POSTs via store.saveObject and routes to the new detail page', async () => {
		storeMocks.fetchCollection.mockResolvedValue([])
		storeMocks.saveObject.mockResolvedValue({ slug: 'new', title: 'New', version: '0.1.0' })
		const push = vi.fn()
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		// Fire the SchemaListPanel @add event.
		await wrapper.find('.emit-add').trigger('click')
		// Flush addSchema's awaits.
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(storeMocks.saveObject).toHaveBeenCalled()
		const [type, body] = storeMocks.saveObject.mock.calls[0]
		expect(type).toBe('schema')
		expect(body).toMatchObject({
			slug: 'new',
			title: 'New',
			version: '0.1.0',
			type: 'object',
			properties: {},
		})
		expect(push).toHaveBeenCalled()
		expect(push.mock.calls[0][0]).toMatchObject({
			name: 'SchemaDesigner',
			params: { slug: 'hello-world', schemaId: 'new' },
		})
	})

	it('REQ-OBSD-002: addSchema surfaces a duplicate-slug error (409) as a thrown Error', async () => {
		storeMocks.fetchCollection.mockResolvedValue([])
		storeMocks.saveObject.mockResolvedValue(null)
		storeMocks.errors = { schema: 'HTTP 409: slug already exists' }
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await expect(
			wrapper.vm.addSchema({ slug: 'dup', title: 'D', version: '0.1.0' }),
		).rejects.toMatchObject({ status: 409 })
	})

	it('REQ-OBSD-003: detail mount loads via store.fetchObject and stages the body via schemaToFields', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.fetchObject.mockResolvedValue(persistedSchema)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter({ schemaId: 'hello' }),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(storeMocks.fetchObject).toHaveBeenCalledWith('schema', 'hello')
		expect(wrapper.vm.staged).toBeTruthy()
		expect(wrapper.vm.staged.slug).toBe('hello')
		expect(wrapper.vm.staged.fields).toHaveLength(1)
		expect(wrapper.vm.staged.fields[0].name).toBe('subject')
	})

	it('REQ-OBSD-006: onFieldsChange updates the staged copy and flips hasStagedChanges true', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.fetchObject.mockResolvedValue(persistedSchema)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter({ schemaId: 'hello' }),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		// Initial staged matches persisted → no changes.
		expect(wrapper.vm.hasStagedChanges).toBe(false)

		// Push a brand-new field through.
		wrapper.vm.onFieldsChange([
			...wrapper.vm.staged.fields,
			{
				_key: 'f-new',
				name: 'body',
				type: 'string',
				required: false,
				default: null,
				description: '',
				validation: {},
			},
		])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.hasStagedChanges).toBe(true)
	})

	it('REQ-OBSD-006: save() composes a JSON-Schema body and calls store.saveObject with id', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.fetchObject.mockResolvedValue(persistedSchema)
		// Echo back whatever was saved (mocks a PUT round-trip).
		storeMocks.saveObject.mockImplementation(async (_type, body) => body)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter({ schemaId: 'hello' }),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		// Mutate the staged title so there is a change to save.
		wrapper.vm.onHeaderChange({
			slug: wrapper.vm.staged.slug,
			title: 'Hello renamed',
			description: 'desc',
			version: '0.2.0',
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.save()
		expect(storeMocks.saveObject).toHaveBeenCalled()
		const [type, body] = storeMocks.saveObject.mock.calls[0]
		expect(type).toBe('schema')
		expect(body).toMatchObject({
			id: 'hello',
			slug: 'hello',
			title: 'Hello renamed',
			version: '0.2.0',
			type: 'object',
		})
		expect(body.properties).toBeDefined()
		expect(dialogMocks.showSuccess).toHaveBeenCalled()
	})

	it('REQ-OBSD-008: delete flows via store.deleteObject + refreshes the list', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.deleteObject.mockResolvedValue(true)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		// Fire the SchemaListPanel @delete event.
		await wrapper.find('.emit-delete').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(storeMocks.deleteObject).toHaveBeenCalledWith('schema', 'hello')
		expect(dialogMocks.showSuccess).toHaveBeenCalled()
		// Refresh was triggered after the delete settled.
		expect(storeMocks.fetchCollection).toHaveBeenCalledTimes(2)
	})

	it('REQ-OBSD-008: failed delete surfaces showError and does NOT refresh', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.deleteObject.mockResolvedValue(false)
		storeMocks.errors = { schema: 'forbidden' }
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.find('.emit-delete').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(dialogMocks.showError).toHaveBeenCalled()
	})

	it('REQ-OBSD-001: open event routes to the detail view via $router.push', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		const push = vi.fn()
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter(),
				$router: { push },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.find('.emit-open').trigger('click')
		expect(push).toHaveBeenCalledWith({
			name: 'SchemaDesigner',
			params: { slug: 'hello-world', schemaId: 'hello' },
			query: {},
		})
	})

	it('REQ-OBSD-006: canSave is false when no changes have been staged', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.fetchObject.mockResolvedValue(persistedSchema)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter({ schemaId: 'hello' }),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSave).toBe(false)
	})

	it('REQ-OBSD-006: discardChanges restores the staged copy from persisted', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		storeMocks.fetchObject.mockResolvedValue(persistedSchema)
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: {
				$route: makeRouter({ schemaId: 'hello' }),
				$router: { push: vi.fn() },
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		wrapper.vm.onHeaderChange({
			slug: wrapper.vm.staged.slug,
			title: 'changed',
			description: '',
			version: '0.1.0',
		})
		expect(wrapper.vm.staged.title).toBe('changed')
		wrapper.vm.discardChanges()
		expect(wrapper.vm.staged.title).toBe(persistedSchema.title)
	})
})
