// SPDX-License-Identifier: EUPL-1.2
/**
 * formLiveValues: what a buildiq form knows and works out while it is filled
 * in (change forms-live-values-and-checks).
 *
 * - A field `default` is a literal or a token: the signed-in user (`@me`,
 *   `@me.displayName`, `@me.email`), today (`@today`), or a field of the record
 *   the form was opened from (`@object.<field>`). REQ-BQLV-001.
 * - A field `calculate` is `{ruleSet, output, inputs[]}`: the rule set to
 *   evaluate, the output to show, and the answers it reads. REQ-BQLV-002.
 * - A form `eligibility` is `{ruleSet, passWhen: {output, equals},
 *   explainWith, blockSubmit}`. REQ-BQLV-003.
 *
 * Live evaluation always goes through the evaluate endpoint in preview mode,
 * which writes no execution log (REQ-BQLV-004). The server evaluates again on
 * save, so nothing computed here is trusted (REQ-BQLV-005).
 *
 * The form renderer is nextcloud-vue's CnFormPage; the hooks that hand it
 * these values are its half of the change. This module is the buildiq half:
 * the client calls, the token resolution and a renderer-independent bridge.
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The tokens the default picker offers; `@object.` takes a field after it. */
export const DEFAULT_TOKENS = Object.freeze([
	'@me',
	'@me.displayName',
	'@me.email',
	'@today',
	'@object.',
])

/** How long the answers must stay still before a live evaluation runs. */
export const LIVE_DELAY_MS = 400

/**
 * The rule sets a form can bind to, read from the buildiq register.
 *
 * @return {Promise<Array<{slug: string, name: string, status: string}>>}
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
 */
export async function listRuleSets() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/buildiq/rule-set'),
	)
	let rows = []
	if (Array.isArray(data)) {
		rows = data
	} else if (data && Array.isArray(data.results)) {
		rows = data.results
	}
	return rows
		.filter((row) => row && typeof row.slug === 'string' && row.slug !== '')
		.map((row) => ({
			slug: row.slug,
			name:
				typeof row.name === 'string' && row.name !== ''
					? row.name
					: row.slug,
			status: typeof row.status === 'string' ? row.status : '',
		}))
}

/**
 * The inputs and outputs of one rule set's decision table.
 *
 * @param {string} slug The rule set slug.
 * @return {Promise<{inputs: Array<object>, outputs: Array<object>}>}
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
 */
export async function ruleSetColumns(slug) {
	const { data } = await axios.get(
		generateUrl(
			'/apps/buildiq/api/rules/' + encodeURIComponent(slug) + '/schema',
		),
	)
	return {
		inputs: Array.isArray(data && data.inputs) ? data.inputs : [],
		outputs: Array.isArray(data && data.outputs) ? data.outputs : [],
	}
}

/**
 * Evaluate a rule set in preview mode: no execution log, no side effects.
 *
 * @param {string} slug The rule set slug.
 * @param {object} payload The answers it reads.
 * @return {Promise<object>} The rule set's outputs.
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
 */
export async function evaluatePreview(slug, payload) {
	const { data } = await axios.post(
		generateUrl(
			'/apps/buildiq/api/rules/' + encodeURIComponent(slug) + '/evaluate',
		),
		{ payload, mode: 'preview' },
	)
	return data && typeof data.result === 'object' && data.result !== null
		? data.result
		: {}
}

/**
 * Read a dot path from an object.
 *
 * @param {object|null} source The object.
 * @param {string} path The dot path.
 * @return {*} The value, or undefined.
 */
function readPath(source, path) {
	return path.split('.').reduce((node, key) => {
		if (node === null || typeof node !== 'object') {
			return undefined
		}
		return node[key]
	}, source)
}

/**
 * The resolver of each fixed `@` token a field default can carry. Each takes
 * the user (or null) and the context.
 */
const TOKEN_RESOLVERS = Object.freeze({
	'@me': (user) => (user ? user.uid : undefined),
	'@me.displayName': (user) => (user ? user.displayName : undefined),
	'@me.email': (user) => (user && user.email ? user.email : undefined),
	'@today': (user, context) =>
		(context.now instanceof Date ? context.now : new Date())
			.toISOString()
			.slice(0, 10),
})

/**
 * The value a field `default` stands for when the form opens.
 *
 * @param {*} value The stored default.
 * @param {{user?: object, object?: object, now?: Date}} context What is known.
 * @return {*} The value to show, or undefined when it cannot be known.
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
 */
