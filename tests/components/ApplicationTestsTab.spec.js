/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest for the app's Tests tab and the release test report
 * (lifecycle-release-test-gate REQ-BQRT-001 to REQ-BQRT-005): the latest run
 * per version with its checks by kind, "Run tests", record tests written by
 * owners and editors, rule-set sandboxes, and the release dialog's "Release
 * anyway" with a reason.
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, loadState } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
	loadState: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/initial-state', () => ({ loadState }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

import ApplicationTestsTab, { groupChecks } from '../../src/components/tabs/ApplicationTestsTab.vue'
import { draftOf, testOf } from '../../src/dialogs/RecordTestDialog.vue'
import ReleaseTestReportDialog from '../../src/dialogs/ReleaseTestReportDialog.vue'
import { failedTestRunOf } from '../../src/views/VersionHistory.vue'

const VERSIONS = [
	{ id: 'v-dev', slug: 'development', name: 'Development', manifest: { pages: [{ config: { schema: 'permit' } }, { config: { schema: 'permit' } }, { config: {} }] } },
	{ id: 'v-old', slug: 'old', status: 'archived' },
]

const RUN = {
	id: 'run-1',
	ranAt: '2026-11-14T08:00:00+00:00',
	trigger: 'manual',
	passed: 42,
	failed: 1,
	skipped: 1,
	report: [
		{ kind: 'structure', name: 'permits: column kvkNumber', outcome: 'failed', message: 'No such property on permit.' },
		{ kind: 'record', name: 'permit: Permit needs an applicant', outcome: 'skipped', message: 'OpenRegister cannot check a record without saving it yet, so this record test did not run.' },
	],
}

const OVERVIEW = {
	recordTests: [{ id: 't-1', name: 'Permit needs an applicant', schema: 'permit', expect: 'refuse', expectedFields: ['applicant'] }],
	ruleSets: [{ slug: 'permit-fees', name: 'Permit fees', ownerApp: 'vergunningen' }],
	latestRuns: { 'v-dev': RUN },
}

