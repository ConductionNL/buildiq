/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SchemaDesigner's staged-model undo/redo (builder-undo-redo,
 * REQ-BUR-002 / REQ-BUR-003 / REQ-BUR-005), mounted with `useSchemasStore`
 * mocked per the store-integration seam convention already used by
 * `tests/views/SchemaDesigner.spec.js`.
 *
 * Covers:
 *  - each staged `on*Change` commit (fields/header/states/transitions/
 *    relations/widgets/access) is one history entry;
 *  - undo restores the staged model without any store call;
 *  - `discardChanges()` is one undoable entry;
 *  - toolbar disabled states (REQ-BUR-002);
 *  - Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y route through `onKeydown`, with the
 *    editable-target guard (REQ-BUR-003);
 *  - a mocked successful `store.saveObject` resolution resets the
 *    history (both buttons disabled); a rejected save leaves it intact
 *    (REQ-BUR-005).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

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

vi.mock('../../../src/store/schemas.js', () => {
	return {
		useSchemasStore: () => storeMocks,
		registerSlugForApp: (appSlug) => `buildiq-${appSlug}`,
		STORE_ID: 'openbuild-schemas',
	}
})

vi.mock('@nextcloud/dialogs', () => dialogMocks)

const { default: SchemaDesigner } =
	await import('../../../src/views/SchemaDesigner.vue')

const editorStubs = {
	SchemaListPanel: {
		name: 'SchemaListPanel',
		props: ['schemas', 'loading'],
		template: '<div />',
	},
	SchemaHeaderForm: {
		name: 'SchemaHeaderForm',
		props: ['value', 'lockedSlug'],
		template: '<div class="schema-header-stub" />',
	},
	FieldEditor: {
		name: 'FieldEditor',
		props: ['fields', 'schemaSlugs'],
		template: '<div class="field-editor-stub" />',
	},
	LifecycleEditor: {
		name: 'LifecycleEditor',
		props: ['states', 'transitions'],
		template: '<div class="lifecycle-editor-stub" />',
	},
	RelationEditor: {
		name: 'RelationEditor',
		props: ['relations', 'schemaSlugs'],
		template: '<div class="relation-editor-stub" />',
	},
	AccessEditor: {
		name: 'AccessEditor',
		props: ['access', 'fieldNames', 'availableGroups', 'readOnly'],
		template: '<div class="access-editor-stub" />',
	},
	WidgetEditor: {
		name: 'WidgetEditor',
		props: ['widgets'],
		template: '<div class="widget-editor-stub" />',
	},
	AggregationEditor: {
		name: 'AggregationEditor',
		props: ['aggregations'],
		template: '<div />',
	},
	CalculationEditor: {
		name: 'CalculationEditor',
		props: ['calculations'],
		template: '<div />',
	},
	NotificationEditor: {
		name: 'NotificationEditor',
		props: ['notifications'],
		template: '<div />',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled', 'title'],
		template:
			'<button class="nc-button-stub" :disabled="disabled" :title="title" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="empty-stub" />',
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		template: '<div class="loading-stub" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note-stub"><slot /></div>',
	},
}

function makeRouter({
	slug = 'hello-world',
	schemaId = '',
	version = undefined,
} = {}) {
	return {
		params: { slug, schemaId },
		query: version ? { _version: version } : {},
	}
}

// Mirrors composeSchemaBody's exact output shape so the initial
// hasStagedChanges is false.
const persistedSchema = {
	slug: 'hello-world-hello',
	title: 'Hello',
	description: '',
	version: '0.1.0',
	type: 'object',
	properties: { subject: { type: 'string' } },
	'x-property-order': ['subject'],
}

