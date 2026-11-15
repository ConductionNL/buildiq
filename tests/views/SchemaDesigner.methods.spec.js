/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the orchestration helpers of `SchemaDesigner.vue` that the
 * list/detail happy-path specs (`SchemaDesigner.spec.js`,
 * `SchemaDesigner.access.spec.js`) do not reach: the pure id/slug readers,
 * the error-message extractors, the breaking-change refusal classifier, the
 * register-attach side effect, delete/navigation, and the undo/redo + keydown
 * handlers.
 *
 * These exercise `wrapper.vm` methods directly with controlled component
 * state, asserting real return values / collaborator calls rather than DOM.
 * Sub-editors are stubbed (each has its own spec) so the mount is cheap.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
		registerSlugForApp: (appSlug, versionSlug) =>
			versionSlug ? `buildiq-${appSlug}-${versionSlug}` : `buildiq-${appSlug}`,
		STORE_ID: 'openbuild-schemas',
	}
})

vi.mock('@nextcloud/dialogs', () => {
	return dialogMocks
})

const { default: axios } = await import('@nextcloud/axios')

// A register record the OpenRegister read returns for the attach flow. The
// test mutates `registerRecord` per case (already-attached, missing id, …).
let registerRecord = { id: 42, slug: 'reg-slug', schemas: [] }

vi.spyOn(axios, 'get').mockImplementation((url) => {
	const target = String(url)
	if (target.includes('/apps/openregister/api/registers/')) {
		return Promise.resolve({ data: registerRecord })
	}
	if (target.includes('cloud/groups')) {
		return Promise.resolve({
			data: { ocs: { data: { groups: ['vets', 'admin', ''] } } },
		})
	}
	if (target.includes('/versions')) {
		return Promise.resolve({ data: [] })
	}
	if (target.includes('/schemas')) {
		return Promise.resolve({ data: { results: [] } })
	}
	return Promise.resolve({ data: { results: [] } })
})

const axiosPatch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} })

const { default: SchemaDesigner } =
	await import('../../src/views/SchemaDesigner.vue')

const stubChild = (name, props = []) => ({ name, props, template: `<div class="${name}-stub" />` })
const editorStubs = {
	SchemaListPanel: stubChild('SchemaListPanel', ['schemas', 'loading']),
	SchemaHeaderForm: stubChild('SchemaHeaderForm', ['value', 'lockedSlug']),
	FieldEditor: stubChild('FieldEditor', ['fields', 'schemaSlugs']),
	LifecycleEditor: stubChild('LifecycleEditor', ['states', 'transitions']),
	RelationEditor: stubChild('RelationEditor', ['relations', 'schemaSlugs']),
	AccessEditor: stubChild('AccessEditor', ['access', 'fieldNames', 'availableGroups', 'readOnly']),
	WidgetEditor: stubChild('WidgetEditor', ['widgets']),
	AggregationEditor: stubChild('AggregationEditor', ['aggregations']),
	CalculationEditor: stubChild('CalculationEditor', ['calculations', 'propertyCalculations', 'fieldNames', 'refusals']),
	NotificationEditor: stubChild('NotificationEditor', ['notifications']),
	BreakingSchemaChangeDialog: stubChild('BreakingSchemaChangeDialog', ['open', 'changes', 'busy']),
	ImportDataWizard: stubChild('ImportDataWizard', ['open', 'registerId']),
	NcButton: stubChild('NcButton', ['type', 'disabled']),
	NcEmptyContent: stubChild('NcEmptyContent', ['name', 'description']),
	NcLoadingIcon: stubChild('NcLoadingIcon', []),
	NcNoteCard: stubChild('NcNoteCard', ['type']),
}

/**
 * Build a `$route` stand-in.
 *
 * @param {object} opts Route overrides.
 * @param {string} [opts.slug] The app slug.
 * @param {string} [opts.schemaId] The schema id param.
 * @param {string} [opts.version] The `?_version=` query value.
 * @return {object} A minimal route object.
 */
function makeRouter({ slug = 'hello-world', schemaId = '', version = undefined } = {}) {
	return {
		params: { slug, schemaId },
		query: version ? { _version: version } : {},
	}
}

