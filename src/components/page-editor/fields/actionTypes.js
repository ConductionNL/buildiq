// SPDX-License-Identifier: EUPL-1.2
/**
 * The typed page actions the action editor authors (pages-action-buttons D1),
 * the migration of the old `target` actions (D3), and the lifecycle guard
 * (D2). Pure functions so the component and its tests share one truth.
 *
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md
 */

/**
 * The action types a maker can pick for a new button, in menu order, with the
 * keys each type owns besides the common ones.
 *
 * @type {Array<{type: string, keys: string[]}>}
 */
export const EDITABLE_TYPES = [
	{ type: 'object-op', keys: ['op', 'values', 'confirm', 'confirmMessage'] },
	{ type: 'run-node', keys: ['flowId', 'nodeId', 'confirm', 'confirmMessage', 'successMessage'] },
	{ type: 'open-form', keys: ['register', 'schema'] },
	{ type: 'open-page', keys: ['target'] },
	{ type: 'navigate', keys: ['target'] },
	{ type: 'export', keys: ['formats'] },
]

/**
 * Keys every action may carry, whatever its type.
 *
 * @type {string[]}
 */
export const COMMON_KEYS = ['id', 'label', 'icon', 'type', 'visibleWhen', 'variant']

/**
 * The export formats the renderer offers.
 *
 * @type {string[]}
 */
export const EXPORT_FORMATS = ['csv', 'excel', 'json']

/**
 * The old `target` behaviours, from before actions were typed.
 *
 * @type {string[]}
 */
const LEGACY_TARGETS = ['navigate', 'emit', 'none']

/**
 * Whether a type is one a maker may author here. Others (handler, agent,
 * api-call, open-modal, refresh, toggle) are shown read-only.
 *
 * @param {string} type - The action type.
 * @return {boolean}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
 */
export function isEditableType(type) {
	return EDITABLE_TYPES.some((entry) => entry.type === type)
}

/**
 * Whether an action still carries the old `target` behaviour.
 *
 * @param {object} action - The stored action.
 * @return {boolean}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-old-actions-keep-working-req-bqab-004
 */
export function isLegacyAction(action) {
	return Boolean(action) && !action.type && LEGACY_TARGETS.includes(action.target)
}

/**
 * The typed equivalent of an old action, or null when it is dropped (`none`).
 *
 * `navigate` becomes `open-page` when its `route` names a page of this app,
 * otherwise `navigate` (a link, its URL still to be filled in); `emit`
 * becomes a `handler` named after the action id, kept read-only.
 *
 * @param {object} action - The stored action.
 * @param {string[]} pageIds - The ids of this app's pages.
 * @return {object|null}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-old-actions-keep-working-req-bqab-004
 */
export function migrateAction(action, pageIds = []) {
	if (!isLegacyAction(action)) {
		return action
	}
	const { target, route, ...rest } = action
	if (target === 'none') {
		return null
	}
	if (target === 'emit') {
		return { ...rest, type: 'handler', handler: rest.handler || rest.id || '' }
	}
	if (route && pageIds.includes(route)) {
		return { ...rest, type: 'open-page', target: route }
	}
	return { ...rest, type: 'navigate' }
}

/**
 * Migrate a list of actions: the typed list and how many old ones were dropped.
 *
 * @param {object[]} actions - The stored actions.
 * @param {string[]} pageIds - The ids of this app's pages.
 * @return {{actions: object[], migrated: number, dropped: number}}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-old-actions-keep-working-req-bqab-004
 */
export function migrateActions(actions, pageIds = []) {
	const list = Array.isArray(actions) ? actions : []
	const out = []
	let migrated = 0
	let dropped = 0
	for (const action of list) {
		if (!isLegacyAction(action)) {
			out.push(action)
			continue
		}
		migrated++
		const next = migrateAction(action, pageIds)
		if (next === null) {
			dropped++
		} else {
			out.push(next)
		}
	}
	return { actions: out, migrated, dropped }
}

/**
 * Switch an action to another type: keep the common keys, drop the keys of
 * the old type, and seed what the new type requires.
 *
 * @param {object} action - The current action.
 * @param {string} type - The new type.
 * @return {object}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
 */
export function withType(action, type) {
	const next = {}
	for (const key of COMMON_KEYS) {
		if (action && action[key] !== undefined) {
			next[key] = action[key]
		}
	}
	next.type = type
	if (type === 'object-op') {
		next.op = 'patch'
		next.values = {}
	}
	if (type === 'run-node') {
		next.flowId = ''
		next.nodeId = ''
	}
	if (type === 'open-form') {
		next.schema = ''
	}
	return next
}

/**
 * The lifecycle field an `object-op` would patch directly, or null.
 *
 * A status moves through `lifecycleActions` so OpenRegister's lifecycle
 * decides what is allowed; a plain patch of that field would bypass its
 * guards (pages-action-buttons D2).
 *
 * @param {object} action - The action.
 * @param {string[]} lifecycleFields - The lifecycle fields of the page's schema.
 * @return {string|null}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
 */
export function lifecycleFieldPatched(action, lifecycleFields = ['status']) {
	if (!action || action.type !== 'object-op' || !action.values || typeof action.values !== 'object') {
		return null
	}
	return Object.keys(action.values).find((key) => lifecycleFields.includes(key)) || null
}

/**
 * What is missing for an action to be saved, as a short message key, or ''.
 *
 * @param {object} action - The action.
 * @return {string}
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
 */
export function missingFor(action) {
	if (!action || !action.id || !action.label) {
		return 'An action needs an id and a label.'
	}
	if (action.type === 'run-node' && (!action.flowId || !action.nodeId)) {
		return 'Pick the flow and the node to start.'
	}
	if (action.type === 'open-form' && !action.schema) {
		return 'Pick the schema the form creates.'
	}
	if ((action.type === 'open-page' || action.type === 'navigate') && !action.target) {
		return 'Pick where the button goes.'
	}
	return ''
}
