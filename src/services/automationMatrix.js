// SPDX-License-Identifier: EUPL-1.2
/**
 * automationMatrix — the single shared v1 compilation matrix constant
 * (design.md Decision 2 of the automation-designer change, extended by
 * design.md Decision 1 of automation-approval-steps and design.md Decision 2
 * of automation-document-action): which ACTION types are expressible for a
 * given TRIGGER type, and whether a CONDITION may be attached. Consumed by
 * `AutomationEditDialog` (inline validation + disabling unsupported
 * combinations), the Automations list (badge text) and
 * `AutomationCompilerService`'s PHP-side enforcement — the two must be
 * kept in sync by hand (no cross-language codegen exists), so this file's
 * shape is the canonical reference the PHP docblock cites back to.
 *
 * @spec openspec/changes/automation-document-action/tasks.md#4.3
 * @spec openspec/specs/automation-designer/spec.md#req-autd-003
 *
 * DEVIATION FROM design.md (documented, mirrored from the backend — see
 * `lib/Service/AutomationCompilerService.php` class docblock): design.md's
 * Decision 2 table marks `manual` + `run-synchronization` as a ✅ "rules
 * backend" cell. No primitive to invoke an OpenConnector synchronization on
 * demand exists anywhere in openbuild; the compiler blocks that cell
 * fail-closed pending a verified OpenConnector "run now" API, so the matrix
 * here matches the compiler's ACTUAL enforced behaviour, not the
 * originally-proposed table.
 *
 * @spec openspec/changes/automation-designer/tasks.md#5.4
 * @spec openspec/specs/automation-designer/spec.md#req-autd-003
 * @spec openspec/changes/automation-approval-steps/tasks.md#3.2
 * @spec openspec/specs/automation-designer/spec.md#req-autd-003
 */

/** Every trigger type the v1 designer supports. */
export const TRIGGER_TYPES = Object.freeze([
	'object-created',
	'object-updated',
	'object-deleted',
	'lifecycle-transition',
	'schedule',
	'manual',
])

/** Every action type the v1 designer supports. */
export const ACTION_TYPES = Object.freeze([
	'send-notification',
	'run-synchronization',
	'object-op',
	'webhook',
	'approval',
	'generateDocument',
])

/**
 * Trigger type → allowed action types (mirrors
 * `AutomationCompilerService::MATRIX` exactly). `approval` (automation-
 * approval-steps) is expressible only on the event/lifecycle-transition
 * triggers — an approval step binds to a concrete object instance, which
 * only those triggers carry (design.md Decision 1). `generateDocument`
 * (automation-document-action) is expressible on the SAME four triggers for
 * the identical reason — Docudesk generates a document FOR a concrete
 * object instance, which a `schedule`/`manual` trigger does not carry.
 */
export const MATRIX = Object.freeze({
	'object-created': Object.freeze([
		'send-notification',
		'approval',
		'generateDocument',
	]),
	'object-updated': Object.freeze([
		'send-notification',
		'approval',
		'generateDocument',
	]),
	'object-deleted': Object.freeze([
		'send-notification',
		'approval',
		'generateDocument',
	]),
	'lifecycle-transition': Object.freeze([
		'send-notification',
		'object-op',
		'webhook',
		'approval',
		'generateDocument',
	]),
	schedule: Object.freeze(['run-synchronization']),
	manual: Object.freeze(['send-notification', 'object-op', 'webhook']),
})

/** Trigger types on which a condition is v1-supported. */
export const CONDITION_ALLOWED_TRIGGERS = Object.freeze(['manual'])

/**
 * Whether a trigger/action combination is expressible in v1.
 *
 * @param {string} triggerType - the automation's trigger type.
 * @param {string} actionType - the candidate action type.
 * @return {boolean}
 */
export function isActionAllowed(triggerType, actionType) {
	const allowed = MATRIX[triggerType]
	if (!allowed) {
		return false
	}
	return allowed.includes(actionType)
}

/**
 * Whether a condition may be attached to the given trigger type in v1.
 *
 * @param {string} triggerType - the automation's trigger type.
 * @return {boolean}
 */
export function isConditionAllowed(triggerType) {
	return CONDITION_ALLOWED_TRIGGERS.includes(triggerType)
}

/**
 * Human-readable reason a combination is blocked, or empty string when
 * allowed. Mirrors the message shape `AutomationCompilerService` throws
 * (REQ-AUTD-003 — "not yet expressible declaratively").
 *
 * @param {string} triggerType - the automation's trigger type.
 * @param {string} actionType - the candidate action type.
 * @return {string}
 * @spec openspec/changes/automation-approval-steps/tasks.md#3.2
 */
export function blockedActionReason(triggerType, actionType) {
	if (isActionAllowed(triggerType, actionType)) {
		return ''
	}
	if (actionType === 'approval') {
		return t(
			'openbuild',
			'Approval actions are only supported on object-event and lifecycle-transition triggers in v1.',
		)
	}
	if (actionType === 'generateDocument') {
		return t(
			'openbuild',
			'Document-generation actions are only supported on object-event and lifecycle-transition triggers in v1.',
		)
	}
	return t(
		'openbuild',
		'Trigger "{trigger}" + action "{action}" is not yet expressible declaratively.',
		{ trigger: triggerType, action: actionType },
	)
}

/**
 * Human-readable reason a condition is blocked on the given trigger, or
 * empty string when allowed.
 *
 * @param {string} triggerType - the automation's trigger type.
 * @return {string}
 */
export function blockedConditionReason(triggerType) {
	if (isConditionAllowed(triggerType)) {
		return ''
	}
	return t(
		'openbuild',
		'A condition is only supported on the "manual" trigger in v1.',
	)
}