/**
 * Mount the tab for vergunningen with the caller in this role.
 *
 * @param {string} role owner, editor or viewer.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountTab(role = 'editor') {
	axiosMock.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/versions') ? VERSIONS : OVERVIEW,
	}))
	// The caller's groups: one of the app's role groups.
	loadState.mockReturnValue([role + 's'])
	const wrapper = shallowMount(ApplicationTestsTab, {
		props: {
			object: {
				uuid: 'uuid-app',
				slug: 'vergunningen',
				permissions: { owners: ['owners'], editors: ['editors'], viewers: ['viewers'] },
			},
		},
		global: { mocks: { $route: { query: {} } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	Object.values(axiosMock).forEach((fn) => fn.mockReset())
})

describe('ApplicationTestsTab', () => {
	it('loads the versions and the tests, and shows the chosen version\'s last run', async () => {
		const wrapper = await mountTab()
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/versions')
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/tests')
		expect(wrapper.vm.versionOptions).toEqual([{ id: 'v-dev', slug: 'development', label: 'Development' }])
		expect(wrapper.vm.latestRun).toEqual(RUN)
		expect(wrapper.text()).toContain('Last run')
		expect(wrapper.text()).toContain('permits: column kvkNumber')
		expect(wrapper.text()).toContain('No such property on permit.')
	})

	it('groups the checks by kind in suite order, leaving out empty kinds', () => {
		expect(groupChecks(RUN.report).map((g) => [g.kind, g.checks.length])).toEqual([['structure', 1], ['record', 1]])
		expect(groupChecks(undefined)).toEqual([])
	})

	it('offers the schemas the version\'s pages use', async () => {
		const wrapper = await mountTab()
		expect(wrapper.vm.schemaSlugs).toEqual(['permit'])
	})

	it('"Run tests" posts for the chosen version and shows the new run', async () => {
		const wrapper = await mountTab()
		const fresh = { ...RUN, id: 'run-2', failed: 0, passed: 43 }
		axiosMock.post.mockResolvedValue({ data: fresh })
		await wrapper.vm.runTests()
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/versions/development/tests', {})
		expect(wrapper.vm.latestRun).toEqual(fresh)
	})

	it('lets owners and editors run and write, and a viewer only read', async () => {
		expect((await mountTab('owner')).vm.canEdit).toBe(true)
		expect((await mountTab('editor')).vm.canEdit).toBe(true)
		const viewer = await mountTab('viewer')
		expect(viewer.vm.canEdit).toBe(false)
		expect(viewer.text()).not.toContain('Run tests')
		expect(viewer.text()).not.toContain('Add record test')
	})

	it('adds a new record test with POST and changes an existing one with PUT', async () => {
		const wrapper = await mountTab()
		axiosMock.post.mockResolvedValue({ data: {} })
		axiosMock.put.mockResolvedValue({ data: {} })
		const test = { name: 'A full permit', schema: 'permit', record: { applicant: 'X' }, expect: 'accept', expectedFields: [] }

		wrapper.vm.editTest(null)
		await wrapper.vm.saveTest(test)
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/record-tests', test)

		wrapper.vm.editTest(OVERVIEW.recordTests[0])
		await wrapper.vm.saveTest(test)
		expect(axiosMock.put).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/record-tests/t-1', test)
		expect(wrapper.vm.dialogOpen).toBe(false)
	})

	it('removes a record test', async () => {
		const wrapper = await mountTab()
		axiosMock.delete.mockResolvedValue({ data: {} })
		await wrapper.vm.removeTest(OVERVIEW.recordTests[0])
		expect(axiosMock.delete).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/record-tests/t-1')
	})

	it('opens a rule set\'s test sandbox from the list', async () => {
		const wrapper = await mountTab()
		expect(wrapper.text()).toContain('Permit fees')
		wrapper.vm.sandboxRuleSet = OVERVIEW.ruleSets[0]
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'RuleSetTestSandboxModal' }).props('ruleSet')).toEqual(OVERVIEW.ruleSets[0])
	})
})

describe('RecordTestDialog helpers', () => {
	it('turns a draft into a record test, keeping refusal fields only for a refusal', () => {
		const draft = { ...draftOf(null), name: ' Needs an applicant ', schema: 'permit', recordText: '{"title":"Shed"}', expect: 'refuse', fieldsText: 'applicant, , owner' }
		expect(testOf(draft)).toEqual({ test: { name: 'Needs an applicant', schema: 'permit', record: { title: 'Shed' }, expect: 'refuse', expectedFields: ['applicant', 'owner'] } })
		expect(testOf({ ...draft, expect: 'accept' }).test.expectedFields).toEqual([])
	})

	it('refuses a draft without a name, with bad JSON or with a list as record', () => {
		const draft = { ...draftOf(null), name: 'N', schema: 'permit' }
		expect(testOf({ ...draft, name: '' }).error).toBe('A record test needs a name and a schema.')
		expect(testOf({ ...draft, recordText: '{' }).error).toBe('The sample record is not valid JSON.')
		expect(testOf({ ...draft, recordText: '[1]' }).error).toBe('The sample record must be an object.')
	})

	it('drafts an existing test', () => {
		expect(draftOf(OVERVIEW.recordTests[0])).toEqual({ name: 'Permit needs an applicant', schema: 'permit', recordText: '{}', expect: 'refuse', fieldsText: 'applicant' })
	})
})

describe('the release test report', () => {
	it('reads the test run out of a release stopped by its tests, and nothing else', () => {
		const stopped = { response: { status: 422, data: { error: 'release_tests_failed', testRun: RUN } } }
		expect(failedTestRunOf(stopped)).toEqual(RUN)
		expect(failedTestRunOf({ response: { status: 422, data: { error: 'release_failed' } } })).toBeNull()
		expect(failedTestRunOf(new Error('network'))).toBeNull()
	})

	it('lists the failed checks and lets an owner release anyway only with a reason', async () => {
		const wrapper = shallowMount(ReleaseTestReportDialog, {
			props: { open: true, run: RUN, canOverride: true },
			global: { stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } } },
		})
		expect(wrapper.vm.failedChecks).toEqual([RUN.report[0]])
		expect(wrapper.text()).toContain('permits: column kvkNumber')
		wrapper.vm.reason = '  Column removed on purpose  '
		await wrapper.vm.$nextTick()
		const anyway = wrapper.findAllComponents({ name: 'NcButton' }).at(1)
		expect(anyway.props('disabled')).toBe(false)
		anyway.vm.$emit('click')
		expect(wrapper.emitted('override')).toEqual([['Column removed on purpose']])
	})

	it('offers no override to someone who may not give one', () => {
		const wrapper = shallowMount(ReleaseTestReportDialog, {
			props: { open: true, run: RUN, canOverride: false },
			global: { stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } } },
		})
		expect(wrapper.findAllComponents({ name: 'NcButton' })).toHaveLength(1)
	})
})
