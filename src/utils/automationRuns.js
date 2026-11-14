// SPDX-License-Identifier: EUPL-1.2
/**
 * What the Automations page and the runs dialog say about an automation's
 * runs (logic-automation-run-log): which actions OpenRegister executes without
 * buildiq, so the dialog labels them instead of inventing an outcome, and the
 * last-run line under each row.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * Action types that compile to an artifact OpenRegister (or integriq for a
 * synchronization) executes on an object event or a schedule, without
 * calling buildiq (design D4). On the `manual` trigger buildiq dispatches
 * every action itself, so none of them is outside it there.
 */
const IN_OPENREGISTER = ['send-notification', 'webhook', 'object-op', 'run-synchronization']

/**
 * The outcome a step carries when OpenRegister executed it.
 */
export const IN_OPENREGISTER_OUTCOME = 'in-openregister'

/**
 * The automation's actions, top level and the approve and reject branches.
 *
 * @param {object} automation The automation.
 * @return {Array<object>} Every action.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
export function actionsOf(automation) {
	const top = Array.isArray(automation?.actions) ? automation.actions.filter((a) => a && typeof a === 'object') : []
	const branches = top.flatMap((action) => [
		...(Array.isArray(action.onApprove) ? action.onApprove : []),
		...(Array.isArray(action.onReject) ? action.onReject : []),
	]).filter((a) => a && typeof a === 'object')
	return [...top, ...branches]
}

/**
 * Whether OpenRegister executes this action of the automation without buildiq.
 *
 * @param {object} automation The automation.
 * @param {object} action One of its actions.
 * @param {boolean} branch Whether the action is an approve or reject follow-up, which buildiq dispatches.
 * @return {boolean}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
export function runsInOpenRegister(automation, action, branch = false) {
	if (branch || automation?.trigger?.type === 'manual') {
		return false
	}
	return IN_OPENREGISTER.includes(action?.type)
}

/**
 * Whether every top-level action runs outside buildiq, so no run is ever recorded.
 *
 * @param {object} automation The automation.
 * @return {boolean}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notification-only-automation-does-not-fake-a-log
 */
export function runsEntirelyInOpenRegister(automation) {
	const top = Array.isArray(automation?.actions) ? automation.actions.filter((a) => a && typeof a === 'object') : []
	return top.length > 0 && top.every((action) => runsInOpenRegister(automation, action))
}

/**
 * The top-level actions OpenRegister executes, as `in-openregister` steps for
 * the run detail: listed, never given a success or a failure.
 *
 * @param {object} automation The automation.
 * @return {Array<{action: string, outcome: string, message: string}>}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-mixed-automation-labels-the-openregister-step
 */
export function openRegisterSteps(automation) {
	const top = Array.isArray(automation?.actions) ? automation.actions.filter((a) => a && typeof a === 'object') : []
	return top
		.filter((action) => runsInOpenRegister(automation, action))
		.map((action) => ({ action: action.type, outcome: IN_OPENREGISTER_OUTCOME, message: '' }))
}

/**
 * A run's time as the row shows it ("7 Oct 14:02"), in the viewer's locale.
 *
 * @param {string} iso The ISO date-time.
 * @param {string} [locale] The locale; the browser's when omitted.
 * @return {string}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003
 */
export function runTime(iso, locale = undefined) {
	const date = new Date(iso)
	if (!iso || Number.isNaN(date.getTime())) {
		return ''
	}
	const day = date.toLocaleDateString(locale, { day: 'numeric', month: 'short' })
	const time = date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', hour12: false })
	return `${day} ${time}`
}

/**
 * The label of a run or step outcome.
 *
 * @param {string} outcome The outcome.
 * @return {string}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003
 */
export function outcomeLabel(outcome) {
	switch (outcome) {
	case 'succeeded':
		return t('buildiq', 'succeeded')
	case 'failed':
		return t('buildiq', 'failed')
	case 'skipped':
		return t('buildiq', 'skipped')
	case 'waiting':
		return t('buildiq', 'waiting')
	case IN_OPENREGISTER_OUTCOME:
		return t('buildiq', 'Runs in OpenRegister. No per-run record yet.')
	default:
		return String(outcome || '')
	}
}

/**
 * The last-run line under an automation row.
 *
 * @param {{lastRunAt: (string|null), lastOutcome: (string|null), lastError: (string|null)}|null} summary The summary.
 * @param {string} [locale] The locale for the time.
 * @return {string}
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-failed-last-run-is-visible-on-the-list
 */
export function lastRunText(summary, locale = undefined) {
	if (!summary || !summary.lastRunAt) {
		return t('buildiq', 'No run in the last 90 days')
	}
	if (summary.lastOutcome === 'failed' && summary.lastError) {
		return t('buildiq', 'Last run failed: {reason}', { reason: summary.lastError })
	}
	return t('buildiq', 'Last run {time}, {outcome}', { time: runTime(summary.lastRunAt, locale), outcome: outcomeLabel(summary.lastOutcome) })
}
