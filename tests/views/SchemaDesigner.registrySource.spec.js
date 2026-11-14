/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schema designer fetches integriq's registry providers once and hands
 * them to the field editor, and a save OpenRegister refuses over a field's
 * registry source lands on that field (data-registry-backed-field-option).
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003
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
	if (target.includes('/api/property-sources')) {
		return Promise.resolve({ data: { results: [{ id: 'kvk', label: 'KvK organisation', identifier: 'kvkNummer' }] } })
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
	FieldEditor: stubChild('FieldEditor', ['fields', 'schemaSlugs', 'propertySources', 'sourceRefusals']),
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

describe('SchemaDesigner registry sources', () => {
	it('fetches the providers once through the integriq path and passes them to the field editor', async () => {
		const wrapper = await mountDesigner()
		const calls = axios.get.mock.calls.map(([url]) => String(url)).filter((url) => url.includes('property-sources'))

		expect(calls).toHaveLength(1)
		expect(calls[0]).toMatch(/\/apps\/integriq\/api\/property-sources$/)
		expect(wrapper.vm.propertySources).toEqual({
			loaded: true,
			available: true,
			results: [{ id: 'kvk', label: 'KvK organisation', identifier: 'kvkNummer' }],
		})

	})

	it('shows a refused registry source on the field it names, and does not report a save', async () => {
		const wrapper = await mountDesigner({ schemaId: 'bedrijf' })
		wrapper.vm.detailAttempted = true
		wrapper.vm.loadingDetail = false
		wrapper.vm.staged = wrapper.vm.bodyToStaged({
			slug: 'bedrijf',
			title: 'Bedrijf',
			version: '0.1.0',
			properties: {
				naam: { type: 'string' },
				kvkNummer: { type: 'string', 'x-openregister-property-source': { provider: 'nope', mode: 'live', config: {} } },
			},
		})
		const message = "'x-openregister-property-source' at 'properties.kvkNummer' names the provider 'nope', which is not a provider id."
		storeMocks.saveObject.mockResolvedValue(null)
		storeMocks.errors = { schema: { status: 400, details: message } }

		await wrapper.vm.save()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.sourceRefusals).toEqual({ kvkNummer: [message] })
		const editor = wrapper.findComponent({ name: 'FieldEditor' })
		expect(editor.props('sourceRefusals')).toEqual({ kvkNummer: [message] })
		expect(editor.props('propertySources').available).toBe(true)
		expect(wrapper.vm.saveError).not.toBe('')
		expect(dialogMocks.showSuccess).not.toHaveBeenCalled()

		storeMocks.saveObject.mockResolvedValue({ slug: 'bedrijf', version: '0.1.1', properties: {} })
		await wrapper.vm.save()
		expect(wrapper.vm.sourceRefusals).toEqual({})
	})
})
