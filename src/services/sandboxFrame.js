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
	const found = []
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	pages.forEach((page, pIndex) => {
		const config = page && isPlainObject(page.config) ? page.config : null
		if (config === null) {
			return
		}
		const pageId = String(page.id || pIndex)
		const pageLabel = page.title || pageId
		if (page.type === 'custom' && isPlainObject(config.code)) {
			found.push({ key: `/pages/${pIndex}/config/code`, pageId, id: pageId, label: pageLabel, code: config.code })
		}
		const widgets = Array.isArray(config.widgets) ? config.widgets : []
		widgets.forEach((widget, wIndex) => {
			if (isPlainObject(widget) && isPlainObject(widget.config) && isPlainObject(widget.config.code)) {
				const id = String(widget.id || wIndex)
				found.push({
					key: `/pages/${pIndex}/config/widgets/${wIndex}/config/code`,
					pageId,
					id,
					label: `${pageLabel} › ${widget.title || id}`,
					code: widget.config.code,
				})
			}
		})
	})
	return found
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
	const found = []
	const add = (key, expression) => {
		if (typeof expression === 'string' && expression.trim() !== '') {
			found.push({ key, expression })
		}
	}
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	pages.forEach((page, pIndex) => {
		const config = page && isPlainObject(page.config) ? page.config : null
		if (config === null) {
			return
		}
		const base = `/pages/${pIndex}/config`
		;(Array.isArray(config.columns) ? config.columns : []).forEach((column, cIndex) => {
			if (isPlainObject(column)) {
				add(`${base}/columns/${cIndex}/expression`, column.expression)
			}
		})
		;(Array.isArray(config.fields) ? config.fields : []).forEach((field, fIndex) => {
			if (!isPlainObject(field)) {
				return
			}
			add(`${base}/fields/${fIndex}/expressionDefault`, field.expressionDefault)
			if (isPlainObject(field.visibleWhen)) {
				add(`${base}/fields/${fIndex}/visibleWhen/expression`, field.visibleWhen.expression)
			}
		})
	})
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
		if (!message || message.type !== 'evaluated' || event.source !== frameWindow()) {
			return
		}
		const entry = pending.get(message.id)
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
		dispose() {
			host.removeEventListener('message', onMessage)
			pending.forEach((entry) => clearTimeout(entry.timer))
			pending.clear()
		},
	}
}
