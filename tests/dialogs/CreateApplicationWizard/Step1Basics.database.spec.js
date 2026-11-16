/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for "Start from a database" in the create-app wizard
 * (data-external-database-sources T07, REQ-BQDB-003): the database picker and
 * table checklist on step 1, the step's validity, the pages listed on the
 * review step, and the `fromDatabase` block the wizard posts.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

const answers = vi.hoisted(() => ({ sources: [], registers: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/openregister/api/sources') {
				return Promise.resolve({ data: { results: answers.sources } })
			}
			if (url === '/apps/openregister/api/registers') {
				return Promise.resolve({ data: { results: answers.registers } })
			}
			return Promise.reject(new Error('no provider in tests'))
		}),
		post: vi.fn(),
	},
}))

vi.mock('../../../src/utils/iconCatalogues.js', () => ({
	buildIconCatalogues: () => [],
	ICON_SOURCES: [],
	resolveAppIcon: () => null,
}))

import axios from '@nextcloud/axios'
import CreateApplicationWizard from '../../../src/dialogs/CreateApplicationWizard.vue'
import StartFromDatabase, { tableOption } from '../../../src/dialogs/CreateApplicationWizard/StartFromDatabase.vue'
import Step1Basics from '../../../src/dialogs/CreateApplicationWizard/Step1Basics.vue'
import Step4Review from '../../../src/dialogs/CreateApplicationWizard/Step4Review.vue'
import { clearCopilotHealthCache } from '../../../src/composables/useCopilot.js'

const PERMITS_DB = [
	{ id: 11, slug: 'permits', title: 'Permits' },
	{ id: 12, slug: 'applicants', title: '' },
]

