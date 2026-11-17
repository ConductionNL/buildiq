// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * aiSteps — the Ask AI step's catalogue check, agent list and stored shape
 * (ai-llm-steps-and-computed-fields REQ-BQLS-001, REQ-BQLS-005).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import axios from '@nextcloud/axios'
import {
	aiStepIncomplete,
	aiStepSummary,
	aiStepToEditor,
	fetchAgentNodeAvailable,
	fetchToolFreeAgents,
	toPersistedAiStep,
} from '../../src/services/aiSteps.js'

describe('aiSteps', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('REQ-BQLS-005: reads the node catalogue and fails closed', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'hermiq.agent-step' }] } })
		expect(await fetchAgentNodeAvailable()).toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/flow/node-catalog')

		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'openregister.filter' }] } })
		expect(await fetchAgentNodeAvailable()).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('down'))
		expect(await fetchAgentNodeAvailable()).toBe(false)
	})

	it('REQ-BQLS-001: lists active agents without tools only', async () => {
		axios.get.mockResolvedValueOnce({
			data: [
				{ '@self': { id: 'ag-1' }, name: 'Classifier', tools: [] },
				{ uuid: 'ag-2', name: 'Actor', tools: ['x.y.z'] },
				{ uuid: 'ag-3', name: 'Off', active: false },
				{ name: 'No id' },
			],
		})

		expect(await fetchToolFreeAgents()).toEqual([{ id: 'ag-1', label: 'Classifier' }])
	})

	it('stores only the keys the preset reads', () => {
		const editor = aiStepToEditor({ type: 'ai-step', preset: 'extract', agent: ' ag-1 ', sourceFields: ['brief', 'brief', ' '], fields: ['naam'] })

		expect(toPersistedAiStep({ ...editor, targetField: 'ignored', labels: ['x'] })).toEqual({
			type: 'ai-step',
			preset: 'extract',
			agent: 'ag-1',
			sourceFields: ['brief'],
			fields: ['naam'],
		})
		expect(toPersistedAiStep({ ...editor, preset: 'prompt', targetField: 'antwoord', prompt: ' Answer {{brief}} ' })).toEqual({
			type: 'ai-step',
			preset: 'prompt',
			agent: 'ag-1',
			sourceFields: ['brief'],
			targetField: 'antwoord',
			prompt: 'Answer {{brief}}',
		})
		expect(aiStepToEditor({ preset: 'search-web' }).preset).toBe('classify')
	})

	it('says what a step still needs, then nothing', () => {
		const step = { preset: 'classify', agent: 'ag-1', sourceFields: ['omschrijving'], targetField: 'categorie', labels: [] }

		expect(aiStepIncomplete({ ...step, agent: '' })).toBe('Pick the Hermiq agent that runs the AI step.')
		expect(aiStepIncomplete({ ...step, sourceFields: [] })).toBe('Pick at least one field the AI step reads.')
		expect(aiStepIncomplete(step)).toBe('Add the labels the AI step picks from.')
		expect(aiStepIncomplete({ ...step, labels: ['afval'] })).toBe('')
		expect(aiStepIncomplete({ ...step, preset: 'extract', fields: [] })).toBe('Pick the fields the AI step extracts.')
		expect(aiStepIncomplete({ ...step, preset: 'prompt', prompt: ' ' })).toBe('Write the instruction the AI step follows.')
		expect(aiStepSummary({ sourceFields: ['omschrijving', 'locatie'] })).toBe('Sends omschrijving, locatie to the AI')
		expect(aiStepSummary({ sourceFields: [] })).toBe('')
	})
})
