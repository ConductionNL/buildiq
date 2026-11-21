// SPDX-License-Identifier: EUPL-1.2
/**
 * scriptSteps — the "Run a script" step of the automation composer (logic-script-step).
 *
 *  - The step holds JavaScript, the body of a function `(items) => items`, a
 *    timeout of 1 to 30 seconds and a note saying what it does (D1).
 *  - It runs only in OpenRegister's code runner (`openregister.code`); buildiq
 *    never evaluates it, not here and not in the browser (D4).
 *  - It is offered only while the instance setting "Allow script steps" is on
 *    and OpenRegister's node catalogue lists the code step (REQ-BQSS-001).
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The action kind. */
export const SCRIPT_STEP = 'script-step'

/** The node OpenRegister's code runner contributes to the flow engine. */
export const CODE_NODE = 'openregister.code'

/** The timeout bounds, in seconds (D1). */
export const MIN_TIMEOUT = 1
export const MAX_TIMEOUT = 30
export const DEFAULT_TIMEOUT = 10

/**
 * Whether the instance allows script steps, as the page was told on load.
 *
 * @return {boolean} True when "Allow script steps" is on.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export function scriptStepsAllowed() {
	try {
		return loadState('buildiq', 'scriptStepsAllowed', false) === true
	} catch {
		return false
	}
}

/**
 * Whether OpenRegister's node catalogue lists the code step. A failed read
 * answers false: the kind stays disabled rather than failing on save.
 *
 * @return {Promise<boolean>} Whether the code runner is there.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export async function fetchCodeNodeAvailable() {
	try {
		const { data } = await axios.get(generateUrl('/apps/openregister/api/flow/node-catalog'))
		const rows = Array.isArray(data && data.results) ? data.results : (Array.isArray(data) ? data : [])
		return rows.some((node) => node && (node.id === CODE_NODE || node.type === CODE_NODE))
	} catch {
		return false
	}
}

/**
 * Why the kind is disabled, or '' when it may be used. The setting comes
 * first: with scripts off, whether the runner is there does not matter.
 *
 * @param {object} support What the instance offers.
 * @param {boolean} support.allowed Whether "Allow script steps" is on.
 * @param {boolean} support.runnerAvailable Whether the code runner is installed.
 * @return {string} The reason, or ''.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export function scriptStepDisabledReason({ allowed, runnerAvailable }) {
	if (allowed !== true) {
		return t('buildiq', 'Script steps are turned off for this instance.')
	}
	if (runnerAvailable !== true) {
		return t('buildiq', 'The code runner is not installed, so scripts cannot run.')
	}
	return ''
}

/**
 * A stored timeout as a whole number of seconds, the default when absent.
 *
 * @param {unknown} value The stored or typed timeout.
 * @return {number} The seconds; NaN when it is not a number.
 */
function timeoutOf(value) {
	if (value === undefined || value === null || value === '') {
		return DEFAULT_TIMEOUT
	}
	return Number(value)
}

/**
 * The editor fields of a stored script step.
 *
 * @param {object} action The stored action.
 * @return {{code: string, timeoutSeconds: number, note: string}} The editor fields.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export function scriptStepToEditor(action) {
	const stored = action || {}
	return {
		code: typeof stored.code === 'string' ? stored.code : '',
		timeoutSeconds: timeoutOf(stored.timeoutSeconds),
		note: typeof stored.note === 'string' ? stored.note : '',
	}
}

/**
 * Why a script step cannot be saved yet, or ''.
 *
 * @param {object} action The editor action.
 * @return {string} The refusal, or ''.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export function scriptStepIncomplete(action) {
	if (String((action && action.code) || '').trim() === '') {
		return t('buildiq', 'Write the script before saving.')
	}
	const timeout = timeoutOf(action.timeoutSeconds)
	if (!Number.isInteger(timeout) || timeout < MIN_TIMEOUT || timeout > MAX_TIMEOUT) {
		return t('buildiq', 'A script may run between 1 and 30 seconds.')
	}
	return ''
}

/**
 * The stored shape of a script step: its code, its timeout and, when given, its note.
 *
 * @param {object} action The editor action.
 * @return {{type: string, code: string, timeoutSeconds: number, note?: string}} The stored action.
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
export function toPersistedScriptStep(action) {
	const persisted = {
		type: SCRIPT_STEP,
		code: String(action.code || ''),
		timeoutSeconds: timeoutOf(action.timeoutSeconds),
	}
	const note = String(action.note || '').trim()
	if (note !== '') {
		persisted.note = note
	}
	return persisted
}
