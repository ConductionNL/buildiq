/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the copilot panel and the generate-app dialog flatten the
 * validator messages of a plan's predicted manifests: in first-seen order,
 * de-duplicated, keeping only non-empty strings and skipping missing lists.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: {} }), post: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import CopilotPanel from '../../src/components/copilot/CopilotPanel.vue'
import CopilotGenerateDialog from '../../src/dialogs/CopilotGenerateDialog.vue'

const ERRORS = new Map([
	['app-a', ['/pages/0: bad type', '', 7, '/menu: missing']],
	['app-b', null],
	['app-c', ['/menu: missing', '/pages/1: no id']],
])
const EXPECTED = ['/pages/0: bad type', '/menu: missing', '/pages/1: no id']

describe.each([
	['CopilotPanel', CopilotPanel, { appSlug: 'tool-library' }, 'validationErrors'],
	['CopilotGenerateDialog', CopilotGenerateDialog, { open: false }, 'allValidationErrors'],
])('%s validator messages', (_name, Component, props, computedName) => {
	it('flattens and de-duplicates the predicted manifests’ messages', () => {
		const wrapper = shallowMount(Component, { props })
		expect(wrapper.vm[computedName]).toEqual([])

		wrapper.vm.copilot.manifestErrors.value = ERRORS
		expect(wrapper.vm[computedName]).toEqual(EXPECTED)
	})
})