export function resolveDefault(value, context = {}) {
	if (!isToken(value)) {
		return value
	}
	const user = userOf(context)
	if (Object.hasOwn(TOKEN_RESOLVERS, value)) {
		return TOKEN_RESOLVERS[value](user, context)
	}
	return value.startsWith('@object.') ? objectFieldValue(value, context) : value
}

/**
 * Whether a stored default is a token (a string starting with `@`).
 *
 * @param {unknown} value The stored default.
 * @return {boolean}
 */
function isToken(value) {
	return typeof value === 'string' && value.charAt(0) === '@'
}

/**
 * The signed-in user the context carries, or null.
 *
 * @param {{user?: object}} context What is known.
 * @return {?object}
 */
function userOf(context) {
	return context.user || null
}

/**
 * The value of an `@object.<path>` token: that field of the record the form
 * was opened from.
 *
 * @param {string} token The `@object.` token.
 * @param {{object?: object}} context What is known.
 * @return {unknown} The value, or undefined without a record.
 */
function objectFieldValue(token, context) {
	return context.object
		? readPath(context.object, token.slice('@object.'.length))
		: undefined
}

/**
 * The explanation to show when an eligibility check does not pass, or ''.
 *
 * @param {object} eligibility The form's eligibility check.
 * @param {object} result The rule set's outputs.
 * @return {string}
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
 */
export function unmetExplanation(eligibility, result) {
	const passWhen = (eligibility && eligibility.passWhen) || {}
	if (result && String(result[passWhen.output]) === String(passWhen.equals)) {
		return ''
	}
	const explanation =
		result && eligibility.explainWith ? result[eligibility.explainWith] : ''
	if (typeof explanation === 'string' && explanation !== '') {
		return explanation
	}
	return t('buildiq', 'You do not meet the conditions of this form yet.')
}

/**
 * Pick the named answers.
 *
 * @param {object} answers All answers.
 * @param {Array<string>} keys The keys to keep.
 * @return {object}
 */
function pick(answers, keys) {
	const out = {}
	keys.forEach((key) => {
		if (Object.hasOwn(answers, key)) {
			out[key] = answers[key]
		}
	})
	return out
}

/**
 * Bridge a form's answers to its calculated fields and eligibility check.
 *
 * Call `answersChanged(answers)` on every change. Once the answers have been
 * still for `delay` ms, each calculated field whose inputs changed is
 * evaluated, and the eligibility check is evaluated when any answer changed.
 * `onChange({values, unmet, submitBlocked})` then receives the calculated
 * values, the explanation of an unmet check ('' when it passes) and whether
 * submit must wait. A check that cannot be evaluated keeps a blocking submit
 * blocked: the server would refuse the save anyway.
 *
 * @param {object} options The bridge options.
 * @param {Array<object>} options.fields The form's fields.
 * @param {object} [options.eligibility] The form's eligibility check.
 * @param {Function} [options.evaluate] `(slug, payload) => Promise<object>`.
 * @param {Function} options.onChange Receives the outcome.
 * @param {number} [options.delay] Debounce in ms.
 * @return {{answersChanged: Function, dispose: Function}}
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
 */