/**
 * Mount step 1, re-rendering with every payload patch it emits, as the wizard shell does.
 *
 * @param {object} payload The starting payload.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountStep1(payload = {}) {
	const wrapper = mount(Step1Basics, {
		props: {
			payload: { name: 'Vergunningen', slug: 'vergunningen', description: '', ...payload },
			'onUpdate:payload': (partial) => wrapper.setProps({ payload: { ...wrapper.props('payload'), ...partial } }),
		},
		global: { stubs: { CnIconPicker: true, CopilotGenerateDialog: true } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	clearCopilotHealthCache()
	answers.sources = [
		{ id: 7, type: 'database', authConfig: { writable: false } },
		{ id: 9, type: 'api' },
	]
	answers.registers = [
		{ id: 1, slug: 'permits-db', title: 'Permits database', source: 7 },
		{ id: 3, slug: 'publications', source: 9 },
	]
	vi.stubGlobal('fetch', vi.fn(async (url) => ({
		ok: true,
		json: async () => ({ results: url === '/apps/openregister/api/registers/permits-db/schemas' ? PERMITS_DB : [] }),
	})))
	axios.post.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('Start from a database — step 1', () => {
	it('shows nothing when OpenRegister serves no connected database', async () => {
		answers.sources = [{ id: 9, type: 'api' }]
		const wrapper = await mountStep1()
		expect(wrapper.find('[data-testid="wizard-database"]').exists()).toBe(false)
	})

	it('offers only the database registers, and loads the picked one\'s tables', async () => {
		const wrapper = await mountStep1()
		await wrapper.find('[data-testid="wizard-database-toggle"]').setValue(true)

		const options = wrapper.findAll('[data-testid="wizard-database-register"] option').map((option) => option.text())
		expect(options).toEqual(['Choose a database', 'Permits database'])

		await wrapper.find('[data-testid="wizard-database-register"]').setValue('permits-db')
		await flushPromises()

		expect(fetch).toHaveBeenCalledWith('/apps/openregister/api/registers/permits-db/schemas', expect.anything())
		expect(wrapper.find('[data-testid="wizard-database-table-permits"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('This database does not take writes, so its pages only show data.')
	})

	it('is not valid until a database and a table are chosen, and keeps the tables in the order ticked', async () => {
		const wrapper = await mountStep1()
		const validity = () => wrapper.emitted('update:payload').filter((patch) => '_step1Valid' in patch[0]).map((patch) => patch[0]._step1Valid)

		await wrapper.find('[data-testid="wizard-database-toggle"]').setValue(true)
		await wrapper.find('[data-testid="wizard-database-register"]').setValue('permits-db')
		await flushPromises()
		expect(wrapper.vm.isValid).toBe(false)

		await wrapper.find('[data-testid="wizard-database-table-applicants"]').setValue(true)
		await wrapper.find('[data-testid="wizard-database-table-permits"]').setValue(true)

		expect(wrapper.vm.isValid).toBe(true)
		expect(validity().at(-1)).toBe(true)
		expect(wrapper.props('payload').fromDatabase).toEqual({
			register: 'permits-db',
			schemas: ['applicants', 'permits'],
			tables: [{ slug: 'applicants', title: 'applicants' }, { slug: 'permits', title: 'Permits' }],
		})

		await wrapper.find('[data-testid="wizard-database-table-applicants"]').setValue(false)
		expect(wrapper.props('payload').fromDatabase.schemas).toEqual(['permits'])

		await wrapper.find('[data-testid="wizard-database-toggle"]').setValue(false)
		expect(wrapper.props('payload').fromDatabase).toBeNull()
		expect(wrapper.vm.isValid).toBe(true)
	})

	it('names a table by its title, else its slug', () => {
		expect(tableOption({ slug: 'permits', title: 'Permits' })).toEqual({ slug: 'permits', title: 'Permits' })
		expect(tableOption({ id: 4 })).toEqual({ slug: '4', title: '4' })
	})

	it('reloads the tables of a database already picked', async () => {
		const wrapper = mount(StartFromDatabase, {
			props: { payload: { fromDatabase: { register: 'permits-db', schemas: ['permits'], tables: [] } } },
		})
		await flushPromises()
		expect(wrapper.find('[data-testid="wizard-database-table-permits"]').element.checked).toBe(true)
	})
})

describe('Start from a database — review and submit', () => {
	it('lists the pages each ticked table gets', () => {
		const wrapper = mount(Step4Review, {
			props: {
				payload: {
					name: 'Vergunningen',
					slug: 'vergunningen',
					versions: [{ slug: 'production', name: 'Production' }],
					fromDatabase: { register: 'permits-db', schemas: ['permits', 'applicants'], tables: [{ slug: 'permits', title: 'Permits' }] },
				},
			},
		})
		expect(wrapper.findAll('[data-testid="wizard-review-database"] li')).toHaveLength(2)
		expect(wrapper.vm.databasePages).toEqual([
			{ slug: 'permits', title: 'Permits' },
			{ slug: 'applicants', title: 'applicants' },
		])
	})

	it('lists no database pages on the default path', () => {
		const wrapper = mount(Step4Review, { props: { payload: { name: 'X', slug: 'x', versions: [] } } })
		expect(wrapper.find('[data-testid="wizard-review-database"]').exists()).toBe(false)
	})

	it('posts the database and its tables, without the titles kept for the review', async () => {
		axios.post.mockResolvedValue({ status: 201, data: { applicationUuid: 'uuid-1' } })
		const wrapper = mount(CreateApplicationWizard, {
			props: { show: true },
			global: { stubs: { CnWizardDialog: true } },
		})

		await wrapper.vm.onSubmit({
			name: 'Vergunningen',
			slug: 'vergunningen',
			description: '',
			preset: 'single',
			versions: [],
			fromDatabase: { register: 'permits-db', schemas: ['permits'], tables: [{ slug: 'permits', title: 'Permits' }] },
		})

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/wizard', {
			name: 'Vergunningen',
			slug: 'vergunningen',
			description: '',
			preset: 'single',
			versions: [],
			fromDatabase: { register: 'permits-db', schemas: ['permits'] },
		})
	})
})
