// SPDX-License-Identifier: EUPL-1.2
/**
 * formLogic — app-side validation (and one save-time normalisation helper)
 * for the manifest-form-logic authoring surface (REQ-OBFEL-005): `type:
 * "form"` pages' `config.steps[]`, `config.fields[].visibleWhen` and
 * `config.fields[].validation`.
 *
 * The `manifest-form-logic` leaf on `@conduction/nextcloud-vue` (installed
 * at `1.0.0-beta.173`+) already ships the canonical shape/partition checks
 * for this contract in its own `validateManifest()` (duplicate step ids,
 * dangling step/condition field references, the complete-partition rule,
 * `min<=max`, pattern-compilability — verified against HEAD in tasks
 * 1.1/1.2). This module is this app's OWN authoritative surface for the
 * same semantic rules per REQ-OBFEL-005 (so the editor's right-pane list
 * and inline marks light up from a predictable, testable source
 * regardless of which nextcloud-vue build is installed), plus one rule the
 * canonical validator does not attempt: a warning-level entry when a field
 * carries BOTH a structured `validation` object and un-migrated legacy
 * flat `required` / `pattern` keys (an editor-only authoring concern, not
 * a manifest-schema concern).
 *
 * Returned entries are `<pointer>: <code>` strings (the same convention as
 * `schedules.js`) so the existing path-prefix -> inline-mark mechanism
 * (REQ-OBPD-011) lights up the offending editor section. Codes are
 * `buildiq.formLogic.error.*` for hard errors and
 * `buildiq.formLogic.warning.*` for the one warning-level rule.
 *
 * @spec openspec/specs/form-editor-logic/spec.md#req-obfel-005
 */

/** The visibleWhen op allow-list (mirrors `$defs/visibleWhen.properties.op.enum`). */
export const VISIBLE_WHEN_OPS = Object.freeze([
	'eq',
	'neq',
	'gt',
	'gte',
	'lt',
	'lte',
])

/**
 * Whether `value` is a plain object (not an array, not null).
 *
 * @param {*} value - candidate value.
 * @return {boolean}
 */
