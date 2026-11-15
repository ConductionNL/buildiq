// SPDX-License-Identifier: EUPL-1.2
/**
 * pageCode — app-side validation for maker-written code in a manifest
 * (REQ-BQCC-001, design D1):
 *
 *  - a code component, `config.code = {html, css, js, inputs}`, on a
 *    `type: "custom"` page or on a page's widget (`config.widgets[].config.code`);
 *  - an expression: `config.columns[].expression`,
 *    `config.fields[].expressionDefault` and
 *    `config.fields[].visibleWhen.expression`.
 *
 * It checks shape only. Whether the caller may write code at all is the
 * server's decision (REQ-BQCC-005, `ApplicationsController::saveManifest()`),
 * and whether an expression runs is the evaluator's (D4).
 *
 * Returned entries are `<pointer>: <code>` strings, the convention of
 * `schedules.js` and `formLogic.js`, so the path-prefix inline marks light up
 * the offending editor section. Codes are `buildiq.pageCode.error.*`.
 *
 * @spec openspec/changes/pages-custom-code/specs
 */

/** What a code component may ask the page to hand in (D1). */
export const CODE_INPUTS = Object.freeze(['object', 'rows', 'route', 'user'])

/** The text parts of a code component. */
export const CODE_PARTS = Object.freeze(['html', 'css', 'js'])

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
 * The errors of one `code` block.
 *
 * @param {*} code - the `config.code` value.
 * @param {string} path - its pointer.
 * @return {string[]} - `<pointer>: <code>` error strings.
 * @spec openspec/changes/pages-custom-code/specs
 */
export function codeBlockErrors(code, path) {
	if (!isPlainObject(code)) {
		return [`${path}: buildiq.pageCode.error.codeNotObject`]
	}

	const errors = []
	for (const part of CODE_PARTS) {
		if (code[part] !== undefined && typeof code[part] !== 'string') {
			errors.push(`${path}/${part}: buildiq.pageCode.error.partNotText`)
		}
	}

	const hasText = CODE_PARTS.some((part) => typeof code[part] === 'string' && code[part].trim() !== '')
	if (!hasText) {
		errors.push(`${path}: buildiq.pageCode.error.empty`)
	}

	if (code.inputs !== undefined) {
		if (!Array.isArray(code.inputs)) {
			errors.push(`${path}/inputs: buildiq.pageCode.error.inputsNotList`)
		} else {
			const seen = new Set()
			code.inputs.forEach((input, index) => {
				if (!CODE_INPUTS.includes(input)) {
					errors.push(`${path}/inputs/${index}: buildiq.pageCode.error.unknownInput`)
				} else if (seen.has(input)) {
					errors.push(`${path}/inputs/${index}: buildiq.pageCode.error.duplicateInput`)
				}
				seen.add(input)
			})
		}
	}

	return errors
}

/**
 * The error for one expression string, or none.
 *
 * @param {*} expression - the expression value.
 * @param {string} path - its pointer.
 * @return {string[]} - zero or one `<pointer>: <code>` string.
 * @spec openspec/changes/pages-custom-code/specs
 */
export function expressionErrors(expression, path) {
	if (expression === undefined) {
		return []
	}
	if (typeof expression !== 'string') {
		return [`${path}: buildiq.pageCode.error.expressionNotText`]
	}
	if (expression.trim() === '') {
		return [`${path}: buildiq.pageCode.error.expressionEmpty`]
	}

	return []
}

/**
 * The errors of a config that may carry a code component: the code block and
 * the rule that `component` and `code` exclude each other.
 *
 * @param {object} config - a page's or widget's `config`.
 * @param {string} path - the config's pointer.
 * @return {string[]} - `<pointer>: <code>` error strings.
 */
function codeConfigErrors(config, path) {
	if (!isPlainObject(config) || config.code === undefined) {
		return []
	}

	const errors = codeBlockErrors(config.code, `${path}/code`)
	if (config.component !== undefined && config.component !== '') {
		errors.push(`${path}/code: buildiq.pageCode.error.componentAndCode`)
	}

	return errors
}

/**
 * Validate every code component and expression in a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - `<pointer>: <code>` error strings.
 * @spec openspec/changes/pages-custom-code/specs
 */
export function validatePageCode(manifest) {
	const errors = []
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []

	pages.forEach((page, pIndex) => {
		const config = page && isPlainObject(page.config) ? page.config : null
		if (config === null) {
			return
		}
		const base = `/pages/${pIndex}/config`

		if (page.type === 'custom') {
			errors.push(...codeConfigErrors(config, base))
		} else if (config.code !== undefined) {
			errors.push(`${base}/code: buildiq.pageCode.error.codeOnWrongPage`)
		}

		if (Array.isArray(config.widgets)) {
			config.widgets.forEach((widget, wIndex) => {
				if (isPlainObject(widget)) {
					errors.push(...codeConfigErrors(widget.config, `${base}/widgets/${wIndex}/config`))
				}
			})
		}

		if (Array.isArray(config.columns)) {
			config.columns.forEach((column, cIndex) => {
				if (isPlainObject(column)) {
					errors.push(...expressionErrors(column.expression, `${base}/columns/${cIndex}/expression`))
				}
			})
		}

		if (Array.isArray(config.fields)) {
			config.fields.forEach((field, fIndex) => {
				if (!isPlainObject(field)) {
					return
				}
				errors.push(...expressionErrors(field.expressionDefault, `${base}/fields/${fIndex}/expressionDefault`))
				if (isPlainObject(field.visibleWhen)) {
					errors.push(...expressionErrors(field.visibleWhen.expression, `${base}/fields/${fIndex}/visibleWhen/expression`))
				}
			})
		}
	})

	return errors
}
