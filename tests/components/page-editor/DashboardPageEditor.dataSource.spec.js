/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DashboardPageEditor's data-source round-trip.
 *
 * Covers:
 *  - a data source is written onto the config, keeping the other keys.
 *  - an empty or missing data source removes the key.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DashboardPageEditor from '../../../src/components/page-editor/DashboardPageEditor.vue'

function mountEditor(config) {
	return shallowMount(DashboardPageEditor, {
		props: { config, pageType: 'dashboard', appSlug: 'hello-world', parentRoute: '/' },
		global: {
			mocks: {
				$validator: {
					register: vi.fn(),
					unregister: vi.fn(),
					errorsForPathPrefix: () => ({ errors: [], warnings: [] }),
					summaryForPathPrefix: () => null,
				},
			},
		},
	})
}

describe('DashboardPageEditor data source', () => {
	it('writes the data source next to the other keys', () => {
		const config = { widgets: [{ id: 'w1' }] }
		const wrapper = mountEditor(config)
		const dataSource = { connector: { endpointPath: 'cases' } }

		wrapper.vm.onDataSourceUpdate(dataSource)

		expect(wrapper.emitted('update:config')[0][0]).toEqual({ widgets: [{ id: 'w1' }], dataSource })
		expect(config).not.toHaveProperty('dataSource')
	})

	it('an empty data source removes the key', () => {
		const wrapper = mountEditor({ widgets: [{ id: 'w1' }], dataSource: { register: 'r' } })

		wrapper.vm.onDataSourceUpdate({})
		wrapper.vm.onDataSourceUpdate(null)

		const events = wrapper.emitted('update:config')
		expect(events[0][0]).toEqual({ widgets: [{ id: 'w1' }] })
		expect(events[1][0]).toEqual({ widgets: [{ id: 'w1' }] })
	})
})
