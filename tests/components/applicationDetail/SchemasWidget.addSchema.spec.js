// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: { results: [] } })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import SchemasWidget from '../../../src/components/applicationDetail/widgets/SchemasWidget.vue'

/**
 * Pins SchemasWidget::addSchema: a globally registered create-schema dialog
 * is opened with the app and version; without one the widget logs a debug
 * notice and emits `addSchema` instead.
 */
function mountWidget() {
	return shallowMount(SchemasWidget, {
		propsData: { appSlug: 'hello', versionSlug: 'production', schemas: [] },
		mocks: { t: (a, s) => s, n: (a, s, p, c) => (c === 1 ? s : p) },
	})
}

describe('SchemasWidget addSchema', () => {
	afterEach(() => {
		delete window.buildiq
		vi.restoreAllMocks()
	})

	it('opens the registered dialog and emits nothing', () => {
		const opener = vi.fn()
		window.buildiq = { openAddSchemaDialog: opener }
		const wrapper = mountWidget()
		wrapper.vm.addSchema()
		expect(opener).toHaveBeenCalledWith({ appSlug: 'hello', versionSlug: 'production' })
		expect(wrapper.emitted('addSchema')).toBeUndefined()
	})

	it.each([[undefined], [{}], [{ openAddSchemaDialog: 'nope' }]])(
		'logs and emits when the dialog is not registered (%j)',
		(registry) => {
			if (registry !== undefined) window.buildiq = registry
			const debug = vi.spyOn(console, 'debug').mockImplementation(() => {})
			const wrapper = mountWidget()
			wrapper.vm.addSchema()
			expect(debug).toHaveBeenCalledWith(
				'buildiq: schema-create dialog not yet registered — deferred to schema-designer spec',
			)
			expect(wrapper.emitted('addSchema')[0][0]).toEqual({ appSlug: 'hello', versionSlug: 'production' })
		},
	)
})