function isPlainObject(value) {
	return !!value && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Validate one `type: "form"` page's `config.steps[]` shape and
 * cross-references.
 *
 * @param {object} config - the page's `config` object.
 * @param {string} pathBase - `/pages/<n>/config` pointer prefix.
 * @param {Set<string>} declaredKeys - `config.fields[].key` values.
 * @return {string[]} - `<pointer>: <code>` error strings.
 */
function validateSteps(config, pathBase, declaredKeys) {
	const errors = []
	if (config.steps === undefined) {
		return errors
	}
	if (!Array.isArray(config.steps)) {
		errors.push(`${pathBase}/steps: buildiq.formLogic.error.steps-not-array`)
		return errors
	}

	const seen = {
		declaredKeys,
		seenIds: new Set(),
		assignmentCount: new Map(),
	}
	config.steps.forEach((step, sIndex) => {
		errors.push(
			...stepErrors(step, {
				...seen,
				stepPath: `${pathBase}/steps/${sIndex}`,
			}),
		)
	})

	seen.assignmentCount.forEach((count, key) => {
		if (count > 1) {
			errors.push(
				`${pathBase}/steps: buildiq.formLogic.error.duplicate-field-assignment(${key})`,
			)
		}
	})

	return errors
}

/**
 * Errors of one step, in report order. Records the step's id and field
 * assignments in the shared sets of `ctx`.
 *
 * @param {unknown} step - the step entry.
 * @param {{stepPath: string, declaredKeys: Set<string>, seenIds: Set<string>, assignmentCount: Map<string, number>}} ctx
 *   - the step pointer prefix, the declared field keys and the ids and
 *   assignments seen so far.
 * @return {string[]}
 */
function stepErrors(step, ctx) {
	if (!isPlainObject(step)) {
		return [`${ctx.stepPath}: buildiq.formLogic.error.step-invalid-shape`]
	}
	const errors = stepTitleErrors(step, ctx.stepPath)
	if (!Array.isArray(step.fields)) {
		errors.push(
			`${ctx.stepPath}/fields: buildiq.formLogic.error.step-fields-not-array`,
		)
		return errors
	}
	errors.push(...stepIdErrors(step, ctx))
	step.fields.forEach((key, fIndex) => {
		if (typeof key !== 'string' || !ctx.declaredKeys.has(key)) {
			errors.push(
				`${ctx.stepPath}/fields/${fIndex}: buildiq.formLogic.error.dangling-step-field`,
			)
			return
		}
		ctx.assignmentCount.set(key, (ctx.assignmentCount.get(key) || 0) + 1)
	})
	return errors
}

/**
 * A missing or blank step title.
 *
 * @param {object} step - the step entry.
 * @param {string} stepPath - the step pointer prefix.
 * @return {string[]}
 */
function stepTitleErrors(step, stepPath) {
	if (typeof step.title !== 'string' || step.title.trim() === '') {
		return [`${stepPath}/title: buildiq.formLogic.error.step-title-required`]
	}
	return []
}

/**
 * A step id used by an earlier step. Records the id.
 *
 * @param {object} step - the step entry.
 * @param {{stepPath: string, seenIds: Set<string>}} ctx - the step pointer
 *   prefix and the ids seen so far.
 * @return {string[]}
 */
function stepIdErrors(step, ctx) {
	if (typeof step.id !== 'string' || step.id === '') {
		return []
	}
	const errors = ctx.seenIds.has(step.id)
		? [`${ctx.stepPath}/id: buildiq.formLogic.error.duplicate-step-id`]
		: []
	ctx.seenIds.add(step.id)
	return errors
}

/**
 * Errors of a LOCAL-mode `visibleWhen` (no `endpoint` / `source`).
 *
 * @param {object} visibleWhen - the condition object.
 * @param {{fieldPath: string, declaredKeys: Set<string>}} ctx - the field
 *   pointer prefix and the declared field keys.
 * @return {string[]}
 */
function visibleWhenErrors(visibleWhen, ctx) {
	const path = `${ctx.fieldPath}/visibleWhen`
	const errors = conditionFieldErrors(visibleWhen, {
		path,
		declaredKeys: ctx.declaredKeys,
	})
	if (visibleWhen.op !== undefined && !VISIBLE_WHEN_OPS.includes(visibleWhen.op)) {
		errors.push(`${path}/op: buildiq.formLogic.error.condition-op-not-allowed`)
	}
	return errors
}

/**
 * A missing, blank or undeclared condition field.
 *
 * @param {object} visibleWhen - the condition object.
 * @param {{path: string, declaredKeys: Set<string>}} ctx - the condition
 *   pointer prefix and the declared field keys.
 * @return {string[]}
 */
function conditionFieldErrors(visibleWhen, ctx) {
	if (typeof visibleWhen.field !== 'string' || visibleWhen.field.trim() === '') {
		return [
			`${ctx.path}/field: buildiq.formLogic.error.condition-field-required`,
		]
	}
	if (!ctx.declaredKeys.has(visibleWhen.field)) {
		return [
			`${ctx.path}/field: buildiq.formLogic.error.dangling-condition-field`,
		]
	}
	return []
}

/** The type each optional `validation` key must have, and its error code. */
const VALIDATION_TYPES = Object.freeze({
	required: {
		type: 'boolean',
		code: 'buildiq.formLogic.error.validation-required-not-boolean',
	},
	min: {
		type: 'number',
		code: 'buildiq.formLogic.error.validation-min-not-number',
	},
	max: {
		type: 'number',
		code: 'buildiq.formLogic.error.validation-max-not-number',
	},
	message: {
		type: 'string',
		code: 'buildiq.formLogic.error.validation-message-not-string',
	},
})

/**
 * Type errors for the given `validation` keys that are present.
 *
 * @param {object} validation - the field's validation object.
 * @param {{fieldPath: string, keys: string[]}} scope - the field pointer
 *   prefix and the keys to check, in report order.
 * @return {string[]}
 */
function validationTypeErrors(validation, scope) {
	return scope.keys
		.filter(
			(key) =>
				validation[key] !== undefined
				&& typeof validation[key] !== VALIDATION_TYPES[key].type,
		)
		.map(
			(key) =>
				`${scope.fieldPath}/validation/${key}: ${VALIDATION_TYPES[key].code}`,
		)
}

/**
 * A numeric `min` above a numeric `max`.
 *
 * @param {object} validation - the field's validation object.
 * @param {string} fieldPath - the field pointer prefix.
 * @return {string[]}
 */
function validationRangeErrors(validation, fieldPath) {
	if (
		typeof validation.min === 'number'
		&& typeof validation.max === 'number'
		&& validation.min > validation.max
	) {
		return [
			`${fieldPath}/validation: buildiq.formLogic.error.validation-min-greater-than-max`,
		]
	}
	return []
}

/**
 * A string `pattern` that does not compile as a RegExp.
 *
 * @param {object} validation - the field's validation object.
 * @param {string} fieldPath - the field pointer prefix.
 * @return {string[]}
 */
function validationPatternErrors(validation, fieldPath) {
	if (typeof validation.pattern !== 'string') {
		return []
	}
	try {
		new RegExp(validation.pattern)
	} catch {
		return [
			`${fieldPath}/validation/pattern: buildiq.formLogic.error.validation-pattern-does-not-compile`,
		]
	}
	return []
}

/**
 * Errors of a field's structured `validation` object, in report order.
 *
 * @param {object} validation - the field's validation object.
 * @param {string} fieldPath - the field pointer prefix.
 * @return {string[]}
 */
function validationErrors(validation, fieldPath) {
	return [
		...validationTypeErrors(validation, {
			fieldPath,
			keys: ['required', 'min', 'max'],
		}),
		...validationRangeErrors(validation, fieldPath),
		...validationPatternErrors(validation, fieldPath),
		...validationTypeErrors(validation, { fieldPath, keys: ['message'] }),
	]
}

/**
 * Validate one field's `visibleWhen` (LOCAL mode only — `endpoint` /
 * `source` shapes are canonical-schema territory, not re-validated here
 * beyond being objects) and `validation` object.
 *
 * @param {object} field - the field entry.
 * @param {{fieldPath: string, declaredKeys: Set<string>}} ctx - the
 *   `/pages/<n>/config/fields/<i>` pointer prefix and the
 *   `config.fields[].key` values.
 * @return {string[]} - `<pointer>: <code>` error/warning strings.
 */
function validateFieldLogic(field, ctx) {
	if (!isPlainObject(field)) {
		return []
	}
	return [
		...localVisibleWhenErrors(field.visibleWhen, ctx),
		...fieldValidationErrors(field, ctx.fieldPath),
	]
}

/**
 * Errors of a field's `visibleWhen` when it is a LOCAL-mode condition.
 *
 * @param {unknown} visibleWhen - the field condition.
 * @param {{fieldPath: string, declaredKeys: Set<string>}} ctx - the field
 *   pointer prefix and the declared field keys.
 * @return {string[]}
 */
function localVisibleWhenErrors(visibleWhen, ctx) {
	return isPlainObject(visibleWhen) && !visibleWhen.endpoint && !visibleWhen.source
		? visibleWhenErrors(visibleWhen, ctx)
		: []
}

/**
 * Errors of a field's structured `validation` object.
 *
 * @param {object} field - the field entry.
 * @param {string} fieldPath - the field pointer prefix.
 * @return {string[]}
 */
function fieldValidationErrors(field, fieldPath) {
	if (!isPlainObject(field.validation)) {
		return []
	}
	const errors = validationErrors(field.validation, fieldPath)
	// Warning-level: an un-migrated field carries both the structured
	// object AND a legacy flat key (Decision 4 — migration is opt-in
	// per field, so this can legitimately persist until the developer
	// edits that field's Validation section).
	if (field.required !== undefined || field.pattern !== undefined) {
		errors.push(
			`${fieldPath}: buildiq.formLogic.warning.flat-and-structured-validation`,
		)
	}
	return errors
}

/**
 * The config of a `type: "form"` page, or null.
 *
 * @param {unknown} page - the page entry.
 * @return {object|null}
 */
function formConfig(page) {
	if (!page || page.type !== 'form') {
		return null
	}
	return isPlainObject(page.config) ? page.config : null
}

/**
 * The string `key` values of a field list.
 *
 * @param {Array} fieldList - `config.fields`.
 * @return {string[]}
 */
function fieldKeys(fieldList) {
	return fieldList.filter((f) => f && typeof f.key === 'string').map((f) => f.key)
}

/**
 * The fields of a form config, or [] when it has none.
 *
 * @param {object} config - the form page config.
 * @return {Array}
 */
function fieldListOf(config) {
	return Array.isArray(config.fields) ? config.fields : []
}

/**
 * The steps and fields errors of one form page config.
 *
 * @param {object} config - the form page config.
 * @param {string} pathBase - the `/pages/<n>/config` pointer prefix.
 * @return {string[]}
 */
function formPageErrors(config, pathBase) {
	const fieldList = fieldListOf(config)
	const declaredKeys = new Set(fieldKeys(fieldList))
	return [
		...validateSteps(config, pathBase, declaredKeys),
		...fieldList.flatMap((field, fIndex) =>
			validateFieldLogic(field, {
				fieldPath: `${pathBase}/fields/${fIndex}`,
				declaredKeys,
			}),
		),
	]
}

/**
 * Validate the `manifest-form-logic` authoring contract (`steps[]` /
 * `visibleWhen` / `validation`) over every `type: "form"` page in the
 * manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - `<pointer>: <code>` error/warning strings.
 * @spec openspec/specs/form-editor-logic/spec.md#req-obfel-005
 */
export function validateFormLogic(manifest) {
	const errors = []
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []

	pages.forEach((page, pIndex) => {
		const config = formConfig(page)
		if (config) {
			errors.push(...formPageErrors(config, `/pages/${pIndex}/config`))
		}
	})

	return errors
}

/**
 * Save-time normalisation (REQ-OBFEL-001): for every `type: "form"` page
 * whose `config.steps[]` is non-empty, any `config.fields[].key` not
 * referenced by any step is appended to the LAST step's `fields[]` — so
 * the written manifest always satisfies the leaf validator's
 * complete-partition rule, even though the unassigned-fields pool is a
 * transient editor state (Decision 2 / the "Unassigned fields" strip's
 * inline warning note in `FormStepsManager.vue` is the live-editor half of
 * this contract; this is the save-time half). Pure — returns a new
 * manifest, never mutates the input. A no-op when `steps` is absent/empty
 * (single-step form) or every field is already assigned.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {object} - the manifest, with any unassigned form-step fields
 *   appended to their page's final step.
 * @spec openspec/specs/form-editor-logic/spec.md#req-obfel-001
 */
export function assignUnassignedFieldsToFinalStep(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) {
		return manifest
	}
	let changed = false
	const pages = manifest.pages.map((page) => {
		const config = formStepsConfig(page)
		const unassigned = config ? unassignedFields(config) : []
		if (unassigned.length === 0) {
			return page
		}
		changed = true
		return withFinalStepFields(page, { config, unassigned })
	})
	return changed ? { ...manifest, pages } : manifest
}

