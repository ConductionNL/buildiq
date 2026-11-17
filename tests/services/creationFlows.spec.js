/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * creationFlows (forms-multi-product-request D5): which flows start on the
 * creation of a record in a product's target.
 *
 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-designer-says-whether-a-part-starts-a-process-req-bqmp-004
 */

import { describe, expect, it, vi } from 'vitest'
import { creationFlowsFor, loadFlows } from '../../src/services/creationFlows.js'

const target = { register: 'zaken', schema: 'zaak' }

describe('creationFlowsFor', () => {
	it('keeps the flows triggered by a created record in the target', () => {
		const flows = [
			{ name: 'own trigger', trigger: { type: 'object.created', register: 'zaken', schema: 'zaak' } },
			{ name: 'trigger node', nodes: [{ type: 'trigger', config: { event: 'object-created', schema: 'zaak' } }, { type: 'action' }] },
			{ name: 'updated', trigger: { type: 'object.updated', register: 'zaken', schema: 'zaak' } },
			{ name: 'other schema', trigger: { type: 'object.created', register: 'zaken', schema: 'zaak-afval' } },
			{ name: 'other register', trigger: { type: 'object.created', register: 'crm', schema: 'zaak' } },
			{ name: 'any schema', trigger: { type: 'object.created' } },
			{ name: 'manual', trigger: { type: 'manual' } },
		]

		expect(creationFlowsFor(flows, target).map((flow) => flow.name)).toEqual(['own trigger', 'trigger node'])
	})

	it('finds nothing for a target without a schema, or without flows', () => {
		expect(creationFlowsFor([{ trigger: { type: 'object.created', schema: 'zaak' } }], { register: 'zaken', schema: '' })).toEqual([])
		expect(creationFlowsFor(null, target)).toEqual([])
	})
})

describe('loadFlows', () => {
	it('reads OpenRegister\'s flow list, paged or plain', async () => {
		const paged = { get: vi.fn(() => Promise.resolve({ data: { results: [{ id: 1 }] } })) }
		expect(await loadFlows(paged)).toEqual([{ id: 1 }])
		expect(paged.get.mock.calls[0][0]).toContain('/apps/openregister/api/flows')

		const plain = { get: vi.fn(() => Promise.resolve({ data: [{ id: 2 }] })) }
		expect(await loadFlows(plain)).toEqual([{ id: 2 }])
	})
})
