// SPDX-License-Identifier: EUPL-1.2
/**
 * schedules — app-side validation of the manifest top-level `schedules[]`
 * array (apphost-scheduling authoring, REQ-OBSA-006). The `schedules[]`
 * JSON-schema definition ships from nextcloud-vue (#132); the canonical
 * `validateManifest` treats it as an additive top-level key
 * (unknown-but-tolerated), and this module supplies the strict shape +
 * cross-reference checks buildiq needs, surfaced through the
 * `useManifestValidator` pipeline (the same mechanism the
 * workflow/connector/theme/document siblings use).
 *
 * Each entry declares a scheduled task: a cadence (exactly one of `interval`
 * seconds OR a 5-field `cron`), an allow-listed `action`, its `arguments`,
 * an `enabled` flag and a stable unique `id`.
 *
 * Returned errors are `<pointer>: <i18n-error-code>` strings so the existing
 * path-prefix → inline-mark mechanism (REQ-OBPD-011) lights up the offending
 * editor entry.
 *
 * @spec openspec/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */

/** The allow-list of supported schedule actions in v1. */
export const SCHEDULE_ACTIONS = Object.freeze(['openconnector:synchronization'])

/** The only keys a schedule entry may carry. */
const ALLOWED_KEYS = Object.freeze([
	'id',
	'enabled',
	'interval',
	'cron',
	'action',
	'arguments',
])

/** kebab-case slug id (e.g. `nightly-brp-sync`). */
const SLUG_RE = /^[a-z0-9]+(-[a-z0-9]+)*$/

/**
 * A single cron field: a star, a number, a range `a-b`, a step (star or
 * range followed by a slash and a divisor), or a comma-separated list of
 * those.
 */
const CRON_FIELD_RE = /^(\*|\d+)(-\d+)?(\/\d+)?(,(\*|\d+)(-\d+)?(\/\d+)?)*$/

/**
 * Whether a string is a well-formed 5-field cron expression (minute hour
 * day-of-month month day-of-week).
 *
 * @param {string} expr - the cron expression.
 * @return {boolean}
 */
export function isValidCron(expr) {
	if (typeof expr !== 'string') {
		return false
	}
	const fields = expr.trim().split(/\s+/)
	if (fields.length !== 5) {
		return false
	}
	return fields.every((f) => CRON_FIELD_RE.test(f))
}

/**
 * Unknown keys on the entry.
 *
 * @param {object} entry - the schedule entry.
 * @param {string} pointer - the entry's JSON-Pointer.
 * @return {string[]}
 */
function keyErrors(entry, pointer) {
	return Object.keys(entry)
		.filter((key) => !ALLOWED_KEYS.includes(key))
		.map((key) => `${pointer}/${key}: buildiq.schedule.error.unknown-key`)
}

/**
 * id: a required kebab-case slug.
 *
 * @param {object} entry - the schedule entry.
 * @param {string} pointer - the entry's JSON-Pointer.
 * @return {string[]}
 */
function idErrors(entry, pointer) {
	if (typeof entry.id !== 'string' || entry.id.trim() === '') {
		return [`${pointer}: buildiq.schedule.error.id-required`]
	}
	if (!SLUG_RE.test(entry.id)) {
		return [`${pointer}: buildiq.schedule.error.id-not-slug`]
	}
	return []
}

/**
 * The cadence error code of an entry: exactly one of interval | cron, and
 * that one well-formed. Null when the cadence is valid.
 *
 * @param {object} entry - the schedule entry.
 * @return {string|null}
 */
function cadenceErrorCode(entry) {
	const hasInterval = entry.interval !== undefined
	if (hasInterval === (entry.cron !== undefined)) {
		return hasInterval
			? 'buildiq.schedule.error.cadence-both'
			: 'buildiq.schedule.error.cadence-required'
	}
	return hasInterval
		? intervalErrorCode(entry.interval)
		: cronErrorCode(entry.cron)
}

