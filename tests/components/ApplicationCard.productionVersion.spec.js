// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['team-alpha'],
}))
vi.mock('../../src/store/productionVersions.js', async () => {
	const { reactive } = await import('vue')
	return {
		productionVersions: reactive({}),
		ensureProductionVersionsLoaded: vi.fn(),
	}
})

import ApplicationCard from '../../src/components/ApplicationCard.vue'
import { productionVersions } from '../../src/store/productionVersions.js'

/**
 * Pins ApplicationCard::productionVersion: an inline version object is used
 * as is, a UUID is resolved through the page-wide version index, anything
 * else is no version.
 */
function versionOf(productionVersion) {
	return shallowMount(ApplicationCard, {
		propsData: { object: { slug: 'x', productionVersion } },
		mocks: { t: (a, s) => s, $router: { push: vi.fn() } },
	}).vm.productionVersion
}

describe('ApplicationCard productionVersion', () => {
	afterEach(() => {
		Object.keys(productionVersions).forEach((k) => delete productionVersions[k])
	})

	it('uses an inline version object', () => {
		expect(versionOf({ status: 'published', semver: '1.0.0' })).toEqual({ status: 'published', semver: '1.0.0' })
	})

	it('resolves a UUID through the version index', () => {
		productionVersions['uuid-1'] = { semver: '2.0.0' }
		expect(versionOf('uuid-1')).toEqual({ semver: '2.0.0' })
	})

	it.each([['uuid-unknown'], [''], [null], [undefined], [42], [false]])('has no version for %j', (pv) => {
		expect(versionOf(pv)).toBe(null)
	})
})
