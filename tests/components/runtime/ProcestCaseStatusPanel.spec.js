/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation of `ProcestCaseStatusPanel::currentStatus`: the
 * status name a linked Procest case shows, and its fallbacks.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const { caseDetail } = vi.hoisted(() => ({ caseDetail: { current: null } }))

vi.mock('../../../src/composables/useProcestCase.js', () => ({
	useProcestCase: () => ({
		loadingDetail: ref(false),
		detailError: ref(''),
		noAccess: ref(false),
		starting: ref(false),
		startError: ref(''),
		statusHistory: ref([]),
		caseDetail: ref(caseDetail.current),
	}),
}))

import ProcestCaseStatusPanel from '../../../src/components/runtime/ProcestCaseStatusPanel.vue'

/**
 * Mount the panel over a case detail.
 *
 * @param {object|null} detail The case detail.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountPanel(detail) {
	caseDetail.current = detail
	return shallowMount(ProcestCaseStatusPanel, {
		props: { object: {}, attachment: {} },
		global: { stubs: { NcButton: true } },
	})
}

describe('ProcestCaseStatusPanel currentStatus', () => {
	it.each([
		[{ statusName: 'Open', status: { statustypeOmschrijving: 'S', naam: 'N' } }, 'Open'],
		[{ status: { statustypeOmschrijving: 'S', naam: 'N' } }, 'S'],
		[{ status: { naam: 'N' } }, 'N'],
		[{ status: {} }, 'Unknown'],
		[{ status: 'open' }, 'Unknown'],
		[{}, 'Unknown'],
		[null, 'Unknown'],
	])('shows the status of %j as %j', (detail, status) => {
		expect(mountPanel(detail).vm.currentStatus).toBe(status)
	})
})
