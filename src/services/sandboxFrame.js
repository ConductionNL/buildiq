// SPDX-License-Identifier: EUPL-1.2
/**
 * sandboxFrame — the page designer's side of the code sandbox
 * (pages-custom-code D2-D4, T07).
 *
 * Maker code never runs in the designer. A code component is posted into the
 * preview document (`GET /apps/buildiq/sandbox/preview`), and expressions into
 * the evaluator (`GET /apps/buildiq/sandbox/evaluator`). Both are framed with
 * `sandbox="allow-scripts"` and served under a policy with no network.
 *
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */

import { generateUrl } from '@nextcloud/router'

/** How long the evaluator may take to answer one batch, in milliseconds (D4). */
export const EVALUATE_TIMEOUT_MS = 1000

/**
 * Whether `value` is a plain object (not an array, not null).
 *
 * @param {unknown} value - anything.
 * @return {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * The URL of a sandbox document.
 *
 * @param {'preview'|'evaluator'} name - which document.
 * @return {string}
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */
export function sandboxDocumentUrl(name) {
	return generateUrl(`/apps/buildiq/sandbox/${name}`)
}

/**
 * Every code component of a manifest: a custom page's `config.code` and a
 * widget's `config.widgets[].config.code`, on any page.
 *
 * @param {object} manifest - the manifest under edit.
 * @return {Array<{key: string, pageId: string, id: string, label: string, code: object}>}
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */
export function codeComponentsOf(manifest) {
	return pageConfigsOf(manifest).flatMap(({ page, config, pIndex }) => {
		const pageId = String(page.id || pIndex)
		const pageLabel = page.title || pageId
		const found = []
		if (page.type === 'custom' && isPlainObject(config.code)) {
			found.push({ key: `/pages/${pIndex}/config/code`, pageId, id: pageId, label: pageLabel, code: config.code })
		}
		objectsOf(config.widgets).forEach(([widget, wIndex]) => {
			if (!hasCodeConfig(widget)) {
				return
			}
			const id = String(widget.id || wIndex)
			found.push({
				key: `/pages/${pIndex}/config/widgets/${wIndex}/config/code`,
				pageId,
				id,
				label: `${pageLabel} › ${widget.title || id}`,
				code: widget.config.code,
			})
		})
		return found
	})
}

/**
 * The pages of a manifest whose config is an object, with their index.
 *
 * @param {object} manifest - the manifest under edit.
 * @return {Array<{page: object, config: object, pIndex: number}>}
 */
function pageConfigsOf(manifest) {
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	return pages
		.map((page, pIndex) => ({ page, config: page && isPlainObject(page.config) ? page.config : null, pIndex }))
		.filter(({ config }) => config !== null)
}

/**
 * The plain-object entries of a list, with their index.
 *
 * @param {*} list - a config list.
 * @return {Array<[object, number]>}
 */
function objectsOf(list) {
	if (!Array.isArray(list)) {
		return []
	}
	return list.map((entry, index) => [entry, index]).filter(([entry]) => isPlainObject(entry))
}

/**
 * Whether a widget carries a code block in its config.
 *
 * @param {object} widget - a widget.
 * @return {boolean}
 */
function hasCodeConfig(widget) {
	return isPlainObject(widget.config) && isPlainObject(widget.config.code)
}

/**
 * Every expression of a manifest, keyed by its pointer: computed columns,
 * computed field defaults and visibility expressions.
 *
 * @param {object} manifest - the manifest under edit.
 * @return {Array<{key: string, expression: string}>}
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */
export function expressionsOf(manifest) {
	const found = pageConfigsOf(manifest).flatMap(({ config, pIndex }) => {
		const base = `/pages/${pIndex}/config`
		return [
			...objectsOf(config.columns).map(([column, cIndex]) => ({ key: `${base}/columns/${cIndex}/expression`, expression: column.expression })),
			...objectsOf(config.fields).flatMap(([field, fIndex]) => fieldExpressionsOf(field, `${base}/fields/${fIndex}`)),
		]
	})
	return found.filter(({ expression }) => typeof expression === 'string' && expression.trim() !== '')
}