/**
 * Mount the designer with the sub-editors stubbed and a pushable router.
 *
 * @param {object} [route] Route overrides forwarded to `makeRouter`.
 * @return {Promise<object>} The mounted wrapper (after mount() settles).
 */
async function mountDesigner(route = {}) {
	const push = vi.fn().mockResolvedValue()
	const wrapper = mount(SchemaDesigner, {
		stubs: editorStubs,
		mocks: {
			$route: makeRouter(route),
			$router: { push },
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	wrapper.vm.$router.push.mockClear()
	return wrapper
}

/**
 * Build a complete staged editor model via the component's own
 * `bodyToStaged`, so assigning it to `wrapper.vm.staged` renders the detail
 * view without the computed field pickers crashing on a missing `fields`.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} slug The schema slug.
 * @return {object} A fully-shaped staged model.
 */
function stagedModel(wrapper, slug) {
	return wrapper.vm.bodyToStaged({
		slug,
		title: slug,
		version: '0.1.0',
		properties: {},
	})
}

beforeEach(() => {
	registerRecord = { id: 42, slug: 'reg-slug', schemas: [] }
	axios.get.mockClear()
	axiosPatch.mockClear()
	axiosPatch.mockResolvedValue({ data: {} })
	storeMocks.fetchCollection.mockReset()
	storeMocks.fetchObject.mockReset()
	storeMocks.saveObject.mockReset()
	storeMocks.deleteObject.mockReset()
	storeMocks.errors = {}
	dialogMocks.showError.mockReset()
	dialogMocks.showSuccess.mockReset()
})

describe('SchemaDesigner persisted id/slug readers', () => {
	it('persistedNumericId: null with no persisted schema, resolves @self.id, treats empty as null', async () => {
		const wrapper = await mountDesigner()
		wrapper.vm.persisted = null
		expect(wrapper.vm.persistedNumericId()).toBe(null)

		wrapper.vm.persisted = { id: 7 }
		expect(wrapper.vm.persistedNumericId()).toBe(7)

		wrapper.vm.persisted = { '@self': { id: 9 } }
		expect(wrapper.vm.persistedNumericId()).toBe(9)

		wrapper.vm.persisted = { id: '' }
		expect(wrapper.vm.persistedNumericId()).toBe(null)
	})

	it('persistedSlug: empty string with no persisted, reads slug then @self.slug', async () => {
		const wrapper = await mountDesigner()
		wrapper.vm.persisted = null
		expect(wrapper.vm.persistedSlug()).toBe('')

		wrapper.vm.persisted = { slug: 'direct' }
		expect(wrapper.vm.persistedSlug()).toBe('direct')

		wrapper.vm.persisted = { '@self': { slug: 'nested' } }
		expect(wrapper.vm.persistedSlug()).toBe('nested')
	})
})

describe('SchemaDesigner namespacedSlug', () => {
	it('prefixes with app+version, leaves prefixed/empty untouched', async () => {
		const wrapper = await mountDesigner({ version: 'prod' })
		expect(wrapper.vm.namespacedSlug('pet')).toBe('hello-world-prod-pet')
		expect(wrapper.vm.namespacedSlug('hello-world-prod-pet')).toBe(
			'hello-world-prod-pet',
		)
		expect(wrapper.vm.namespacedSlug('  ')).toBe('')
	})

	it('falls back to the app-only prefix when there is no version', async () => {
		const wrapper = await mountDesigner()
		expect(wrapper.vm.namespacedSlug('pet')).toBe('hello-world-pet')
	})
})

describe('SchemaDesigner errorMessage / saveErrorText / isBreakingRefusal', () => {
	it('errorMessage walks response.data shapes then falls back to message', async () => {
		const wrapper = await mountDesigner()
		expect(wrapper.vm.errorMessage(null)).toBe('')
		expect(wrapper.vm.errorMessage({ response: { data: 'raw text' } })).toBe('raw text')
		expect(
			wrapper.vm.errorMessage({ response: { data: { message: 'msg' } } }),
		).toBe('msg')
		expect(
			wrapper.vm.errorMessage({ response: { data: { error: 'err' } } }),
		).toBe('err')
		expect(wrapper.vm.errorMessage(new Error('boom'))).toBe('boom')
		expect(wrapper.vm.errorMessage('plain')).toBe('plain')
	})

	it('saveErrorText prefers a string, then details, then message, then a generic line', async () => {
		const wrapper = await mountDesigner()
		expect(wrapper.vm.saveErrorText('already a string')).toBe('already a string')
		expect(wrapper.vm.saveErrorText({ details: 'the details' })).toBe(
			'Could not save the schema: {error}',
		)
		expect(wrapper.vm.saveErrorText({ message: 'the message' })).toBe(
			'Could not save the schema: {error}',
		)
		expect(wrapper.vm.saveErrorText(null)).toBe('Could not save the schema.')
	})

	it('isBreakingRefusal is true only for a 409 whose details mention "breaking"', async () => {
		const wrapper = await mountDesigner()
		expect(wrapper.vm.isBreakingRefusal(null)).toBe(false)
		expect(wrapper.vm.isBreakingRefusal('string')).toBe(false)
		expect(wrapper.vm.isBreakingRefusal({ status: 400, details: 'breaking' })).toBe(false)
		expect(wrapper.vm.isBreakingRefusal({ status: 409, details: 'other' })).toBe(false)
		expect(
			wrapper.vm.isBreakingRefusal({ status: 409, details: 'Breaking change' }),
		).toBe(true)
	})
})

describe('SchemaDesigner attachSchemaToRegister', () => {
	it('no-ops when the created schema has no id', async () => {
		const wrapper = await mountDesigner()
		await wrapper.vm.attachSchemaToRegister({})
		expect(axios.patch).not.toHaveBeenCalled()
		expect(dialogMocks.showError).not.toHaveBeenCalled()
	})

	it('PATCHes the register by numeric id, appending the new schema id', async () => {
		registerRecord = { id: 42, slug: 'reg-slug', schemas: ['1'] }
		const wrapper = await mountDesigner({ version: 'prod' })
		wrapper.vm.applicationVersion = { register: 'reg-slug' }
		await wrapper.vm.$nextTick()
		await wrapper.vm.attachSchemaToRegister({ id: 99 })
		expect(axiosPatch).toHaveBeenCalledTimes(1)
		const [patchUrl, payload] = axiosPatch.mock.calls[0]
		expect(String(patchUrl)).toContain('/registers/42')
		expect(payload).toEqual({ schemas: ['1', 99] })
	})

	it('skips the PATCH when the schema is already attached', async () => {
		registerRecord = { id: 42, slug: 'reg-slug', schemas: ['99'] }
		const wrapper = await mountDesigner({ version: 'prod' })
		wrapper.vm.applicationVersion = { register: 'reg-slug' }
		await wrapper.vm.$nextTick()
		await wrapper.vm.attachSchemaToRegister({ id: 99 })
		expect(axiosPatch).not.toHaveBeenCalled()
	})

	it('surfaces a showError toast when the register has no id to write by', async () => {
		registerRecord = { slug: 'reg-slug', schemas: [] }
		const wrapper = await mountDesigner({ version: 'prod' })
		wrapper.vm.applicationVersion = { register: 'reg-slug' }
		await wrapper.vm.$nextTick()
		await wrapper.vm.attachSchemaToRegister({ id: 99 })
		expect(axiosPatch).not.toHaveBeenCalled()
		expect(dialogMocks.showError).toHaveBeenCalled()
	})
})

describe('SchemaDesigner deleteSchema', () => {
	it('deletes by the row numeric id and toasts success', async () => {
		const wrapper = await mountDesigner()
		wrapper.vm.schemas = [{ slug: 'pet', id: 5 }]
		storeMocks.deleteObject.mockResolvedValue(true)
		await wrapper.vm.deleteSchema('pet')
		expect(storeMocks.deleteObject).toHaveBeenCalledWith('schema', 5)
		expect(dialogMocks.showSuccess).toHaveBeenCalled()
	})

	it('shows an error and makes no navigation when the delete fails', async () => {
		const wrapper = await mountDesigner()
		wrapper.vm.schemas = [{ slug: 'pet', id: 5 }]
		storeMocks.deleteObject.mockResolvedValue(false)
		storeMocks.errors = { schema: 'nope' }
		await wrapper.vm.deleteSchema('pet')
		expect(dialogMocks.showError).toHaveBeenCalled()
		expect(wrapper.vm.$router.push).not.toHaveBeenCalled()
	})

	it('navigates back to the list when the open schema is the one deleted', async () => {
		const wrapper = await mountDesigner({ schemaId: 'pet' })
		wrapper.vm.schemas = [{ slug: 'pet', id: 5 }]
		storeMocks.deleteObject.mockResolvedValue(true)
		await wrapper.vm.deleteSchema('pet')
		expect(wrapper.vm.$router.push).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.$router.push.mock.calls[0][0].name).toBe(
			'SchemaDesignerList',
		)
	})
})

describe('SchemaDesigner navigation helpers', () => {
	it('openSchema pushes the versioned SchemaDesigner route for the slug', async () => {
		const wrapper = await mountDesigner({ version: 'prod' })
		wrapper.vm.openSchema('pet')
		const arg = wrapper.vm.$router.push.mock.calls[0][0]
		expect(arg.name).toBe('SchemaDesigner')
		expect(arg.params.schemaId).toBe('pet')
		expect(arg.query._version).toBe('prod')
	})

	it('goToList pushes the versioned list route', async () => {
		const wrapper = await mountDesigner({ version: 'prod' })
		wrapper.vm.goToList()
		const arg = wrapper.vm.$router.push.mock.calls[0][0]
		expect(arg.name).toBe('SchemaDesignerList')
		expect(arg.query._version).toBe('prod')
	})
})

describe('SchemaDesigner undo/redo/keydown', () => {
	it('undo/redo mutate staged from the session history, and no-op without one', async () => {
		const wrapper = await mountDesigner()
		const prevState = stagedModel(wrapper, 'back')
		const nextState = stagedModel(wrapper, 'fwd')
		wrapper.vm.history = {
			undo: vi.fn().mockReturnValue(prevState),
			redo: vi.fn().mockReturnValue(nextState),
		}
		wrapper.vm.undo()
		expect(wrapper.vm.staged.slug).toBe('back')
		wrapper.vm.redo()
		expect(wrapper.vm.staged.slug).toBe('fwd')

		// A null history (no detail session) is a silent no-op.
		wrapper.vm.history = null
		wrapper.vm.staged = stagedModel(wrapper, 'kept')
		wrapper.vm.undo()
		wrapper.vm.redo()
		expect(wrapper.vm.staged.slug).toBe('kept')
	})

	it('undo/redo leave staged unchanged at the ends of the history', async () => {
		const wrapper = await mountDesigner()
		wrapper.vm.history = {
			undo: vi.fn().mockReturnValue(null),
			redo: vi.fn().mockReturnValue(null),
		}
		wrapper.vm.staged = stagedModel(wrapper, 'edge')
		wrapper.vm.undo()
		wrapper.vm.redo()
		expect(wrapper.vm.staged.slug).toBe('edge')
	})

	it('onKeydown routes ctrl+z to undo and ctrl+shift+z / ctrl+y to redo', async () => {
		const wrapper = await mountDesigner({ schemaId: 'pet' })
		wrapper.vm.staged = stagedModel(wrapper, 'pet')
		const undo = vi.spyOn(wrapper.vm, 'undo').mockImplementation(() => {})
		const redo = vi.spyOn(wrapper.vm, 'redo').mockImplementation(() => {})

		const ev = (over) => ({ ctrlKey: true, preventDefault: vi.fn(), ...over })
		wrapper.vm.onKeydown(ev({ key: 'z' }))
		expect(undo).toHaveBeenCalledTimes(1)
		wrapper.vm.onKeydown(ev({ key: 'z', shiftKey: true }))
		expect(redo).toHaveBeenCalledTimes(1)
		wrapper.vm.onKeydown(ev({ key: 'y' }))
		expect(redo).toHaveBeenCalledTimes(2)
	})

	it('onKeydown no-ops without a staged schema or without a modifier', async () => {
		const wrapper = await mountDesigner({ schemaId: 'pet' })
		const undo = vi.spyOn(wrapper.vm, 'undo').mockImplementation(() => {})

		// No staged model.
		wrapper.vm.staged = null
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', preventDefault: vi.fn() })
		expect(undo).not.toHaveBeenCalled()

		// Staged, but no ctrl/meta modifier.
		wrapper.vm.staged = stagedModel(wrapper, 'pet')
		wrapper.vm.onKeydown({ key: 'z', preventDefault: vi.fn() })
		expect(undo).not.toHaveBeenCalled()
	})
})

describe('SchemaDesigner discardChanges / save guards', () => {
	it('discardChanges restages the persisted body and clears the save error', async () => {
		const wrapper = await mountDesigner()
		const commit = vi.spyOn(wrapper.vm, 'commitStaged').mockImplementation(() => {})
		wrapper.vm.persisted = { slug: 'pet', title: 'Pet', version: '0.1.0', properties: {} }
		wrapper.vm.saveError = 'stale'
		wrapper.vm.discardChanges()
		expect(commit).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.saveError).toBe('')
	})

	it('save is a no-op while already saving', async () => {
		const wrapper = await mountDesigner({ schemaId: 'pet' })
		wrapper.vm.staged = stagedModel(wrapper, 'pet')
		wrapper.vm.saving = true
		await wrapper.vm.save()
		expect(storeMocks.saveObject).not.toHaveBeenCalled()
	})

	it('save records errorMessage() when writeBody throws', async () => {
		const wrapper = await mountDesigner({ schemaId: 'pet' })
		wrapper.vm.staged = stagedModel(wrapper, 'pet')
		vi.spyOn(wrapper.vm, 'writeBody').mockImplementation(() => {
			throw new Error('compose blew up')
		})
		await wrapper.vm.save()
		expect(wrapper.vm.saveError).toBe('compose blew up')
		expect(wrapper.vm.saving).toBe(false)
	})
})

describe('SchemaDesigner data loaders', () => {
	it('loadApplicationRecord keeps the matching app and swallows request errors', async () => {
		const wrapper = await mountDesigner()
		axios.get.mockImplementationOnce(() =>
			Promise.resolve({
				data: { results: [{ slug: 'other' }, { slug: 'hello-world', id: 1 }] },
			}),
		)
		await wrapper.vm.loadApplicationRecord()
		expect(wrapper.vm.applicationRecord).toEqual({ slug: 'hello-world', id: 1 })

		axios.get.mockImplementationOnce(() => Promise.reject(new Error('down')))
		await wrapper.vm.loadApplicationRecord()
		expect(wrapper.vm.applicationRecord).toBe(null)
	})

	it('loadInstanceGroups keeps only non-empty string group ids and empties on error', async () => {
		const wrapper = await mountDesigner()
		await wrapper.vm.loadInstanceGroups()
		expect(wrapper.vm.instanceGroups).toEqual(['vets', 'admin'])

		axios.get.mockImplementationOnce(() => Promise.reject(new Error('down')))
		await wrapper.vm.loadInstanceGroups()
		expect(wrapper.vm.instanceGroups).toEqual([])
	})

	it('onSchemaImported refreshes the schema list', async () => {
		const wrapper = await mountDesigner()
		const refresh = vi.spyOn(wrapper.vm, 'refreshList').mockResolvedValue()
		await wrapper.vm.onSchemaImported()
		expect(refresh).toHaveBeenCalledTimes(1)
	})
})

describe('SchemaDesigner computed guards', () => {
	const c = SchemaDesigner.computed

	it('otherSchemaSlugs reads slug, @self.slug or id and leaves out the open schema', () => {
		const slugs = c.otherSchemaSlugs.call({
			schemaId: 'pets',
			schemas: [{ slug: 'pets' }, { '@self': { slug: 'owners' } }, { id: 'vets' }, {}],
		})
		expect(slugs).toEqual(['owners', 'vets'])
	})

	it('headerValue and fieldNames are empty without a staged schema', () => {
		expect(c.headerValue.call({ staged: null })).toEqual({ slug: '', title: '', description: '', version: '0.1.0' })
		expect(c.fieldNames.call({ staged: null })).toEqual([])
		expect(c.fieldNames.call({ staged: { fields: [{ name: 'a' }, { name: '' }, { name: 'b' }] } })).toEqual(['a', 'b'])
	})

	it('fieldNamesUnique refuses a missing or repeated name and passes without a staged schema', () => {
		expect(c.fieldNamesUnique.call({ staged: null })).toBe(true)
		expect(c.fieldNamesUnique.call({ staged: { fields: [{ name: 'a' }, { name: '' }] } })).toBe(false)
		expect(c.fieldNamesUnique.call({ staged: { fields: [{ name: 'a' }, { name: 'a' }] } })).toBe(false)
		expect(c.fieldNamesUnique.call({ staged: { fields: [{ name: 'a' }, { name: 'b' }] } })).toBe(true)
	})

	it('canSave is false while saving, on a lifecycle or name error, or on a widget config error', () => {
		const ok = {
			staged: { widgets: [] },
			saving: false,
			hasInitialLifecycleState: true,
			fieldNamesUnique: true,
			hasStagedChanges: true,
		}
		expect(c.canSave.call(ok)).toBe(true)
		expect(c.canSave.call({ ...ok, saving: true })).toBe(false)
		expect(c.canSave.call({ ...ok, hasInitialLifecycleState: false })).toBe(false)
		expect(c.canSave.call({ ...ok, fieldNamesUnique: false })).toBe(false)
		expect(c.canSave.call({ ...ok, staged: { widgets: [{ configError: 'bad json' }] } })).toBe(false)
		expect(c.canSave.call({ ...ok, hasStagedChanges: false })).toBe(false)
	})
})

describe('SchemaDesigner loadDetail', () => {
	/**
	 * A stand-in `this` for loadDetail.
	 *
	 * @param {object} overrides Fields to replace.
	 * @return {object} The context.
	 */
	function context(overrides = {}) {
		return {
			schemaId: 'pets',
			staged: null,
			persisted: null,
			loadingDetail: false,
			detailAttempted: false,
			saveError: 'old',
			history: { reset: vi.fn() },
			store: storeMocks,
			persistedSlug: () => '',
			bodyToStaged: (body) => ({ slug: body.slug, staged: true }),
			errorMessage: (e) => e.message,
			t: (app, text, vars) => (vars ? text.replace('{error}', vars.error) : text),
			...overrides,
		}
	}

	it('resets to an empty session without a schema id', async () => {
		const ctx = context({ schemaId: '', staged: { slug: 'x' }, persisted: { slug: 'x' } })
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(ctx.staged).toBeNull()
		expect(ctx.persisted).toBeNull()
		expect(ctx.history.reset).toHaveBeenCalledWith(null)
		expect(storeMocks.fetchObject).not.toHaveBeenCalled()
	})

	it('skips the fetch when the staged schema is already the one asked for', async () => {
		const staged = { slug: 'pets' }
		const ctx = context({ staged, persistedSlug: () => 'pets' })
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(storeMocks.fetchObject).not.toHaveBeenCalled()
		expect(ctx.detailAttempted).toBe(true)
		expect(ctx.staged).toBe(staged)
	})

	it('stages what the store returns', async () => {
		storeMocks.fetchObject.mockResolvedValue({ slug: 'pets' })
		const ctx = context()
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(storeMocks.fetchObject).toHaveBeenCalledWith('schema', 'pets')
		expect(ctx.persisted).toEqual({ slug: 'pets' })
		expect(ctx.staged).toEqual({ slug: 'pets', staged: true })
		expect(ctx.saveError).toBe('')
		expect([ctx.loadingDetail, ctx.detailAttempted]).toEqual([false, true])
	})

	it('shows the store error when nothing comes back', async () => {
		storeMocks.fetchObject.mockResolvedValue(null)
		storeMocks.errors = { schema: 'not allowed' }
		const ctx = context()
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(ctx.staged).toBeNull()
		expect(dialogMocks.showError).toHaveBeenCalledWith('Failed to load schema: not allowed')
		expect(ctx.detailAttempted).toBe(true)
	})

	it('stays silent when nothing comes back and the store has no error', async () => {
		storeMocks.fetchObject.mockResolvedValue(null)
		const ctx = context()
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(ctx.staged).toBeNull()
		expect(dialogMocks.showError).not.toHaveBeenCalled()
	})

	it('shows the thrown error and clears the staged schema', async () => {
		storeMocks.fetchObject.mockRejectedValue(new Error('network down'))
		const ctx = context({ staged: { slug: 'old' }, persistedSlug: () => 'old' })
		await SchemaDesigner.methods.loadDetail.call(ctx)
		expect(ctx.staged).toBeNull()
		expect(ctx.persisted).toBeNull()
		expect(dialogMocks.showError).toHaveBeenCalledWith('Failed to load schema: network down')
		expect(ctx.loadingDetail).toBe(false)
	})
})

describe('SchemaDesigner body, create fallback and lifecycle hooks', () => {
	it('composeSchemaBody passes aggregations, calculations and notifications through unchanged', async () => {
		const wrapper = await mountDesigner()
		const aggregations = { total: { field: 'amount', op: 'sum' } }
		const calculations = { vat: { expression: 'amount * 0.21' } }
		const notifications = [{ on: 'create', to: 'admin' }]
		const staged = wrapper.vm.bodyToStaged({
			slug: 'pets',
			title: 'Pets',
			version: '0.1.0',
			properties: {},
			'x-openregister-aggregations': aggregations,
			'x-openregister-calculations': calculations,
			'x-openregister-notifications': notifications,
		})

		const body = wrapper.vm.composeSchemaBody(staged)

		expect(body['x-openregister-aggregations']).toEqual(aggregations)
		expect(body['x-openregister-calculations']).toEqual(calculations)
		expect(body['x-openregister-notifications']).toEqual(notifications)
		const bare = wrapper.vm.composeSchemaBody(wrapper.vm.bodyToStaged({ slug: 'p', title: 'P', version: '0.1.0', properties: {} }))
		expect('x-openregister-aggregations' in bare).toBe(false)
		expect('x-openregister-notifications' in bare).toBe(false)
	})

	it('addSchema throws the store error text when the create fails without a duplicate', async () => {
		const wrapper = await mountDesigner()
		storeMocks.saveObject.mockResolvedValue(null)
		storeMocks.errors = { schema: 'quota reached' }
		await expect(wrapper.vm.addSchema({ slug: 'pets', title: 'Pets', version: '0.1.0' })).rejects.toThrow('quota reached')
		expect(wrapper.vm.$router.push).not.toHaveBeenCalled()
	})

	it('addSchema still navigates to the new schema when staging after the create fails', async () => {
		const wrapper = await mountDesigner()
		storeMocks.saveObject.mockResolvedValue({ id: 7, slug: 'buildiq-hello-world-pets' })
		vi.spyOn(wrapper.vm, 'attachSchemaToRegister').mockRejectedValue(new Error('patch failed'))
		wrapper.vm.staged = { slug: 'previous' }

		await wrapper.vm.addSchema({ slug: 'pets', title: 'Pets', version: '0.1.0' })

		expect(wrapper.vm.staged).toBeNull()
		expect(wrapper.vm.persisted).toBeNull()
		expect(wrapper.vm.$router.push).toHaveBeenCalledTimes(1)
		expect(JSON.stringify(wrapper.vm.$router.push.mock.calls[0][0])).toContain('buildiq-hello-world-pets')
	})

	it('a new schema id clears the previous attempt and loads again', () => {
		const ctx = { detailAttempted: true, loadDetail: vi.fn() }
		SchemaDesigner.watch.schemaId.handler.call(ctx)
		expect(ctx.detailAttempted).toBe(false)
		expect(ctx.loadDetail).toHaveBeenCalledTimes(1)
	})

	it('unmounting removes the keydown listener', async () => {
		const wrapper = await mountDesigner()
		const remove = vi.spyOn(document, 'removeEventListener')
		wrapper.unmount()
		expect(remove).toHaveBeenCalledWith('keydown', expect.any(Function))
		remove.mockRestore()
	})
})
