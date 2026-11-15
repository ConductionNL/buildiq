import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the live pickers of AutomationEditDialog.vue: schemas, lifecycle
 * transitions, groups and synchronizations, and how each degrades to free text.
 *
 * Spec: automation-designer (REQ-AUTD-002).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationEditDialog from '../../src/dialogs/AutomationEditDialog.vue'
import { clearAppStatusCache } from '../../src/composables/useAppStatus.js'

const NcSelectStub = {
	name: 'NcSelect',
	props: [
		'value',
		'options',
		'loading',
		'inputLabel',
		'label',
		'clearable',
		'disabled',
		'multiple',
	],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label', 'placeholder', 'type'],
	template:
		'<input class="nctextfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}
const NcTextAreaStub = {
	name: 'NcTextArea',
	props: ['value', 'label'],
	template:
		'<textarea class="nctextarea-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcModalStub = {
	name: 'NcModal',
	props: ['name', 'size'],
	template: '<div class="ncmodal-stub"><slot /></div>',
}
const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="ncnotecard-stub"><slot /></div>',
}

const stubs = {
	NcModal: NcModalStub,
	NcSelect: NcSelectStub,
	NcTextField: NcTextFieldStub,
	NcTextArea: NcTextAreaStub,
	NcButton: NcButtonStub,
	NcNoteCard: NcNoteCardStub,
}

const flush = () => new Promise((r) => setTimeout(r, 0))

function baseAutomation() {
	return {
		slug: '',
		name: '',
		description: '',
		applicationSlug: 'permit-tracker',
		versionUuid: 'version-1',
		enabled: true,
		trigger: { type: 'manual' },
		condition: null,
		actions: [],
	}
}

function factory(automation = baseAutomation(), register = 'permits') {
	return mount(AutomationEditDialog, {
		propsData: { open: false, automation, register },
		stubs,
	})
}