/**
 * The config of a form page with a non-empty `steps[]`, or null.
 *
 * @param {unknown} page - the page entry.
 * @return {object|null}
 */
function formStepsConfig(page) {
	const config = formConfig(page)
	return config && Array.isArray(config.steps) && config.steps.length > 0
		? config
		: null
}

/**
 * The declared field keys no step references, in field order.
 *
 * @param {object} config - the form page config.
 * @return {string[]}
 */
function unassignedFields(config) {
	const assigned = new Set()
	config.steps.forEach((step) => {
		;(Array.isArray(step && step.fields) ? step.fields : []).forEach((k) =>
			assigned.add(k),
		)
	})
	return fieldKeys(fieldListOf(config)).filter((k) => !assigned.has(k))
}

/**
 * The page with the unassigned fields appended to its last step.
 *
 * @param {object} page - the form page.
 * @param {{config: object, unassigned: string[]}} change - the page's config
 *   and the keys to append.
 * @return {object}
 */
function withFinalStepFields(page, change) {
	const steps = change.config.steps.slice()
	const lastIndex = steps.length - 1
	const lastStep = steps[lastIndex] || {}
	steps[lastIndex] = {
		...lastStep,
		fields: [
			...(Array.isArray(lastStep.fields) ? lastStep.fields : []),
			...change.unassigned,
		],
	}
	return { ...page, config: { ...change.config, steps } }
}