/**
 * The error code of an interval that is not a positive integer, else null.
 *
 * @param {unknown} interval - the entry's interval.
 * @return {string|null}
 */
function intervalErrorCode(interval) {
	return Number.isInteger(interval) && interval > 0
		? null
		: 'buildiq.schedule.error.interval-invalid'
}

/**
 * The error code of a malformed cron expression, else null.
 *
 * @param {unknown} cron - the entry's cron expression.
 * @return {string|null}
 */
function cronErrorCode(cron) {
	return isValidCron(cron) ? null : 'buildiq.schedule.error.cron-invalid'
}

/**
 * cadence: exactly one of interval | cron.
 *
 * @param {object} entry - the schedule entry.
 * @param {string} pointer - the entry's JSON-Pointer.
 * @return {string[]}
 */
function cadenceErrors(entry, pointer) {
	const code = cadenceErrorCode(entry)
	return code === null ? [] : [`${pointer}: ${code}`]
}

/**
 * action: allow-listed, with the arguments that action needs.
 *
 * @param {object} entry - the schedule entry.
 * @param {string} pointer - the entry's JSON-Pointer.
 * @return {string[]}
 */
function actionErrors(entry, pointer) {
	if (!SCHEDULE_ACTIONS.includes(entry.action)) {
		return [`${pointer}: buildiq.schedule.error.action-unsupported`]
	}
	if (
		entry.action === 'openconnector:synchronization'
		&& !hasSynchronizationId(entry.arguments)
	) {
		return [`${pointer}: buildiq.schedule.error.synchronization-required`]
	}
	return []
}

/**
 * Whether the arguments name a synchronization (a non-blank string id).
 *
 * @param {unknown} args - the entry's arguments.
 * @return {boolean}
 */
function hasSynchronizationId(args) {
	const syncId = args && args.synchronizationId
	return typeof syncId === 'string' && syncId.trim() !== ''
}

/**
 * enabled: an optional boolean.
 *
 * @param {object} entry - the schedule entry.
 * @param {string} pointer - the entry's JSON-Pointer.
 * @return {string[]}
 */
function enabledErrors(entry, pointer) {
	if (entry.enabled !== undefined && typeof entry.enabled !== 'boolean') {
		return [`${pointer}: buildiq.schedule.error.enabled-invalid`]
	}
	return []
}

/** The per-entry checks, in the order their errors are reported. */
const SCHEDULE_CHECKS = Object.freeze([
	keyErrors,
	idErrors,
	cadenceErrors,
	actionErrors,
	enabledErrors,
])

/**
 * Validate one schedule entry (no cross-entry uniqueness — that is the
 * array-level check). Used both by the pipeline validator below and by the
 * dialog's live save-gating.
 *
 * @param {object} entry - the schedule entry.
 * @param {number|string} [idx] - index for the JSON-Pointer prefix.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */
export function validateScheduleEntry(entry, idx = 0) {
	const pointer = `/schedules/${idx}`
	if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
		return [`${pointer}: buildiq.schedule.error.invalid-shape`]
	}
	return SCHEDULE_CHECKS.flatMap((check) => check(entry, pointer))
}

/**
 * Validate the top-level `schedules[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */
export function validateSchedules(manifest) {
	const errors = []
	const schedules = manifest && manifest.schedules
	if (schedules === undefined) {
		return errors
	}
	if (!Array.isArray(schedules)) {
		errors.push('/schedules: buildiq.schedule.error.not-array')
		return errors
	}

	const seenIds = new Map()
	schedules.forEach((entry, idx) => {
		errors.push(...validateScheduleEntry(entry, idx))
		// cross-entry uniqueness (only when the id is a usable string)
		if (entry && typeof entry.id === 'string' && entry.id.trim() !== '') {
			if (seenIds.has(entry.id)) {
				errors.push(`/schedules/${idx}: buildiq.schedule.error.duplicate-id`)
			}
			seenIds.set(entry.id, idx)
		}
	})

	return errors
}
