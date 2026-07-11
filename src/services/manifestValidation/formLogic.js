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
 * `openbuild.formLogic.error.*` for hard errors and
 * `openbuild.formLogic.warning.*` for the one warning-level rule.
 *
 * @spec openspec/changes/form-editor-logic/specs/form-editor-logic/spec.md#req-obfel-005
 */

/** The visibleWhen op allow-list (mirrors `$defs/visibleWhen.properties.op.enum`). */
export const VISIBLE_WHEN_OPS = Object.freeze(['eq', 'neq', 'gt', 'gte', 'lt', 'lte'])

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
		errors.push(`${pathBase}/steps: openbuild.formLogic.error.steps-not-array`)
		return errors
	}

	const seenIds = new Set()
	const assignmentCount = new Map()

	config.steps.forEach((step, sIndex) => {
		const stepPath = `${pathBase}/steps/${sIndex}`
		if (!isPlainObject(step)) {
			errors.push(`${stepPath}: openbuild.formLogic.error.step-invalid-shape`)
			return
		}
		if (typeof step.title !== 'string' || step.title.trim() === '') {
			errors.push(`${stepPath}/title: openbuild.formLogic.error.step-title-required`)
		}
		if (!Array.isArray(step.fields)) {
			errors.push(`${stepPath}/fields: openbuild.formLogic.error.step-fields-not-array`)
			return
		}
		if (typeof step.id === 'string' && step.id !== '') {
			if (seenIds.has(step.id)) {
				errors.push(`${stepPath}/id: openbuild.formLogic.error.duplicate-step-id`)
			}
			seenIds.add(step.id)
		}
		step.fields.forEach((key, fIndex) => {
			if (typeof key !== 'string' || !declaredKeys.has(key)) {
				errors.push(`${stepPath}/fields/${fIndex}: openbuild.formLogic.error.dangling-step-field`)
				return
			}
			assignmentCount.set(key, (assignmentCount.get(key) || 0) + 1)
		})
	})

	assignmentCount.forEach((count, key) => {
		if (count > 1) {
			errors.push(`${pathBase}/steps: openbuild.formLogic.error.duplicate-field-assignment(${key})`)
		}
	})

	return errors
}

/**
 * Validate one field's `visibleWhen` (LOCAL mode only — `endpoint` /
 * `source` shapes are canonical-schema territory, not re-validated here
 * beyond being objects) and `validation` object.
 *
 * @param {object} field - the field entry.
 * @param {string} fieldPath - `/pages/<n>/config/fields/<i>` pointer prefix.
 * @param {Set<string>} declaredKeys - `config.fields[].key` values.
 * @return {string[]} - `<pointer>: <code>` error/warning strings.
 */
