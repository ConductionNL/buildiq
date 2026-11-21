/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for src/services/scriptSteps.js (logic-script-step, REQ-BQSS-001).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import axios from '@nextcloud/axios'
import {
	CODE_NODE,
	DEFAULT_TIMEOUT,
	fetchCodeNodeAvailable,
	scriptStepDisabledReason,
	scriptStepIncomplete,
	scriptStepsAllowed,
	scriptStepToEditor,
	toPersistedScriptStep,
} from '../../src/services/scriptSteps.js'

/**
 * Put an initial-state value on the page, as Nextcloud's template does.
 *
 * @param {string} key The state key.
 * @param {unknown} value The value.
 * @return {void}
 */
function provide(key, value) {
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = `initial-state-buildiq-${key}`
	input.value = btoa(JSON.stringify(value))
	document.body.appendChild(input)
}

describe('scriptSteps', () => {
	afterEach(() => {
		document.body.innerHTML = ''
		axios.get.mockReset()
	})

	it('reads the instance setting from the page, off when it is not there', () => {
		expect(scriptStepsAllowed()).toBe(false)
		provide('scriptStepsAllowed', true)
		expect(scriptStepsAllowed()).toBe(true)
	})

	it('finds the code step in the node catalogue, and answers false when the read fails', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'openregister.object-write' }, { type: CODE_NODE }] } })
		expect(await fetchCodeNodeAvailable()).toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/flow/node-catalog')

		axios.get.mockResolvedValueOnce({ data: [{ id: 'openregister.object-write' }] })
		expect(await fetchCodeNodeAvailable()).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('down'))
		expect(await fetchCodeNodeAvailable()).toBe(false)
	})

	it('names the setting before the runner, and nothing when both are there', () => {
		expect(scriptStepDisabledReason({ allowed: false, runnerAvailable: false })).toBe('Script steps are turned off for this instance.')
		expect(scriptStepDisabledReason({ allowed: true, runnerAvailable: false })).toBe('The code runner is not installed, so scripts cannot run.')
		expect(scriptStepDisabledReason({ allowed: true, runnerAvailable: true })).toBe('')
	})

	it('reads a stored step into the editor with the default time limit', () => {
		expect(scriptStepToEditor({ type: 'script-step', code: 'return items', timeoutSeconds: 5, note: 'n' })).toEqual({ code: 'return items', timeoutSeconds: 5, note: 'n' })
		expect(scriptStepToEditor({ type: 'webhook' })).toEqual({ code: '', timeoutSeconds: DEFAULT_TIMEOUT, note: '' })
		expect(scriptStepToEditor(null)).toEqual({ code: '', timeoutSeconds: DEFAULT_TIMEOUT, note: '' })
	})

	it('refuses an empty script and a time limit outside 1 to 30 whole seconds', () => {
		expect(scriptStepIncomplete({ code: ' ' })).toBe('Write the script before saving.')
		expect(scriptStepIncomplete({ code: 'return items', timeoutSeconds: 0 })).toBe('A script may run between 1 and 30 seconds.')
		expect(scriptStepIncomplete({ code: 'return items', timeoutSeconds: 31 })).toBe('A script may run between 1 and 30 seconds.')
		expect(scriptStepIncomplete({ code: 'return items', timeoutSeconds: 2.5 })).toBe('A script may run between 1 and 30 seconds.')
		expect(scriptStepIncomplete({ code: 'return items', timeoutSeconds: '' })).toBe('')
		expect(scriptStepIncomplete({ code: 'return items', timeoutSeconds: 30 })).toBe('')
	})

	it('stores the code and time limit, and the note only when there is one', () => {
		expect(toPersistedScriptStep({ type: 'script-step', code: 'return items', timeoutSeconds: 3, note: '  Uppercases postcode ' })).toEqual({ type: 'script-step', code: 'return items', timeoutSeconds: 3, note: 'Uppercases postcode' })
		expect(toPersistedScriptStep({ code: 'return items', timeoutSeconds: '', note: '' })).toEqual({ type: 'script-step', code: 'return items', timeoutSeconds: DEFAULT_TIMEOUT })
	})
})
