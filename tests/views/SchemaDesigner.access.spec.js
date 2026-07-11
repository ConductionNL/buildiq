/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the Access sub-editor wiring in
 * `SchemaDesigner.vue` (REQ-OBDSA-001 .. REQ-OBDSA-007).
 *
 * Covers:
 *  - `bodyToStaged` / `composeSchemaBody` round-trip with and without a
 *    persisted `authorization` block.
 *  - An unrelated field/header edit + save preserves a persisted
 *    `authorization` block untouched — the regression test for the
 *    pre-existing strip-on-save bug this change fixes.
 *  - `authorLockedOut` truth table: non-member / member / admin.
 *  - `accessReadOnly` truth table: draft vs production version, owner vs
 *    editor role.
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
	}
})

vi.mock('../../src/store/schemas.js', () => {
	return {
		useSchemasStore: () => storeMocks,
		registerSlugForApp: (appSlug) => `openbuild-${appSlug}`,
		STORE_ID: 'openbuild-schemas',
	}
})

vi.mock('@nextcloud/dialogs', () => {
	return { showError: vi.fn(), showSuccess: vi.fn() }
})

/**
 * Groups returned by the mocked `loadState('openbuild', 'currentUserGroups')`
 * call — mutated per-test instead of using `mockReturnValue`/`mockReset`,
 * which would also affect unrelated `loadState('core', 'capabilities')`
 * calls that `@nextcloud/vue`'s own bundled chunks make at *module
 * evaluation time* (before any test's beforeEach runs). The real
 * `@nextcloud/initial-state` throws when a DOM data attribute is absent —
 * @nextcloud/vue and @nextcloud/capabilities both catch that throw and fall
 * back to jsdom-safe defaults. A bare `vi.fn()` (returning undefined
 * instead of throwing) breaks that fallback chain and crashes an unrelated
 * NcListItem import. Throwing for any other (app, key) pair preserves the
 * real module's contract for everyone except our own composable.
 */
let mockedUserGroups = []

vi.mock('@nextcloud/initial-state', () => {
	return {
		// Mirrors the real module's signature: `loadState(app, key, fallback?)`
		// returns `fallback` when provided instead of throwing (the real
		// implementation does the same when the DOM data-attribute is
		// absent) — @nextcloud/vue's internal calls pass their own
		// fallback, so honouring it keeps their code paths working exactly
		// as they do against the real, un-mocked module.
		loadState: vi.fn((app, key, fallback) => {
			if (app === 'openbuild' && key === 'currentUserGroups') {
				return mockedUserGroups
			}
			if (fallback !== undefined) {
				return fallback
			}
			throw new Error(`loadState mock: no initial state for ${app}/${key}`)
		}),
	}
})

const { loadState } = await import('@nextcloud/initial-state')
const { default: axios } = await import('@nextcloud/axios')
const { default: SchemaDesigner } = await import('../../src/views/SchemaDesigner.vue')

// Both `loadApplicationRecord()` (SchemaDesigner) and `useApplicationVersion()`
// fire unrelated network fetches on mount. Spying on (not replacing) the real
// axios module's `get` makes those reject deterministically and immediately,
// instead of racing a real (refused) TCP connection against this file's
// manual `applicationRecord` / `applicationVersion` overrides used for the
// accessReadOnly truth table. A blanket `vi.mock('@nextcloud/axios', ...)`
// was tried first but broke @nextcloud/vue's own internal axios usage
// (NcPasswordField chunk import crashed on `password_policy`) — spying on
// the real module's method keeps its other exports intact.
vi.spyOn(axios, 'get').mockRejectedValue(new Error('network disabled in test'))

