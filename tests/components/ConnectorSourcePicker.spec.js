import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ConnectorSourcePicker.vue.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-002, REQ-OCAS-004, REQ-OCAS-005).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import ConnectorSourcePicker from '../../src/components/page-editor/ConnectorSourcePicker.vue'
import { clearAppStatusCache } from '../../src/composables/useAppStatus.js'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel', 'placeholder', 'label'],
	template:
		'<div class="ncselect-stub" :data-label="inputLabel">{{ JSON.stringify(options) }}</div>',
}

const flush = () => new Promise((r) => setTimeout(r, 0))

describe('ConnectorSourcePicker', () => {
	beforeEach(() => {
		clearAppStatusCache()
		axios.get.mockReset()

		global.OC = { appswebroots: { openconnector: '/apps/openconnector' } }
	})

	it('lists endpoints with path + source name only, never credentials', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				results: [
					{
						path: 'kvk/companies',
						sourceName: 'KvK',
						apiKey: 'SECRET',
						token: 'SECRET2',
					},
				],
			},
		})
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		const html = wrapper.html()
		expect(html).toContain('kvk/companies')
		expect(html).toContain('KvK')
		expect(html).not.toContain('SECRET')
	})

	it('carries an inputLabel on NcSelect (a11y gate)', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		expect(wrapper.find('.ncselect-stub').attributes('data-label')).toBeTruthy()
	})

	it('degrades to a manual escape hatch when OpenConnector is absent', async () => {
		global.OC = { appswebroots: {} }
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		expect(wrapper.find('.connector-source-picker__manual').exists()).toBe(true)
		const input = wrapper.find('input[type="text"]')
		await input.setValue('kvk/companies')
		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([
			'kvk/companies',
		])
	})

	it('strips scheme/host from a manually entered path', async () => {
		global.OC = { appswebroots: {} }
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		const input = wrapper.find('input[type="text"]')
		await input.setValue('https://evil.example/kvk/companies')
		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([
			'kvk/companies',
		])
	})
})
