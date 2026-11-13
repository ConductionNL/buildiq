// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: { results: [] } })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import SchemasWidget from '../../../src/components/applicationDetail/widgets/SchemasWidget.vue'

/**
 * Pins SchemasWidget::openSchema: the row's id, else uuid, else slug, names the
 * schema in the designer route, and a row with none of them navigates nowhere.
 */
function mountWidget(push) {
	return shallowMount(SchemasWidget, {
		propsData: { appSlug: 'hello', versionSlug: '', schemas: [] },
		mocks: {
			t: (a, s) => s,
			n: (a, s, p, c) => (c === 1 ? s : p),
			$router: { push },
		},
	})
}

describe('SchemasWidget openSchema', () => {
	it('does not navigate for a row without id, uuid or slug', () => {
		const push = vi.fn()
		mountWidget(push).vm.openSchema({ title: 'Nameless' })

		expect(push).not.toHaveBeenCalled()
	})

	it('falls back to the uuid, then the slug, as the schema id', () => {
		const push = vi.fn()
		const vm = mountWidget(push).vm

		vm.openSchema({ uuid: 'abc-uuid', slug: 'ignored' })
		vm.openSchema({ slug: 'person' })

		expect(push).toHaveBeenCalledTimes(2)
		expect(push.mock.calls[0][0].params).toEqual({ slug: 'hello', schemaId: 'abc-uuid' })
		expect(push.mock.calls[1][0].params).toEqual({ slug: 'hello', schemaId: 'person' })
		expect(push.mock.calls[0][0].name).toBe('SchemaDesigner')
	})
})
