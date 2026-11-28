/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for sharedAutomations: the automations a step may run and the
 * shared automations an app's flow picker lists.
 *
 * Spec: logic-shared-flows-and-automations (REQ-BQSF-003, REQ-BQSF-004).
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import {
	automationId,
	compiledFlowOf,
	fetchAutomations,
	isPickable,
	runAutomationOptions,
	sharedFlowOptions,
	withSharedGroup,
} from '../../src/services/sharedAutomations.js'

const AUTOMATIONS = [
	{ id: 'own', name: 'Intake', applicationSlug: 'permits', shared: false },
	{ id: 'b', name: 'Bevestig', applicationSlug: 'permits', shared: false },
	{ id: 'a', name: 'Archiveer', applicationSlug: 'permits', shared: true, provenance: { flowUuid: 'flow-a' } },
	{ id: 'c', name: 'Controleer adres', applicationSlug: 'subsidies', shared: true, provenance: { flowUuid: 'flow-c' } },
	{ id: 'd', name: 'Hidden', applicationSlug: 'subsidies', shared: false, provenance: { flowUuid: 'flow-d' } },
	{ id: 'e', name: 'Uncompiled', applicationSlug: 'subsidies', shared: true },
	{ name: 'No id', applicationSlug: 'permits' },
]

describe('runAutomationOptions', () => {
	it('lists the app\'s own automations first, then shared ones of other apps, never itself', () => {
		expect(runAutomationOptions(AUTOMATIONS, { applicationSlug: 'permits', ownId: 'own' })).toEqual([
			{ id: 'a', label: 'Archiveer', shared: false },
			{ id: 'b', label: 'Bevestig', shared: false },
			{ id: 'c', label: 'Controleer adres (subsidies)', shared: true },
			{ id: 'e', label: 'Uncompiled (subsidies)', shared: true },
		])
	})

	it('offers nothing for a malformed list', () => {
		expect(runAutomationOptions(null)).toEqual([])
	})
})

describe('sharedFlowOptions and withSharedGroup', () => {
	it('lists the compiled flows of other apps\' shared automations with their app', () => {
		expect(sharedFlowOptions(AUTOMATIONS, 'permits')).toEqual([
			{ label: 'Controleer adres (subsidies)', value: 'flow-c', shared: true },
		])
	})

	it('groups them after the plain flows under a heading, listing a shared flow once', () => {
		const flows = [{ label: 'One', value: 'f1' }, { label: 'aut-check', value: 'flow-c' }]
		const options = withSharedGroup(flows, sharedFlowOptions(AUTOMATIONS, 'permits'), 'Shared automations')

		expect(options).toEqual([
			{ label: 'One', value: 'f1' },
			{ label: 'Shared automations', value: '', heading: true },
			{ label: 'Controleer adres (subsidies)', value: 'flow-c', shared: true },
		])
		expect(options.filter(isPickable).map((o) => o.value)).toEqual(['f1', 'flow-c'])
	})

	it('adds no heading without shared automations', () => {
		const flows = [{ label: 'One', value: 'f1' }]
		expect(withSharedGroup(flows, [], 'Shared automations')).toBe(flows)
	})
})

describe('record helpers', () => {
	it('reads the id from id, uuid or @self, and the compiled flow from provenance', () => {
		expect(automationId({ uuid: 'u' })).toBe('u')
		expect(automationId({ '@self': { id: 's' } })).toBe('s')
		expect(automationId(null)).toBe('')
		expect(compiledFlowOf({ provenance: { flowUuid: 'f' } })).toBe('f')
		expect(compiledFlowOf({ provenance: 'x' })).toBe('')
	})

	it('reads the automations from OpenRegister', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'a' }] } })

		expect(await fetchAutomations()).toEqual([{ id: 'a' }])
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/automation', { params: { _limit: 500 } })
	})
})
