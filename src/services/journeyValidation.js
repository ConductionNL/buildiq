// SPDX-License-Identifier: EUPL-1.2
/**
 * journeyValidation — the author-time checks of the Journey Designer
 * (journey-designer, ADR-085 §6).
 *
 * A journey is `{ id, title, steps[] }`. A `form` step REFERENCES a form
 * object by `formId` and never carries field definitions of its own; a step
 * may carry `branch: [{ when, goto }]` (the `visibleWhen` predicates
 * `CnJourney` evaluates) and `writes: [{ as, register, schema, mapping }]`.
 *
 * The checks here need no server: step references, branch rules and the
 * order of `{ $write: <as> }` references. Whether each write's mapping fits
 * its destination schema is asked of the server (`checkJourneyWrites`), which
 * hands every write to OpenRegister's form destination validator, the same
 * one the run path uses (decision 179). No rule of that validator lives here.
 *
 * Every finding is `{ step, code, ...params }`: the editor translates the
 * code, so no sentence is fixed here.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md
 */
import manifestSchema from '@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The step types the designer authors.
 *
 * @type {ReadonlyArray<string>}
 */
export const JOURNEY_STEP_TYPES = Object.freeze(['form', 'review', 'confirmation'])

/**
 * The operators a branch rule may use: read from the canonical schema's
 * `visibleWhen.op`, so the editor cannot offer an operator the renderer
 * does not know, nor miss one it gains.
 *
 * @type {ReadonlyArray<string>}
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
 */
export const BRANCH_OPERATORS = Object.freeze([...manifestSchema.$defs.visibleWhen.properties.op.enum])

/**
 * The steps with the one at `from` moved to `to`; every step is the same
 * object, so only the order changes.
 *
 * @param {Array<object>} steps - the steps.
 * @param {number} from - the index of the step to move.
 * @param {number} to - the index it moves to.
 * @return {Array<object>} A new array in the new order.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
export function moveStep(steps, from, to) {
	const out = [...steps]
	if (from < 0 || from >= out.length || to < 0 || to >= out.length) {
		return out
	}
	const [moved] = out.splice(from, 1)
	out.splice(to, 0, moved)
	return out
}

/**
 * A form step as it is stored: its id, title, the form it references and
 * its branch and writes. A pasted `form` with fields is dropped, so no field
 * definition is copied into the journey.
 *
 * @param {object} step - the step as edited.
 * @return {object} The step to store.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
export function storedFormStep(step) {
	const stored = { ...step, type: 'form', formId: String(step.formId || '') }
	delete stored.form
	delete stored.fields
	return stored
}

/**
 * The field keys a form object asks, from its `fields` and from the fields
 * of its own `steps[]`.
 *
 * @param {object|undefined} form - the form object.
 * @return {Array<string>} The keys.
 */
function fieldKeysOf(form) {
	if (!form) {
		return []
	}
	const keys = []
	const collect = (fields) => {
		for (const field of Array.isArray(fields) ? fields : []) {
			if (field && (field.key || field.name)) {
				keys.push(String(field.key || field.name))
			}
		}
	}
	collect(form.fields)
	for (const sub of Array.isArray(form.steps) ? form.steps : []) {
		collect(sub && sub.fields)
	}
	return keys
}

/**
 * Findings on the steps themselves: an unknown type, a form step without a
 * form, one whose form no longer exists, and one that carries its own field
 * definitions instead of a reference.
 *
 * @param {{steps?: Array<object>}} journey - the journey.
 * @param {{[id: string]: object}} forms - the form objects by id.
 * @return {Array<object>} The findings.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
export function stepFindings(journey, forms) {
	const findings = []
	const seen = new Set()
	for (const step of journeySteps(journey)) {
		const id = String(step.id || '')
		if (id === '' || seen.has(id)) {
			findings.push({ step: id, code: id === '' ? 'step-id-missing' : 'step-id-duplicate' })
		}
		seen.add(id)
		if (!JOURNEY_STEP_TYPES.includes(step.type)) {
			findings.push({ step: id, code: 'step-type-unknown', type: String(step.type) })
			continue
		}
		if (step.type !== 'form') {
			continue
		}
		if (step.form || step.fields) {
			findings.push({ step: id, code: 'step-copies-fields' })
		}
		const formId = String(step.formId || '')
		if (formId === '') {
			findings.push({ step: id, code: 'step-form-missing' })
		} else if (!forms || !forms[formId]) {
			findings.push({ step: id, code: 'step-form-gone', form: formId })
		}
	}
	return findings
}

/**
 * The fields a condition reads and the operators it uses, through `all`
 * and `any` compositions.
 *
 * @param {object} cond - the condition.
 * @param {Array<{field: string|null, op: string}>} out - collects the parts.
 * @return {Array<{field: string|null, op: string}>} The parts.
 */