export function createLiveValuesBridge(options) {
	const state = bridgeState(options)
	const run = () => runEvaluation(state)

	return {
		/**
		 * Note a change of the answers; evaluation follows once they settle.
		 *
		 * @param {object} answers All answers of the form.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		answersChanged(answers) {
			state.pending = { ...(answers || {}) }
			if (state.timer !== null) {
				clearTimeout(state.timer)
			}
			state.timer = setTimeout(run, state.delay)
		},
		/**
		 * Cancel a pending evaluation, when the form closes.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		dispose() {
			if (state.timer !== null) {
				clearTimeout(state.timer)
			}
			state.timer = null
		},
	}
}

/**
 * The bridge's settings and its evaluation state. The state never leaves the
 * bridge.
 *
 * @param {object} options The bridge options, see createLiveValuesBridge().
 * @return {object}
 */
function bridgeState(options) {
	return { ...bridgeSettings(options), ...freshEvaluationState() }
}

/**
 * The bridge options with their defaults applied.
 *
 * @param {object} options The bridge options, see createLiveValuesBridge().
 * @return {object} `calculated`, `check`, `evaluate`, `onChange` and `delay`.
 */
function bridgeSettings(options) {
	return {
		calculated: orDefault(options.fields, []).filter(isCalculatedField),
		check: eligibilityCheck(orDefault(options.eligibility, null)),
		evaluate: orDefault(options.evaluate, evaluatePreview),
		onChange: options.onChange,
		delay: orDefault(options.delay, LIVE_DELAY_MS),
	}
}

/**
 * The evaluation state of a bridge that has evaluated nothing yet.
 *
 * @return {object}
 */
function freshEvaluationState() {
	return {
		lastInputs: {},
		lastAnswers: null,
		pending: null,
		timer: null,
		settled: null,
		values: {},
		unmet: '',
	}
}

/**
 * The value, or the fallback when it is undefined (as a default parameter).
 *
 * @param {unknown} value The given value.
 * @param {unknown} fallback The default.
 * @return {unknown}
 */
function orDefault(value, fallback) {
	return value === undefined ? fallback : value
}

/**
 * Whether a field is calculated by a rule set output.
 *
 * @param {?object} field A form field.
 * @return {boolean}
 */
function isCalculatedField(field) {
	return Boolean(
		field && field.calculate && field.calculate.ruleSet && field.calculate.output,
	)
}

/**
 * The eligibility check when it names a rule set, else null.
 *
 * @param {?object} eligibility The form's eligibility check.
 * @return {?object}
 */
function eligibilityCheck(eligibility) {
	return eligibility && eligibility.ruleSet ? eligibility : null
}

/**
 * Evaluate the settled answers: every calculated field whose inputs changed,
 * then the eligibility check when any answer changed, and report the outcome
 * once all of them are done.
 *
 * @param {object} state The bridge state.
 * @return {Promise<void>}
 */
async function runEvaluation(state) {
	settle(state)
	const jobs = evaluationJobs(state)
	if (jobs.length === 0) {
		return
	}
	await Promise.all(jobs)
	reportOutcome(state)
}

/**
 * Take the pending answers as the ones to evaluate now.
 *
 * @param {object} state The bridge state.
 * @return {void}
 */
function settle(state) {
	state.settled = state.pending
	state.pending = null
	state.timer = null
}

/**
 * Start the evaluations the settled answers need: the calculated fields in
 * order, then the eligibility check.
 *
 * @param {object} state The bridge state.
 * @return {Array<Promise<void>>}
 */
function evaluationJobs(state) {
	const jobs = state.calculated
		.map((field) => calculationJob(state, field))
		.filter(Boolean)
	const checkJob = eligibilityJob(state)
	if (checkJob) {
		jobs.push(checkJob)
	}
	return jobs
}

/**
 * Evaluate one calculated field when the answers it reads changed.
 *
 * @param {object} state The bridge state.
 * @param {object} field The calculated field.
 * @return {?Promise<void>} The evaluation, or null when its inputs did not change.
 */
function calculationJob(state, field) {
	const payload = pick(state.settled, calculationInputs(field))
	const signature = JSON.stringify(payload)
	if (state.lastInputs[field.key] === signature) {
		return null
	}
	state.lastInputs[field.key] = signature
	const { evaluate } = state
	return evaluate(field.calculate.ruleSet, payload)
		.then((result) => {
			state.values[field.key] = result ? result[field.calculate.output] : undefined
		})
		.catch(() => {
			delete state.lastInputs[field.key]
		})
}

/**
 * The answers a calculated field reads.
 *
 * @param {object} field The calculated field.
 * @return {Array<string>}
 */
function calculationInputs(field) {
	return Array.isArray(field.calculate.inputs) ? field.calculate.inputs : []
}

/**
 * Evaluate the eligibility check when any answer changed.
 *
 * @param {object} state The bridge state.
 * @return {?Promise<void>} The evaluation, or null when there is nothing to check.
 */
function eligibilityJob(state) {
	const signature = JSON.stringify(state.settled)
	if (!state.check || signature === state.lastAnswers) {
		return null
	}
	state.lastAnswers = signature
	const { evaluate, check } = state
	return evaluate(check.ruleSet, state.settled)
		.then((result) => {
			state.unmet = unmetExplanation(check, result)
		})
		.catch(() => {
			state.lastAnswers = null
			state.unmet = t(
				'buildiq',
				'The conditions could not be checked. Try again in a moment.',
			)
		})
}

/**
 * Hand the calculated values, the unmet explanation and whether submit must
 * wait to the bridge's `onChange`.
 *
 * @param {object} state The bridge state.
 * @return {void}
 */
function reportOutcome(state) {
	const { onChange, check } = state
	onChange({
		values: { ...state.values },
		unmet: state.unmet,
		submitBlocked: !!(check && check.blockSubmit && state.unmet !== ''),
	})
}
