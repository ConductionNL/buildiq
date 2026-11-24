/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow bindings of an application (`Application.flows`) as the settings
 * dialog edits them: the flows a maker bound, each with where it runs
 * (`runOn`), and the wrapper flows buildiq keeps for those entries, which the
 * maker never sees or edits (logic-shared-flows-and-automations D1, D2).
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */

/** The name every wrapper flow starts with (FlowBindingWrapperService::NAME_PREFIX). */
export const WRAPPER_PREFIX = 'aut-attach-'

/** The record events a "Run on" entry may name. */
export const RUN_ON_EVENTS = ['object.created', 'object.updated', 'object.deleted']

/** The mode of an entry an app user starts from a record. */
export const FROM_RECORD = 'from-record'

/**
 * Whether a binding is one of buildiq's wrappers rather than a flow the maker bound.
 *
 * @param {object} binding One `Application.flows` entry.
 * @return {boolean}
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */
export function isWrapperBinding(binding) {
	return !!binding
		&& typeof binding.label === 'string'
		&& binding.label.startsWith(WRAPPER_PREFIX)
		&& !!binding.sourceUuid
}

/**
 * The "when" of a `runOn` entry: its event, or `from-record`.
 *
 * @param {object} entry A `runOn` entry.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */
export function runOnWhen(entry) {
	if (entry && entry.mode === FROM_RECORD) {
		return FROM_RECORD
	}
	return (entry && entry.event) || ''
}

/**
 * A `runOn` entry for a schema and a "when", in the stored shape: exactly one
 * of `event` and `mode` (the register fragment's `oneOf`).
 *
 * @param {string} schema The schema slug.
 * @param {string} when An event from RUN_ON_EVENTS, or `from-record`.
 * @return {{schema: string, event?: string, mode?: string}}
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */
export function runOnEntry(schema, when) {
	if (when === FROM_RECORD) {
		return { schema, mode: FROM_RECORD }
	}
	return { schema, event: RUN_ON_EVENTS.includes(when) ? when : 'object.updated' }
}

/**
 * The new `Application.flows` after the maker picked flows: a flow that stays
 * picked keeps its binding as it was (its `runOn` and `sourceUuid` with it), a
 * new one is bound without `runOn`, and the wrappers are carried along
 * untouched for the server to reconcile.
 *
 * @param {Array<object>} bindings The current `Application.flows`.
 * @param {Array<{label: string, value: string}>} picked The picked options.
 * @return {Array<object>}
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */
export function withPickedFlows(bindings, picked) {
	const list = Array.isArray(bindings) ? bindings : []
	const bound = (picked || []).map((option) => {
		const existing = list.find((binding) => !isWrapperBinding(binding) && binding.flow === option.value)
		return existing || { flow: option.value, label: option.label }
	})
	return [...bound, ...list.filter(isWrapperBinding)]
}

/**
 * The new `Application.flows` with one bound flow's `runOn` replaced.
 *
 * @param {Array<object>} bindings The current `Application.flows`.
 * @param {string} flow The bound flow's uuid.
 * @param {Array<object>} runOn Its new entries.
 * @return {Array<object>}
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */
export function withRunOn(bindings, flow, runOn) {
	return (Array.isArray(bindings) ? bindings : []).map((binding) => (
		!isWrapperBinding(binding) && binding.flow === flow
			? { ...binding, runOn }
			: binding
	))
}