async function openDialog(wrapper) {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

/**
 * Answer axios GETs per URL fragment; anything else gets an empty list.
 *
 * @param {Record<string, Function>} routes - URL fragment to handler.
 * @return {void}
 */
function route(routes) {
	axios.get.mockImplementation((url) => {
		for (const [fragment, handler] of Object.entries(routes)) {
			if (url.includes(fragment)) {
				return handler(url)
			}
		}
		return Promise.resolve({ data: { results: [] } })
	})
}

/**
 * A fetch() answering the OCS group listing.
 *
 * @param {object} answer - { ok, groups } or { throws: true }.
 * @return {Function} the fetch mock.
 */
function groupsFetch(answer) {
	return vi.fn(async () => {
		if (answer.throws) {
			throw new Error('offline')
		}
		return {
			ok: answer.ok,
			json: async () => ({ ocs: { data: { groups: answer.groups } } }),
		}
	})
}

describe('AutomationEditDialog live pickers', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		clearAppStatusCache()
		vi.stubGlobal('fetch', groupsFetch({ ok: true, groups: [] }))
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('lists the register schemas by slug and title, and selects the stored one', async () => {
		route({
			'/registers/permits/schemas': () => Promise.resolve({
				data: [
					{ id: 1, slug: 'permit', title: 'Permit' },
					{ id: 2, slug: 'case' },
					{ id: 3 },
				],
			}),
		})
		const automation = baseAutomation()
		automation.trigger = { type: 'object-created', schema: 'case' }

		const wrapper = factory(automation)
		await openDialog(wrapper)

		expect(wrapper.vm.schemaOptions).toEqual([
			{ value: 'permit', label: 'Permit' },
			{ value: 'case', label: 'case' },
			{ value: 3, label: '3' },
		])
		expect(wrapper.vm.schemaOption).toEqual({ value: 'case', label: 'case' })
		expect(wrapper.vm.schemaFetchFailed).toBe(false)
		expect(wrapper.vm.schemaLoading).toBe(false)
	})

	it('falls back to free text without a register, on an empty list and on a failed request', async () => {
		const noRegister = factory(baseAutomation(), '')
		await openDialog(noRegister)
		expect(noRegister.vm.schemaFetchFailed).toBe(true)
		expect(axios.get.mock.calls.some(([url]) => url.includes('/schemas'))).toBe(false)

		route({ '/registers/permits/schemas': () => Promise.resolve({ data: { results: [] } }) })
		const empty = factory()
		await openDialog(empty)
		expect(empty.vm.schemaFetchFailed).toBe(true)

		route({ '/registers/permits/schemas': () => Promise.reject(new Error('404')) })
		const failed = factory()
		await openDialog(failed)
		expect(failed.vm.schemaFetchFailed).toBe(true)
		expect(failed.vm.schemaOptions).toEqual([])
		expect(failed.vm.schemaLoading).toBe(false)
	})

	it('loads the stored schema\'s lifecycle transitions for a lifecycle trigger', async () => {
		route({
			'/registers/permits/schemas': () => Promise.resolve({ data: { results: [{ slug: 'permit', title: 'Permit' }] } }),
			'/api/schemas/permit': () => Promise.resolve({
				data: { 'x-openregister-lifecycle': { transitions: { approve: {}, reject: {} } } },
			}),
		})
		const automation = baseAutomation()
		automation.trigger = { type: 'lifecycle-transition', schema: 'permit', transition: 'reject' }

		const wrapper = factory(automation)
		await openDialog(wrapper)
		await flush()

		expect(wrapper.vm.transitionOptions).toEqual([
			{ value: 'approve', label: 'approve' },
			{ value: 'reject', label: 'reject' },
		])
		expect(wrapper.vm.transitionOption).toEqual({ value: 'reject', label: 'reject' })
		expect(wrapper.vm.transitionFetchFailed).toBe(false)
		expect(wrapper.vm.transitionLoading).toBe(false)
	})

	it('selecting a schema on a lifecycle trigger loads its transitions; clearing it loads nothing', async () => {
		route({
			'/api/schemas/case': () => Promise.resolve({ data: {} }),
			'/api/schemas/broken': () => Promise.reject(new Error('500')),
		})
		const automation = baseAutomation()
		automation.trigger = { type: 'lifecycle-transition' }
		const wrapper = factory(automation)
		await openDialog(wrapper)

		wrapper.vm.onSchemaSelect({ value: 'case', label: 'Case' })
		await flush()
		expect(wrapper.vm.triggerSchema).toBe('case')
		expect(wrapper.vm.transitionOptions).toEqual([])
		expect(wrapper.vm.transitionFetchFailed).toBe(true)

		wrapper.vm.onSchemaSelect({ value: 'broken', label: 'Broken' })
		await flush()
		expect(wrapper.vm.transitionFetchFailed).toBe(true)
		expect(wrapper.vm.transitionLoading).toBe(false)

		const before = axios.get.mock.calls.length
		wrapper.vm.onSchemaSelect(null)
		await flush()
		expect(wrapper.vm.triggerSchema).toBe('')
		expect(axios.get.mock.calls.length).toBe(before)
	})

	it('selecting a schema on an object trigger stores it without asking for transitions', async () => {
		const automation = baseAutomation()
		automation.trigger = { type: 'object-updated' }
		const wrapper = factory(automation)
		await openDialog(wrapper)
		const before = axios.get.mock.calls.length

		wrapper.vm.onSchemaSelect({ value: 'permit', label: 'Permit' })
		await flush()

		expect(wrapper.vm.triggerSchema).toBe('permit')
		expect(axios.get.mock.calls.length).toBe(before)
	})

	it('lists the Nextcloud groups over OCS and applies a pick to the approval row', async () => {
		const fetchMock = groupsFetch({ ok: true, groups: ['admin', 'permits'] })
		vi.stubGlobal('fetch', fetchMock)
		const automation = baseAutomation()
		automation.actions = [{ type: 'approval', assigneeGroup: 'permits' }]

		const wrapper = factory(automation)
		await openDialog(wrapper)

		expect(fetchMock).toHaveBeenCalledWith('/ocs/v1.php/cloud/groups?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(wrapper.vm.groupOptions).toEqual([
			{ value: 'admin', label: 'admin' },
			{ value: 'permits', label: 'permits' },
		])
		expect(wrapper.vm.groupOption('permits')).toEqual({ value: 'permits', label: 'permits' })
		expect(wrapper.vm.groupOption('gone')).toBeNull()

		wrapper.vm.onGroupSelect(0, { value: 'admin', label: 'admin' })
		expect(wrapper.vm.actions[0].assigneeGroup).toBe('admin')
		wrapper.vm.onGroupSelect(0, null)
		expect(wrapper.vm.actions[0].assigneeGroup).toBe('')
	})

	it('falls back to a free-text group on a refused, empty or failed listing', async () => {
		for (const answer of [{ ok: false, groups: ['x'] }, { ok: true, groups: [] }, { throws: true }]) {
			vi.stubGlobal('fetch', groupsFetch(answer))
			const wrapper = factory()
			await openDialog(wrapper)

			expect(wrapper.vm.groupFetchFailed).toBe(true)
			expect(wrapper.vm.groupLoading).toBe(false)
		}
	})

	it('falls back to a free-text synchronization when none can be listed, and applies a pick', async () => {
		globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
		route({ '/synchronization': () => Promise.reject(new Error('404')) })
		const automation = baseAutomation()
		automation.actions = [{ type: 'run-synchronization', synchronizationId: '' }]

		const wrapper = factory(automation)
		await openDialog(wrapper)

		expect(wrapper.vm.syncFetchFailed).toBe(true)
		expect(wrapper.vm.syncOptions).toEqual([])
		expect(wrapper.vm.syncOption('sync-1')).toBeNull()

		wrapper.vm.onSyncSelect(0, { id: 'sync-1', label: 'BRP sync' })
		expect(wrapper.vm.actions[0].synchronizationId).toBe('sync-1')
		wrapper.vm.onSyncSelect(0, null)
		expect(wrapper.vm.actions[0].synchronizationId).toBe('')
		delete globalThis.OC
	})
})
