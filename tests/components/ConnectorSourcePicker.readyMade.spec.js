/**
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCB-005 (integrations-connector-bound-pages): an endpoint a ready-made
 * integriq connector provides is an ordinary integriq endpoint targeting that
 * connector's source, so the picker lists it next to hand-made endpoints,
 * named after the connector, and binds it the same way. The bound page then
 * renders through the same normaliser as any other connector-bound page.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const { default: axios } = await import('@nextcloud/axios')
const { default: ConnectorSourcePicker } =
	await import('../../src/components/page-editor/ConnectorSourcePicker.vue')
const { clearAppStatusCache } = await import('../../src/composables/useAppStatus.js')
const { normalizeConnectorPages } =
	await import('../../src/services/connectorPages.js')

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel', 'placeholder', 'label'],
	template: '<div class="ncselect-stub" />',
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const SHEET = { id: 'src-sheet', name: 'Spreadsheet' }
const HANDMADE = { id: 'src-erp', name: 'ERP' }
const ENDPOINTS = [
	{ id: 'e1', endpoint: 'budget-rows', targetType: 'api', targetId: 'src-sheet' },
	{ id: 'e2', endpoint: 'erp/orders', targetType: 'api', targetId: 'src-erp' },
]

async function mountPicker(binding = {}) {
	axios.get.mockImplementation(async (url) => {
		if (url === '/apps/openregister/api/objects/integriq/source') {
			return { data: { results: [SHEET, HANDMADE] } }
		}
		if (url === '/apps/openregister/api/objects/integriq/endpoint') {
			return { data: { results: ENDPOINTS } }
		}
		throw Object.assign(new Error('404'), { response: { status: 404 } })
	})
	const wrapper = mount(ConnectorSourcePicker, {
		propsData: { binding },
		stubs: { NcSelect: NcSelectStub },
	})
	await flush()
	await flush()
	return wrapper
}

describe('ready-made connector endpoints (REQ-BQCB-005)', () => {
	beforeEach(() => {
		clearAppStatusCache()
		axios.get.mockReset()
		global.OC = { appswebroots: { integriq: '/apps/integriq' } }
	})

	it('lists the connector endpoint with the connector name next to hand-made ones', async () => {
		const wrapper = await mountPicker()

		expect(wrapper.vm.endpointOptions).toEqual([
			{ label: 'budget-rows (Spreadsheet)', path: 'budget-rows' },
			{ label: 'erp/orders (ERP)', path: 'erp/orders' },
		])
	})

	it('binds the connector endpoint like any other', async () => {
		const wrapper = await mountPicker()

		wrapper.vm.onSelect({
			label: 'budget-rows (Spreadsheet)',
			path: 'budget-rows',
		})

		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([
			'budget-rows',
		])
	})

	it('renders a page bound to it as a connector list page', () => {
		const binding = {
			endpointPath: 'budget-rows',
			fields: { Post: 'post', Amount: 'amount' },
		}
		const out = normalizeConnectorPages({
			pages: [
				{
					id: 'budget',
					route: '/budget',
					title: 'Budget',
					type: 'index',
					config: { dataSource: { connector: binding } },
				},
			],
		})

		expect(out.pages[0].component).toBe('connector-list')
		expect(out.pages[0].config.dataSource.connector).toEqual(binding)
	})
})