function conditionParts(cond, out = []) {
	if (!cond || typeof cond !== 'object') {
		return out
	}
	const parts = Array.isArray(cond.all) ? cond.all : (Array.isArray(cond.any) ? cond.any : null)
	if (parts !== null) {
		parts.forEach((part) => conditionParts(part, out))
		return out
	}
	out.push({ field: cond.field ? String(cond.field).split('.')[0] : null, op: cond.op === undefined ? 'eq' : String(cond.op) })
	return out
}

/**
 * Findings on the branch rules: a target that is no step, an operator the
 * canonical set does not hold, and a field that is not asked by then. A
 * branch on a step runs once that step is answered, so it may read that
 * step's fields and the fields of every step before it.
 *
 * @param {{steps?: Array<object>}} journey - the journey.
 * @param {{[id: string]: object}} forms - the form objects by id.
 * @return {Array<object>} The findings.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
 */
export function branchFindings(journey, forms) {
	const steps = journeySteps(journey)
	const firstAsked = {}
	steps.forEach((step, index) => {
		if (step.type !== 'form') {
			return
		}
		for (const key of fieldKeysOf(forms && forms[String(step.formId || '')])) {
			if (firstAsked[key] === undefined) {
				firstAsked[key] = index
			}
		}
	})
	const ids = new Set(steps.map((step) => String(step.id || '')))
	const findings = []
	steps.forEach((step, index) => {
		const id = String(step.id || '')
		for (const rule of Array.isArray(step.branch) ? step.branch : []) {
			const goto = String((rule && rule.goto) || '')
			if (!ids.has(goto)) {
				findings.push({ step: id, code: 'branch-target-unknown', goto })
			}
			for (const part of conditionParts(rule && rule.when)) {
				if (!BRANCH_OPERATORS.includes(part.op)) {
					findings.push({ step: id, code: 'branch-operator-unknown', operator: part.op })
				}
				if (part.field === null) {
					continue
				}
				const asked = firstAsked[part.field]
				if (asked === undefined) {
					findings.push({ step: id, code: 'branch-field-unknown', field: part.field })
				} else if (asked > index) {
					findings.push({ step: id, code: 'branch-field-later', field: part.field, askedAt: String(steps[asked].id) })
				}
			}
		}
	})
	return findings
}

/**
 * Findings on the order of the writes: every `as` is unique, and a
 * `{ $write: <as> }` value names a write made before it, in this step or an
 * earlier one. Whether each mapping fits its destination is the server's
 * question (`checkJourneyWrites`).
 *
 * @param {{steps?: Array<object>}} journey - the journey.
 * @return {Array<object>} The findings.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 */
export function writeOrderFindings(journey) {
	const made = new Set()
	const findings = []
	for (const step of journeySteps(journey)) {
		const id = String(step.id || '')
		for (const write of Array.isArray(step.writes) ? step.writes : []) {
			const fixed = (write && write.mapping && write.mapping.fixed) || {}
			for (const [property, value] of Object.entries(fixed)) {
				if (value && typeof value === 'object' && typeof value.$write === 'string' && !made.has(value.$write)) {
					findings.push({ step: id, code: 'write-unknown', write: value.$write, property })
				}
			}
			const as = String((write && write.as) || '')
			if (as !== '' && made.has(as)) {
				findings.push({ step: id, code: 'write-as-duplicate', write: as })
			}
			if (as !== '') {
				made.add(as)
			}
		}
	}
	return findings
}

/**
 * Every finding the designer can make without the server.
 *
 * @param {{steps?: Array<object>}} journey - the journey.
 * @param {{[id: string]: object}} forms - the form objects by id.
 * @return {Array<object>} The findings; empty when the journey may be checked further.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md
 */
export function journeyFindings(journey, forms) {
	return [...stepFindings(journey, forms), ...branchFindings(journey, forms), ...writeOrderFindings(journey)]
}

/**
 * Ask buildiq whether every write of the journey fits its destination.
 * Only someone allowed to author journeys may ask.
 *
 * @param {object} journey - the journey.
 * @param {object} [client] - axios-compatible client (tests inject one).
 * @return {Promise<{accepted: boolean, findings: Array<object>}>} The answer.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 */
export async function checkJourneyWrites(journey, client = axios) {
	const { data } = await client.post(generateUrl('/apps/buildiq/api/journeys/check'), { journey })
	const findings = data && Array.isArray(data.findings) ? data.findings : []
	return { accepted: findings.length === 0, findings }
}

/**
 * The journey's steps, or none.
 *
 * @param {{steps?: Array<object>}} journey - the journey.
 * @return {Array<object>} The steps.
 */
function journeySteps(journey) {
	return Array.isArray(journey && journey.steps) ? journey.steps.filter((step) => step && typeof step === 'object') : []
}
