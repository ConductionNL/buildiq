/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation of `ImportDataWizard::detectedType`: the import
 * type shown for a picked file, from its extension.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const ImportDataWizard = (await import('../../src/dialogs/ImportDataWizard.vue'))
	.default

/**
 * Mount the wizard with a stub import client.
 *
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountWizard() {
	return shallowMount(ImportDataWizard, {
		propsData: {
			registerId: 'openbuild-app-staging',
			schemas: [],
			importClient: {},
		},
	})
}

describe('ImportDataWizard detectedType', () => {
	it.each([
		['data.xlsx', 'excel'],
		['DATA.XLS', 'excel'],
		['list.csv', 'csv'],
		['dump.v2.JSON', 'json'],
		['notes.txt', ''],
		['noextension', ''],
		['', ''],
		[undefined, ''],
	])('reads %j as %j', (name, type) => {
		const wrapper = mountWizard()
		wrapper.vm.file = { name }
		expect(wrapper.vm.detectedType).toBe(type)
	})

	it('is empty without a file', () => {
		expect(mountWizard().vm.detectedType).toBe('')
	})
})
