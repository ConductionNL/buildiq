/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuildiqLogsPage: a logs page bound to a schema lists the
 * recent changes to that schema's records, newest first
 * (capability-requirements-backfill T10, REQ-BQCV-010).
 *
 * CnLogsPage is replaced by a stand-in that, like the real one in `source`
 * mode, GETs its `source` and lists the rows in the order they arrive.
 * OpenRegister's caller-scoped audit trail answers newest first.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGet(...args) },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const CnLogsPageStandIn = {
	name: 'CnLogsPage',
	props: {
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
		source: { type: String, default: '' },
		columns: { type: Array, default: () => [] },
		title: { type: String, default: '' },
	},
	data() {
		return { rows: [] }
	},
	async mounted() {
		if (this.source && !(this.register && this.schema)) {
			const { data } = await axiosGet(this.source)
			this.rows = data.results
		}
	},
	render() {
		return h('ul', this.rows.map((row) => h('li', `${row.created} ${row.userName} ${row.action}`)))
	},
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnLogsPage: CnLogsPageStandIn }))

const { default: BuildiqLogsPage } = await import('../../../src/components/runtime/BuildiqLogsPage.vue')

const FEED = '/index.php/apps/openregister/api/audit-trails/readable?register=7&schema=12&limit=100'
const CHANGES = [
	{ created: '2026-11-24T10:15:00+00:00', userName: 'Sanne', action: 'update', objectUuid: 'p-2' },
	{ created: '2026-11-23T09:00:00+00:00', userName: 'Olaf', action: 'create', objectUuid: 'p-1' },
]

/**
 * Answer the register and schema reads and the feed.
 *
 * @param {object} options What fails.
 * @return {void}
 */
function answer({ schemaMissing = false } = {}) {
	axiosGet.mockImplementation((url) => {
		if (url === '/index.php/apps/openregister/api/registers/permits-register') {
			return Promise.resolve({ data: { id: 7, slug: 'permits-register' } })
		}
		if (url === '/index.php/apps/openregister/api/schemas/permit') {
			return schemaMissing ? Promise.reject(new Error('404')) : Promise.resolve({ data: { id: 12, slug: 'permit' } })
		}
		if (url === FEED) {
			return Promise.resolve({ data: { results: CHANGES, nextCursor: null } })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

describe('BuildiqLogsPage (REQ-BQCV-010)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
	})

	it('lists the recent changes to permits, newest first', async () => {
		answer()
		const wrapper = mount(BuildiqLogsPage, { props: { register: 'permits-register', schema: 'permit', title: 'Activity' } })
		await flushPromises()

		const page = wrapper.findComponent(CnLogsPageStandIn)
		expect(page.props('source')).toBe(FEED)
		expect(page.props('register')).toBe('')
		expect(page.props('schema')).toBe('')
		expect(page.props('title')).toBe('Activity')
		expect(page.props('columns').map((column) => column.key)).toEqual(['created', 'userName', 'action', 'objectUuid'])
		expect(wrapper.findAll('li').map((li) => li.text())).toEqual([
			'2026-11-24T10:15:00+00:00 Sanne update',
			'2026-11-23T09:00:00+00:00 Olaf create',
		])
	})

	it('uses numeric ids as they are', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = mount(BuildiqLogsPage, { props: { register: '7', schema: '12' } })
		await flushPromises()

		expect(wrapper.findComponent(CnLogsPageStandIn).props('source')).toBe(FEED)
		expect(axiosGet).toHaveBeenCalledTimes(1)
	})

	it('falls back to the schema\'s records when the schema cannot be resolved', async () => {
		answer({ schemaMissing: true })
		const columns = [{ key: 'title', label: 'Title' }]
		const wrapper = mount(BuildiqLogsPage, { props: { register: 'permits-register', schema: 'permit', columns } })
		await flushPromises()

		const page = wrapper.findComponent(CnLogsPageStandIn)
		expect([page.props('register'), page.props('schema'), page.props('source')]).toEqual(['permits-register', 'permit', ''])
		expect(page.props('columns')).toEqual(columns)
	})

	it('keeps a source the maker set and resolves nothing', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = mount(BuildiqLogsPage, { props: { source: '/apps/other/api/logs' } })
		await flushPromises()

		expect(wrapper.findComponent(CnLogsPageStandIn).props('source')).toBe('/apps/other/api/logs')
		expect(axiosGet).toHaveBeenCalledWith('/apps/other/api/logs')
		expect(axiosGet).toHaveBeenCalledTimes(1)
	})
})