/**
 * A form field's expression default and visibility expression, keyed by pointer.
 *
 * @param {object} field - a form field.
 * @param {string} path - its pointer.
 * @return {Array<{key: string, expression: *}>}
 */
function fieldExpressionsOf(field, path) {
	const found = [{ key: `${path}/expressionDefault`, expression: field.expressionDefault }]
	if (isPlainObject(field.visibleWhen)) {
		found.push({ key: `${path}/visibleWhen/expression`, expression: field.visibleWhen.expression })
	}
	return found
}

/**
 * The inputs a preview hands a code component: only those it asks for, with
 * sample values, since the designer has no record open.
 *
 * @param {object} code - the code block.
 * @param {{id: string, name: string}} user - the signed-in user.
 * @return {object}
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */
export function previewInputs(code, user) {
	const samples = { object: {}, rows: [], route: {}, user }
	const asked = code && Array.isArray(code.inputs) ? code.inputs : []
	const inputs = {}
	asked.forEach((key) => {
		if (Object.hasOwn(samples, key)) {
			inputs[key] = samples[key]
		}
	})
	return inputs
}

/**
 * Whether a message event is an evaluator answer from the given frame.
 *
 * @param {MessageEvent} event - the message event.
 * @param {?Window} source - the evaluator frame's window.
 * @return {boolean}
 */
function isEvaluatedFrom(event, source) {
	const message = event.data
	return Boolean(message) && message.type === 'evaluated' && event.source === source
}

/**
 * A client for the evaluator frame (the protocol of SandboxController::evaluator).
 *
 * Each `evaluate()` posts one batch with its own id and resolves with the
 * frame's `results` (`{key: {value}|{error}|{disabled}}`). An answer that does
 * not come in time resolves every key with a timeout error. Messages from any
 * other window, or for another id, are ignored.
 *
 * @param {() => ?Window} frameWindow - returns the evaluator frame's window.
 * @param {object} [options] - options.
 * @param {number} [options.timeout] - milliseconds before a batch times out.
 * @param {Window} [options.host] - the window that receives the answers.
 * @return {{evaluate: (items: Array<object>, scope: object) => Promise<object>, dispose: () => void}}
 * @spec openspec/changes/pages-custom-code/tasks.md#t07
 */
export function createEvaluatorClient(frameWindow, { timeout = EVALUATE_TIMEOUT_MS, host = window } = {}) {
	const pending = new Map()
	let counter = 0

	const onMessage = (event) => {
		const message = event.data
		const entry = isEvaluatedFrom(event, frameWindow()) ? pending.get(message.id) : undefined
		if (!entry) {
			return
		}
		pending.delete(message.id)
		clearTimeout(entry.timer)
		entry.resolve(isPlainObject(message.results) ? message.results : {})
	}
	host.addEventListener('message', onMessage)

	return {
		/**
		 * Evaluate a batch of expressions over one scope.
		 *
		 * @param {Array<{key: string, expression: string}>} items - the expressions.
		 * @param {object} scope - `{row, values, user, route}`.
		 * @return {Promise<object>} the results by key.
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		evaluate(items, scope) {
			const target = frameWindow()
			if (!target || items.length === 0) {
				return Promise.resolve({})
			}
			counter += 1
			const id = `eval-${counter}`
			return new Promise((resolve) => {
				const timer = setTimeout(() => {
					pending.delete(id)
					const results = {}
					items.forEach((item) => {
						results[item.key] = { error: 'timeout' }
					})
					resolve(results)
				}, timeout)
				pending.set(id, { resolve, timer })
				target.postMessage({ type: 'evaluate', id, items, scope }, '*')
			})
		},

		/** Stop listening and drop unanswered batches. */
		/**
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		dispose() {
			host.removeEventListener('message', onMessage)
			pending.forEach((entry) => clearTimeout(entry.timer))
			pending.clear()
		},
	}
}
