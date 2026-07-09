// SPDX-License-Identifier: EUPL-1.2
/**
 * schedules — app-side validation of the manifest top-level `schedules[]`
 * array (apphost-scheduling authoring, REQ-OBSA-006). The `schedules[]`
 * JSON-schema definition ships from nextcloud-vue (#132); the canonical
 * `validateManifest` treats it as an additive top-level key
 * (unknown-but-tolerated), and this module supplies the strict shape +
 * cross-reference checks openbuild needs, surfaced through the
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
 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */

/** The allow-list of supported schedule actions in v1. */
export const SCHEDULE_ACTIONS = Object.freeze(['openconnector:synchronization'])

/** The only keys a schedule entry may carry. */
const ALLOWED_KEYS = Object.freeze(['id', 'enabled', 'interval', 'cron', 'action', 'arguments'])

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
 * Validate one schedule entry (no cross-entry uniqueness — that is the
 * array-level check). Used both by the pipeline validator below and by the
 * dialog's live save-gating.
 *
 * @param {object} entry - the schedule entry.
 * @param {number|string} [idx] - index for the JSON-Pointer prefix.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */
export function validateScheduleEntry(entry, idx = 0) {
	const errors = []
	const at = (code) => `/schedules/${idx}: ${code}`
	if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
		errors.push(at('openbuild.schedule.error.invalid-shape'))
		return errors
	}
	for (const key of Object.keys(entry)) {
		if (!ALLOWED_KEYS.includes(key)) {
			errors.push(`/schedules/${idx}/${key}: openbuild.schedule.error.unknown-key`)
		}
	}
	// id
	if (typeof entry.id !== 'string' || entry.id.trim() === '') {
		errors.push(at('openbuild.schedule.error.id-required'))
	} else if (!SLUG_RE.test(entry.id)) {
		errors.push(at('openbuild.schedule.error.id-not-slug'))
	}
	// cadence: exactly one of interval | cron
	const hasInterval = entry.interval !== undefined
	const hasCron = entry.cron !== undefined
	if (hasInterval && hasCron) {
		errors.push(at('openbuild.schedule.error.cadence-both'))
	} else if (!hasInterval && !hasCron) {
		errors.push(at('openbuild.schedule.error.cadence-required'))
	} else if (hasInterval) {
		if (typeof entry.interval !== 'number' || !Number.isInteger(entry.interval) || entry.interval <= 0) {
			errors.push(at('openbuild.schedule.error.interval-invalid'))
		}
	} else if (!isValidCron(entry.cron)) {
		errors.push(at('openbuild.schedule.error.cron-invalid'))
	}
	// action
	if (!SCHEDULE_ACTIONS.includes(entry.action)) {
		errors.push(at('openbuild.schedule.error.action-unsupported'))
	} else if (entry.action === 'openconnector:synchronization') {
		const args = entry.arguments
		const syncId = args && args.synchronizationId
		if (typeof syncId !== 'string' || syncId.trim() === '') {
			errors.push(at('openbuild.schedule.error.synchronization-required'))
		}
	}
	// enabled (optional boolean)
	if (entry.enabled !== undefined && typeof entry.enabled !== 'boolean') {
		errors.push(at('openbuild.schedule.error.enabled-invalid'))
	}
	return errors
}

/**
 * Validate the top-level `schedules[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */
export function validateSchedules(manifest) {
	const errors = []
	const schedules = manifest && manifest.schedules
	if (schedules === undefined) {
		return errors
	}
	if (!Array.isArray(schedules)) {
		errors.push('/schedules: openbuild.schedule.error.not-array')
		return errors
	}

	const seenIds = new Map()
	schedules.forEach((entry, idx) => {
		errors.push(...validateScheduleEntry(entry, idx))
		// cross-entry uniqueness (only when the id is a usable string)
		if (entry && typeof entry.id === 'string' && entry.id.trim() !== '') {
			if (seenIds.has(entry.id)) {
				errors.push(`/schedules/${idx}: openbuild.schedule.error.duplicate-id`)
			}
			seenIds.set(entry.id, idx)
		}
	})

	return errors
}