const flush = async (wrapper) => {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

function mountDetail() {
	storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
	storeMocks.fetchObject.mockResolvedValue(persistedSchema)
	return mount(SchemaDesigner, {
		stubs: editorStubs,
		mocks: {
			$route: makeRouter({ schemaId: 'hello' }),
			$router: { push: vi.fn() },
		},
	})
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

describe('SchemaDesigner — undo/redo (builder-undo-redo)', () => {
	it('REQ-BUR-002: both buttons disabled in a fresh detail session', async () => {
		const wrapper = await (async () => {
			const w = mountDetail()
			await flush(w)
			return w
		})()
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('REQ-BUR-005: onFieldsChange is one history entry and undo restores it without a store call', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
		const before = wrapper.vm.staged
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
		expect(wrapper.vm.canUndo).toBe(true)
		expect(wrapper.vm.staged.fields).toHaveLength(2)
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.staged.fields).toHaveLength(1)
		expect(wrapper.vm.staged).toEqual(before)
		expect(storeMocks.saveObject).not.toHaveBeenCalled()
	})

	it('REQ-BUR-005: redo re-applies the undone field edit', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canRedo).toBe(true)
		wrapper.vm.redo()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.staged.fields).toHaveLength(2)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('every staged on*Change handler is exactly one history entry', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
		const sizeBefore = wrapper.vm.history.size.value
		// Each value below is deliberately distinct from the staged model's
		// current value (persistedSchema stages with empty states/
		// transitions/relations/widgets and a non-null access block) — the
		// engine's structural-identity no-op would otherwise absorb a
		// same-as-current commit and under-count entries.
		wrapper.vm.onHeaderChange({
			title: 'New title',
			description: '',
			version: '0.2.0',
		})
		await wrapper.vm.$nextTick()
		wrapper.vm.onStatesChange([{ name: 'draft', initial: true }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onTransitionsChange([
			{ from: 'draft', to: 'published', label: 'publish' },
		])
		await wrapper.vm.$nextTick()
		wrapper.vm.onRelationsChange([
			{ name: 'owner', target: 'user', type: 'many-to-one' },
		])
		await wrapper.vm.$nextTick()
		wrapper.vm.onWidgetsChange([{ type: 'table', config: {} }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onAccessChange({
			rows: [{ op: 'read', kind: 'group', groups: ['admin'] }],
			extraKeys: {},
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.history.size.value).toBe(sizeBefore + 6)
	})

	it('REQ-BUR-005: "Discard staged edits" is one undoable entry', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		expect(wrapper.vm.staged.fields).toHaveLength(2)
		wrapper.vm.discardChanges()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.staged.fields).toHaveLength(1)
		expect(wrapper.vm.canUndo).toBe(true)
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		// The discarded staged edits (2 fields) are restored in full.
		expect(wrapper.vm.staged.fields).toHaveLength(2)
	})

	it('REQ-BUR-005: a successful save resets the history (both buttons disabled)', async () => {
		storeMocks.saveObject.mockImplementation(async (_type, body) => body)
		const wrapper = mountDetail()
		await flush(wrapper)
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
		expect(wrapper.vm.canUndo).toBe(true)
		await wrapper.vm.save()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('REQ-BUR-005: a rejected save leaves the history intact', async () => {
		storeMocks.saveObject.mockResolvedValue(null)
		storeMocks.errors = { schema: 'save failed' }
		const wrapper = mountDetail()
		await flush(wrapper)
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
		expect(wrapper.vm.canUndo).toBe(true)
		await wrapper.vm.save()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
	})

	it('REQ-BUR-003: Ctrl+Z / Ctrl+Shift+Z drive undo/redo outside editable fields', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		wrapper.vm.onKeydown({
			ctrlKey: true,
			key: 'z',
			shiftKey: false,
			preventDefault() {},
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.staged.fields).toHaveLength(1)
		wrapper.vm.onKeydown({
			ctrlKey: true,
			key: 'z',
			shiftKey: true,
			preventDefault() {},
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.staged.fields).toHaveLength(2)
	})

	it('REQ-BUR-003: a chord targeting an <input> is ignored (native text-field undo wins)', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		let prevented = false
		const input = document.createElement('input')
		wrapper.vm.onKeydown({
			ctrlKey: true,
			key: 'z',
			shiftKey: false,
			target: input,
			preventDefault() {
				prevented = true
			},
		})
		await wrapper.vm.$nextTick()
		expect(prevented).toBe(false)
		expect(wrapper.vm.staged.fields).toHaveLength(2)
	})

	it('onKeydown no-ops in list mode (no schemaId, no staged model)', async () => {
		storeMocks.fetchCollection.mockResolvedValue([persistedSchema])
		const wrapper = mount(SchemaDesigner, {
			stubs: editorStubs,
			mocks: { $route: makeRouter(), $router: { push: vi.fn() } },
		})
		await flush(wrapper)
		expect(() => {
			wrapper.vm.onKeydown({
				ctrlKey: true,
				key: 'z',
				shiftKey: false,
				preventDefault() {},
			})
		}).not.toThrow()
	})

	it('renders Undo/Redo NcButtons with shortcut tooltips beside Discard/Save', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
		const buttons = wrapper.findAll('.nc-button-stub')
		// VTU v2 returns a plain array from findAll(); the v1 `.wrappers`
		// accessor no longer exists.
		const titles = buttons.map((w) => w.attributes('title'))
		expect(titles).toContain('Undo (Ctrl+Z)')
		expect(titles).toContain('Redo (Ctrl+Shift+Z / Ctrl+Y)')
	})

	it('REQ-BUR-004/-005: the appSlug watcher resets the history (app switch is a session boundary)', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		expect(wrapper.vm.canUndo).toBe(true)
		wrapper.vm.$options.watch.appSlug.handler.call(wrapper.vm)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('REQ-BUR-004/-005: the versionSlug watcher resets the history (version switch is a session boundary)', async () => {
		const wrapper = mountDetail()
		await flush(wrapper)
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
		expect(wrapper.vm.canUndo).toBe(true)
		wrapper.vm.$options.watch.versionSlug.handler.call(wrapper.vm)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
	})
})
