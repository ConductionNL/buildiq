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
 * The value a field `default` stands for when the form opens.
 *
 * @param {*} value The stored default.
 * @param {{user?: object, object?: object, now?: Date}} context What is known.
 * @return {*} The value to show, or undefined when it cannot be known.
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
 */
export function resolveDefault(value, context = {}) {
	if (typeof value !== 'string' || value.charAt(0) !== '@') {
		return value
	}
	const user = context.user || null
	if (value === '@me') {
		return user ? user.uid : undefined
	}
	if (value === '@me.displayName') {
		return user ? user.displayName : undefined
	}
	if (value === '@me.email') {
		return user && user.email ? user.email : undefined
	}
	if (value === '@today') {
		const now = context.now instanceof Date ? context.now : new Date()
		return now.toISOString().slice(0, 10)
	}
	if (value.startsWith('@object.')) {
		return context.object
			? readPath(context.object, value.slice('@object.'.length))
			: undefined
	}
	return value
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
export function createLiveValuesBridge({
	fields = [],
	eligibility = null,
	evaluate = evaluatePreview,
	onChange,
	delay = LIVE_DELAY_MS,
}) {
	const calculated = fields.filter(
		(field) =>
			field
			&& field.calculate
			&& field.calculate.ruleSet
			&& field.calculate.output,
	)
	const check = eligibility && eligibility.ruleSet ? eligibility : null
	const lastInputs = {}
	let lastAnswers = null
	let pending = null
	let timer = null
	const values = {}
	let unmet = ''

	const run = async () => {
		const answers = pending
		pending = null
		timer = null
		const jobs = []

		calculated.forEach((field) => {
			const inputs = Array.isArray(field.calculate.inputs)
				? field.calculate.inputs
				: []
			const payload = pick(answers, inputs)
			const signature = JSON.stringify(payload)
			if (lastInputs[field.key] === signature) {
				return
			}
			lastInputs[field.key] = signature
			jobs.push(
				evaluate(field.calculate.ruleSet, payload)
					.then((result) => {
						values[field.key] = result
							? result[field.calculate.output]
							: undefined
					})
					.catch(() => {
						delete lastInputs[field.key]
					}),
			)
		})

		const signature = JSON.stringify(answers)
		if (check && signature !== lastAnswers) {
			lastAnswers = signature
			jobs.push(
				evaluate(check.ruleSet, answers)
					.then((result) => {
						unmet = unmetExplanation(check, result)
					})
					.catch(() => {
						lastAnswers = null
						unmet = t(
							'buildiq',
							'The conditions could not be checked. Try again in a moment.',
						)
					}),
			)
		}

		if (jobs.length === 0) {
			return
		}
		await Promise.all(jobs)
		onChange({
			values: { ...values },
			unmet,
			submitBlocked: !!(check && check.blockSubmit && unmet !== ''),
		})
	}

	return {
		/**
		 * Note a change of the answers; evaluation follows once they settle.
		 *
		 * @param {object} answers All answers of the form.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		answersChanged(answers) {
			pending = { ...(answers || {}) }
			if (timer !== null) {
				clearTimeout(timer)
			}
			timer = setTimeout(run, delay)
		},
		/**
		 * Cancel a pending evaluation, when the form closes.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		dispose() {
			if (timer !== null) {
				clearTimeout(timer)
			}
			timer = null
		},
	}
}
