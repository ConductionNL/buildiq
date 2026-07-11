// SPDX-License-Identifier: EUPL-1.2
/**
 * automationMatrix — the single shared v1 compilation matrix constant
 * (design.md Decision 2 of the automation-designer change): which ACTION
 * types are expressible for a given TRIGGER type, and whether a CONDITION
 * may be attached. Consumed by `AutomationEditDialog` (inline validation +
 * disabling unsupported combinations), the Automations list (badge text)
 * and `AutomationCompilerService`'s PHP-side enforcement — the two must be
 * kept in sync by hand (no cross-language codegen exists), so this file's
 * shape is the canonical reference the PHP docblock cites back to.
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
 * @spec openspec/changes/automation-designer/specs/automation-designer/spec.md#req-autd-003
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

/** Every action type the v1 designer supports (`approval` is reserved, never selectable). */
export const ACTION_TYPES = Object.freeze([
	'send-notification',
	'run-synchronization',
	'object-op',
	'webhook',
])

/**
 * Trigger type → allowed action types (mirrors
 * `AutomationCompilerService::MATRIX` exactly).
 */
export const MATRIX = Object.freeze({
	'object-created': Object.freeze(['send-notification']),
	'object-updated': Object.freeze(['send-notification']),
	'object-deleted': Object.freeze(['send-notification']),
	'lifecycle-transition': Object.freeze(['send-notification', 'object-op', 'webhook']),
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
	if (actionType === 'approval') {
		return false
	}
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
 */
export function blockedActionReason(triggerType, actionType) {
	if (actionType === 'approval') {
		return t('openbuild', 'The approval action is reserved for a future release and is not yet expressible declaratively.')
	}
	if (isActionAllowed(triggerType, actionType)) {
		return ''
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
	return t('openbuild', 'A condition is only supported on the "manual" trigger in v1.')
}
