/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCV-016: an installed app the organisation customised (a hybrid
 * Application, `appType: 'hybrid'`) is listed beside the apps it built, marked
 * hybrid, and counted beside them.
 *
 * Both the dashboard (Apps and Hybrid apps tiles, Recent apps table) and the
 * Apps index (one ApplicationCard per `built-app` row, no appType filter)
 * read the same `built-app` schema, so a hybrid app shows up in both.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/unified-app-model/spec.md#requirement-customised-installed-apps-are-listed-beside-built-apps-and-marked-hybrid-req-bqcv-016
 */
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, vars = {}) => p.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => [] }))

import axios from '@nextcloud/axios'
import ApplicationCard from '../../src/components/ApplicationCard.vue'
import DashboardIndex from '../../src/views/DashboardIndex.vue'

const VIRTUAL = { slug: 'permits', name: 'Permits', appType: 'virtual', '@self': { id: 'uuid-v' } }
const HYBRID = { slug: 'zaken', name: 'Zaken (customised)', appType: 'hybrid', '@self': { id: 'uuid-h' } }

/**
 * Answer the dashboard's reads from a register holding the given apps.
 *
 * @param {Array<object>} apps The `built-app` rows.
 * @return {void}
 */
function registerHolding(apps) {
	axios.get.mockImplementation((url, { params }) => {
		if (url.endsWith('/applicationVersion')) {
			return Promise.resolve({ data: { total: 0 } })
		}
		const rows = params.appType ? apps.filter((a) => a.appType === params.appType) : apps
		if (params._limit === 1) {
			return Promise.resolve({ data: { total: rows.length } })
		}
		return Promise.resolve({ data: { results: rows } })
	})
}

const dashboardStubs = {
	CnDashboardPage: {
		template: '<div><slot name="widget-apps" /><slot name="widget-hybrid" /><slot name="widget-recent-apps" /></div>',
	},
	KpiLinkTile: {
		props: ['title', 'count'],
		template: '<div class="tile" :data-title="title">{{ count }}</div>',
	},
	CnDataTable: {
		props: ['rows'],
		template: '<div><div v-for="row in rows" :key="row.slug" class="row" :data-slug="row.slug">{{ row.name }}|{{ row.typeLabel }}</div></div>',
	},
	NcButton: true,
	NcEmptyContent: true,
	CreateApplicationWizard: true,
	PencilOutline: true,
}

beforeEach(() => {
	axios.get.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('REQ-BQCV-016 a customised installed app shows in the overview', () => {
	it('lists both apps, labels only the hybrid one Hybrid, and the hybrid count reads 1', async () => {
		registerHolding([VIRTUAL, HYBRID])
		const wrapper = mount(DashboardIndex, { global: { stubs: dashboardStubs, mocks: { $router: { push: vi.fn() } } } })
		await flushPromises()

		const rows = wrapper.findAll('.row')
		expect(rows.map((r) => r.attributes('data-slug'))).toEqual(['permits', 'zaken'])
		expect(rows.map((r) => r.text())).toEqual(['Permits|Virtual', 'Zaken (customised)|Hybrid'])

		expect(wrapper.find('.tile[data-title="Hybrid apps"]').text()).toBe('1')
		expect(wrapper.find('.tile[data-title="Apps"]').text()).toBe('2')
	})

	it('on the Apps index, only the hybrid card carries the hybrid label', () => {
		const card = (object) => shallowMount(ApplicationCard, {
			props: { object },
			global: { mocks: { $router: { push: vi.fn() }, $route: { query: {} } } },
		})

		const virtualPill = card(VIRTUAL).find('.ob-app-card__type')
		const hybridPill = card(HYBRID).find('.ob-app-card__type')

		expect(hybridPill.text()).toBe('Hybrid')
		expect(hybridPill.classes()).toContain('ob-app-card__type--hybrid')
		expect(virtualPill.text()).toBe('Virtual')
		expect(virtualPill.classes()).not.toContain('ob-app-card__type--hybrid')
	})

	it('the Apps index lists every built-app row, with no filter that would leave hybrid apps out', () => {
		const { pages } = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const apps = pages.find((page) => page.id === 'VirtualApps')

		expect(apps.config.schema).toBe('built-app')
		expect(apps.config.cardComponent).toBe('ApplicationCard')
		expect(apps.config.filter ?? apps.config.filters ?? null).toBeNull()
	})
})