function validateFieldLogic(field, fieldPath, declaredKeys) {
	const errors = []
	if (!isPlainObject(field)) {
		return errors
	}

	const visibleWhen = isPlainObject(field.visibleWhen) ? field.visibleWhen : null
	if (visibleWhen && !visibleWhen.endpoint && !visibleWhen.source) {
		// LOCAL mode.
		if (typeof visibleWhen.field !== 'string' || visibleWhen.field.trim() === '') {
			errors.push(`${fieldPath}/visibleWhen/field: openbuild.formLogic.error.condition-field-required`)
		} else if (!declaredKeys.has(visibleWhen.field)) {
			errors.push(`${fieldPath}/visibleWhen/field: openbuild.formLogic.error.dangling-condition-field`)
		}
		if (visibleWhen.op !== undefined && !VISIBLE_WHEN_OPS.includes(visibleWhen.op)) {
			errors.push(`${fieldPath}/visibleWhen/op: openbuild.formLogic.error.condition-op-not-allowed`)
		}
	}

	const validation = isPlainObject(field.validation) ? field.validation : null
	if (validation) {
		if (validation.required !== undefined && typeof validation.required !== 'boolean') {
			errors.push(`${fieldPath}/validation/required: openbuild.formLogic.error.validation-required-not-boolean`)
		}
		const hasMin = validation.min !== undefined
		const hasMax = validation.max !== undefined
		if (hasMin && typeof validation.min !== 'number') {
			errors.push(`${fieldPath}/validation/min: openbuild.formLogic.error.validation-min-not-number`)
		}
		if (hasMax && typeof validation.max !== 'number') {
			errors.push(`${fieldPath}/validation/max: openbuild.formLogic.error.validation-max-not-number`)
		}
		if (hasMin && hasMax && typeof validation.min === 'number' && typeof validation.max === 'number' && validation.min > validation.max) {
			errors.push(`${fieldPath}/validation: openbuild.formLogic.error.validation-min-greater-than-max`)
		}
		if (typeof validation.pattern === 'string') {
			try {
				// eslint-disable-next-line no-new
				new RegExp(validation.pattern)
			} catch {
				errors.push(`${fieldPath}/validation/pattern: openbuild.formLogic.error.validation-pattern-does-not-compile`)
			}
		}
		if (validation.message !== undefined && typeof validation.message !== 'string') {
			errors.push(`${fieldPath}/validation/message: openbuild.formLogic.error.validation-message-not-string`)
		}
		// Warning-level: an un-migrated field carries both the structured
		// object AND a legacy flat key (Decision 4 — migration is opt-in
		// per field, so this can legitimately persist until the developer
		// edits that field's Validation section).
		if (field.required !== undefined || field.pattern !== undefined) {
			errors.push(`${fieldPath}: openbuild.formLogic.warning.flat-and-structured-validation`)
		}
	}

	return errors
}

/**
 * Validate the `manifest-form-logic` authoring contract (`steps[]` /
 * `visibleWhen` / `validation`) over every `type: "form"` page in the
 * manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - `<pointer>: <code>` error/warning strings.
 * @spec openspec/changes/form-editor-logic/specs/form-editor-logic/spec.md#req-obfel-005
 */
export function validateFormLogic(manifest) {
	const errors = []
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []

	pages.forEach((page, pIndex) => {
		if (!page || page.type !== 'form') {
			return
		}
		const config = isPlainObject(page.config) ? page.config : null
		if (!config) {
			return
		}
		const pathBase = `/pages/${pIndex}/config`
		const fieldList = Array.isArray(config.fields) ? config.fields : []
		const declaredKeys = new Set(
			fieldList.filter((f) => f && typeof f.key === 'string').map((f) => f.key),
		)

		errors.push(...validateSteps(config, pathBase, declaredKeys))

		fieldList.forEach((field, fIndex) => {
			errors.push(...validateFieldLogic(field, `${pathBase}/fields/${fIndex}`, declaredKeys))
		})
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
 * @spec openspec/changes/form-editor-logic/specs/form-editor-logic/spec.md#req-obfel-001
 */
export function assignUnassignedFieldsToFinalStep(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) {
		return manifest
	}
	let changed = false
	const pages = manifest.pages.map((page) => {
		if (!page || page.type !== 'form') {
			return page
		}
		const config = isPlainObject(page.config) ? page.config : null
		if (!config || !Array.isArray(config.steps) || config.steps.length === 0) {
			return page
		}
		const fieldList = Array.isArray(config.fields) ? config.fields : []
		const declaredKeys = fieldList
			.filter((f) => f && typeof f.key === 'string')
			.map((f) => f.key)
		const assigned = new Set()
		config.steps.forEach((step) => {
			(Array.isArray(step && step.fields) ? step.fields : []).forEach((k) => assigned.add(k))
		})
		const unassigned = declaredKeys.filter((k) => !assigned.has(k))
		if (unassigned.length === 0) {
			return page
		}
		changed = true
		const steps = config.steps.slice()
		const lastIndex = steps.length - 1
		const lastStep = steps[lastIndex] || {}
		steps[lastIndex] = {
			...lastStep,
			fields: [...(Array.isArray(lastStep.fields) ? lastStep.fields : []), ...unassigned],
		}
		return { ...page, config: { ...config, steps } }
	})
	return changed ? { ...manifest, pages } : manifest
}