const editorStubs = {
	SchemaListPanel: { name: 'SchemaListPanel', props: ['schemas', 'loading'], template: '<div />' },
	SchemaHeaderForm: { name: 'SchemaHeaderForm', props: ['value', 'lockedSlug'], template: '<div />' },
	FieldEditor: { name: 'FieldEditor', props: ['fields', 'schemaSlugs'], template: '<div />' },
	LifecycleEditor: { name: 'LifecycleEditor', props: ['states', 'transitions'], template: '<div />' },
	RelationEditor: { name: 'RelationEditor', props: ['relations', 'schemaSlugs'], template: '<div />' },
	AccessEditor: { name: 'AccessEditor', props: ['access', 'fieldNames', 'availableGroups', 'readOnly'], template: '<div />' },
	WidgetEditor: { name: 'WidgetEditor', props: ['widgets'], template: '<div />' },
	AggregationEditor: { name: 'AggregationEditor', props: ['aggregations'], template: '<div />' },
	CalculationEditor: { name: 'CalculationEditor', props: ['calculations'], template: '<div />' },
	NotificationEditor: { name: 'NotificationEditor', props: ['notifications'], template: '<div />' },
	NcButton: { name: 'NcButton', props: ['type', 'disabled'], template: '<button :disabled="disabled"><slot name="icon" /><slot /></button>' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div />' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note-stub" :data-type="type"><slot /></div>' },
}

function makeRouter({ slug = 'hello-world', schemaId = 'hello', version = undefined } = {}) {
	return {
		params: { slug, schemaId },
		query: version ? { _version: version } : {},
	}
}

const persistedSchemaWithAuth = {
	slug: 'hello-world-hello',
	title: 'Hello',
	description: '',
	version: '0.1.0',
	type: 'object',
	properties: { subject: { type: 'string' } },
	'x-property-order': ['subject'],
	authorization: { read: ['vets'] },
}

beforeEach(() => {
	storeMocks.fetchCollection.mockReset()
	storeMocks.fetchObject.mockReset()
	storeMocks.saveObject.mockReset()
	storeMocks.deleteObject.mockReset()
	storeMocks.errors = {}
	loadState.mockClear()
	mockedUserGroups = []
	globalThis.OC = { isUserAdmin: () => false }
})

async function mountDetail({ schemaObject = persistedSchemaWithAuth, route = {} } = {}) {
	storeMocks.fetchCollection.mockResolvedValue([schemaObject])
	storeMocks.fetchObject.mockResolvedValue(schemaObject)
	const wrapper = mount(SchemaDesigner, {
		stubs: editorStubs,
		mocks: {
			$route: makeRouter(route),
			$router: { push: vi.fn() },
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('SchemaDesigner — Access sub-editor wiring (REQ-OBDSA-001 / REQ-OBDSA-002)', () => {
	it('bodyToStaged / composeSchemaBody round-trip WITHOUT an authorization block omits the key', async () => {
		const wrapper = await mountDetail({
			schemaObject: {
				slug: 'hello-world-hello',
				title: 'Hello',
				description: '',
				version: '0.1.0',
				type: 'object',
				properties: { subject: { type: 'string' } },
				'x-property-order': ['subject'],
			},
		})
		expect(wrapper.vm.staged.access.rows.every((r) => r.kind === 'everyone')).toBe(true)
		const body = wrapper.vm.composeSchemaBody(wrapper.vm.staged)
		expect(body.authorization).toBeUndefined()
	})

	it('bodyToStaged / composeSchemaBody round-trip WITH an authorization block preserves it exactly', async () => {
		const wrapper = await mountDetail()
		expect(wrapper.vm.staged.rawAuthorization).toEqual({ read: ['vets'] })
		const body = wrapper.vm.composeSchemaBody(wrapper.vm.staged)
		expect(body.authorization).toEqual({ read: ['vets'] })
	})

	it('REQ-OBDSA-002: an unrelated field-title edit + save preserves the persisted authorization block (strip-bug regression)', async () => {
		storeMocks.saveObject.mockImplementation(async (_type, body) => body)
		const wrapper = await mountDetail()
		// Unrelated edit: change the header title only.
		wrapper.vm.onHeaderChange({
			slug: wrapper.vm.staged.slug,
			title: 'Hello renamed',
			description: '',
			version: '0.1.0',
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.save()
		expect(storeMocks.saveObject).toHaveBeenCalled()
		const [, body] = storeMocks.saveObject.mock.calls[0]
		expect(body.authorization).toEqual({ read: ['vets'] })
		expect(body.title).toBe('Hello renamed')
	})

	it('REQ-OBDSA-002: a byte-identical authorization block persists after Save even when it holds an unrepresentable entry', async () => {
		const schemaObject = {
			...persistedSchemaWithAuth,
			authorization: { read: ['vets', '@creator'] }, // mixed array — unrepresentable
		}
		storeMocks.saveObject.mockImplementation(async (_type, body) => body)
		const wrapper = await mountDetail({ schemaObject })
		wrapper.vm.onHeaderChange({
			slug: wrapper.vm.staged.slug,
			title: 'Renamed again',
			description: '',
			version: '0.1.0',
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.save()
		const [, body] = storeMocks.saveObject.mock.calls[0]
		expect(body.authorization).toEqual({ read: ['vets', '@creator'] })
	})
})

describe('SchemaDesigner — authorLockedOut truth table (REQ-OBDSA-004)', () => {
	it('non-admin non-member sees the lock-out warning when read is scoped to a group they are not in', async () => {
		mockedUserGroups = ['other-group']
		globalThis.OC = { isUserAdmin: () => false }
		const wrapper = await mountDetail()
		expect(wrapper.vm.authorLockedOut).toBe(true)
	})

	it('a member of the scoped group sees no lock-out warning', async () => {
		mockedUserGroups = ['vets']
		globalThis.OC = { isUserAdmin: () => false }
		const wrapper = await mountDetail()
		expect(wrapper.vm.authorLockedOut).toBe(false)
	})

	it('an NC admin never sees the lock-out warning (admin bypasses OR enforcement)', async () => {
		mockedUserGroups = ['other-group']
		globalThis.OC = { isUserAdmin: () => true }
		const wrapper = await mountDetail()
		expect(wrapper.vm.authorLockedOut).toBe(false)
	})

	it('own-records / condition scopes never trigger the lock-out warning', async () => {
		mockedUserGroups = ['other-group']
		globalThis.OC = { isUserAdmin: () => false }
		const wrapper = await mountDetail({
			schemaObject: { ...persistedSchemaWithAuth, authorization: { read: ['@creator'] } },
		})
		expect(wrapper.vm.authorLockedOut).toBe(false)
	})

	it('an unscoped (everyone) read row never triggers the lock-out warning', async () => {
		mockedUserGroups = []
		globalThis.OC = { isUserAdmin: () => false }
		const wrapper = await mountDetail({
			schemaObject: {
				slug: 'hello-world-hello',
				title: 'Hello',
				description: '',
				version: '0.1.0',
				type: 'object',
				properties: { subject: { type: 'string' } },
				'x-property-order': ['subject'],
			},
		})
		expect(wrapper.vm.authorLockedOut).toBe(false)
	})
})

describe('SchemaDesigner — accessReadOnly truth table (REQ-OBDSA-007)', () => {
	it('draft version + editor role → editable (not gated)', async () => {
		const wrapper = await mountDetail()
		wrapper.vm.applicationRecord = { permissions: { owners: [], editors: ['editors-group'], viewers: [] }, productionVersion: 'prod-uuid' }
		wrapper.vm.applicationVersion = { uuid: 'draft-uuid' }
		mockedUserGroups = ['editors-group']
		expect(wrapper.vm.accessReadOnly).toBe(false)
	})

	it('production version + editor role → read-only', async () => {
		const wrapper = await mountDetail()
		wrapper.vm.applicationRecord = { permissions: { owners: [], editors: ['editors-group'], viewers: [] }, productionVersion: 'prod-uuid' }
		wrapper.vm.applicationVersion = { uuid: 'prod-uuid' }
		mockedUserGroups = ['editors-group']
		expect(wrapper.vm.accessReadOnly).toBe(true)
	})

	it('production version + owner role → editable', async () => {
		const wrapper = await mountDetail()
		wrapper.vm.applicationRecord = { permissions: { owners: ['owners-group'], editors: [], viewers: [] }, productionVersion: 'prod-uuid' }
		wrapper.vm.applicationVersion = { uuid: 'prod-uuid' }
		mockedUserGroups = ['owners-group']
		expect(wrapper.vm.accessReadOnly).toBe(false)
	})

	it('production version + viewer/no role → not editor-gated (useRole returns "viewer"/"none", not "editor")', async () => {
		const wrapper = await mountDetail()
		wrapper.vm.applicationRecord = { permissions: { owners: [], editors: [], viewers: ['viewers-group'] }, productionVersion: 'prod-uuid' }
		wrapper.vm.applicationVersion = { uuid: 'prod-uuid' }
		mockedUserGroups = ['viewers-group']
		expect(wrapper.vm.accessReadOnly).toBe(false)
	})
})
