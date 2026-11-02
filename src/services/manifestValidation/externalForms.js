// SPDX-License-Identifier: EUPL-1.2
/**
 * externalForms — app-side validation of the manifest v2
 * `runtime.externalForms[]` array (external-form-provisioning, REQ-EFP-001).
 * The canonical `app-manifest-v2.schema.json` carries `runtime` with
 * `additionalProperties: true`, so the library validator accepts the
 * `externalForms` branch; this module supplies the strict shape checks
 * buildiq needs, surfaced through the `useManifestValidator` pipeline (the
 * same mechanism the theme/workflow/connector/document/schedule siblings
 * use).
 *
 * Returned errors are `<pointer>: <i18n-error-code>` strings so the existing
 * path-prefix → inline-mark mechanism (REQ-OBPD-011) lights up the offending
 * editor entry.
 *
 * An app with no `externalForms` entries SHALL serialize byte-identical
 * manifests to before this feature — this module never touches the manifest,
 * it only reads it, and returns `[]` when the key is absent.
 *
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
 */

/** Closed enum of supported `status` values. */
export const EXTERNAL_FORM_STATUSES = Object.freeze(['enabled', 'disabled'])

/** The only keys an `externalForms` entry may carry. */
const ALLOWED_KEYS = Object.freeze([
	'id',
	'pageId',
	'register',
	'schema',
	'status',
	'publicRead',
	'organisationScope',
	'portalPage',
	'trackLinkAction',
])

/** The only keys a `portalPage` object may carry (when not null). */
const ALLOWED_PORTAL_PAGE_KEYS = Object.freeze(['objectId', 'portalPath'])

/** The only keys a `trackLinkAction` object may carry. */
const ALLOWED_TRACK_LINK_KEYS = Object.freeze(['enabled'])

/**
 * Whether a value is a non-null, non-array object.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a value is a string with more than whitespace in it.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 */
function isFilledString(value) {
	return typeof value === 'string' && value.trim() !== ''
}

/**
 * One `<entry pointer>: <code>` error string.
 *
 * @param {{pointer: string}} ctx - the entry context.
 * @param {string} code - the i18n error code.
 * @return {string}
 */
function entryError(ctx, code) {
	return `${ctx.pointer}: ${code}`
}

/**
 * Unknown-key errors for every key of `object` outside `scope.allowed`.
 *
 * @param {object} object - the object whose keys are checked.
 * @param {{allowed: string[], pointer: string}} scope - the allowed keys and
 *   the pointer the key is appended to.
 * @return {string[]}
 */
function unknownKeyErrors(object, scope) {
	return Object.keys(object)
		.filter((key) => !scope.allowed.includes(key))
		.map(
			(key) =>
				`${scope.pointer}/${key}: buildiq.externalForm.error.unknown-key`,
		)
}

/**
 * Unknown keys on the entry.
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function entryKeyErrors(entry, ctx) {
	return unknownKeyErrors(entry, { allowed: ALLOWED_KEYS, pointer: ctx.pointer })
}

/**
 * id: required, at most one entry per id. Records the id as seen.
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string, idx: number, seenIds: Map}} ctx - the entry context.
 * @return {string[]}
 */
function idErrors(entry, ctx) {
	if (!isFilledString(entry.id)) {
		return [entryError(ctx, 'buildiq.externalForm.error.id-required')]
	}
	const duplicate = ctx.seenIds.has(entry.id)
	ctx.seenIds.set(entry.id, ctx.idx)
	return duplicate
		? [entryError(ctx, 'buildiq.externalForm.error.duplicate-id')]
		: []
}

/** Required string keys with the error each reports when missing, in order. */
const REQUIRED_STRINGS = Object.freeze([
	['pageId', 'buildiq.externalForm.error.page-id-required'],
	['register', 'buildiq.externalForm.error.register-required'],
	['schema', 'buildiq.externalForm.error.schema-required'],
])

/**
 * pageId, register and schema: required strings.
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function requiredStringErrors(entry, ctx) {
	return REQUIRED_STRINGS.filter(([key]) => !isFilledString(entry[key])).map(
		([, code]) => entryError(ctx, code),
	)
}

/**
 * status, publicRead and organisationScope: a closed enum, a boolean, and a
 * string id or null (absent tolerated for the last two).
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function scalarErrors(entry, ctx) {
	const errors = []
	if (!EXTERNAL_FORM_STATUSES.includes(entry.status)) {
		errors.push(entryError(ctx, 'buildiq.externalForm.error.status-invalid'))
	}
	if (entry.publicRead !== undefined && typeof entry.publicRead !== 'boolean') {
		errors.push(
			entryError(ctx, 'buildiq.externalForm.error.public-read-not-boolean'),
		)
	}
	const scope = entry.organisationScope
	if (scope !== undefined && scope !== null && typeof scope !== 'string') {
		errors.push(
			entryError(ctx, 'buildiq.externalForm.error.organisation-scope-invalid'),
		)
	}
	return errors
}

/**
 * portalPage: `{objectId, portalPath}` or null (absent tolerated as null).
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function portalPageErrors(entry, ctx) {
	const pp = entry.portalPage
	if (pp === undefined || pp === null) {
		return []
	}
	if (!isPlainObject(pp)) {
		return [entryError(ctx, 'buildiq.externalForm.error.portal-page-invalid')]
	}
	const errors = unknownKeyErrors(pp, {
		allowed: ALLOWED_PORTAL_PAGE_KEYS,
		pointer: `${ctx.pointer}/portalPage`,
	})
	if (!isFilledString(pp.objectId)) {
		errors.push(
			entryError(
				ctx,
				'buildiq.externalForm.error.portal-page-object-id-required',
			),
		)
	}
	if (pp.portalPath !== undefined && typeof pp.portalPath !== 'string') {
		errors.push(
			entryError(ctx, 'buildiq.externalForm.error.portal-page-path-invalid'),
		)
	}
	return errors
}

/**
 * trackLinkAction: `{enabled: boolean}` (absent tolerated as disabled).
 *
 * @param {object} entry - the externalForms entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function trackLinkActionErrors(entry, ctx) {
	const tla = entry.trackLinkAction
	if (tla === undefined) {
		return []
	}
	if (!isPlainObject(tla)) {
		return [
			entryError(ctx, 'buildiq.externalForm.error.track-link-action-invalid'),
		]
	}
	const errors = unknownKeyErrors(tla, {
		allowed: ALLOWED_TRACK_LINK_KEYS,
		pointer: `${ctx.pointer}/trackLinkAction`,
	})
	if (typeof tla.enabled !== 'boolean') {
		errors.push(
			entryError(
				ctx,
				'buildiq.externalForm.error.track-link-action-enabled-not-boolean',
			),
		)
	}
	return errors
}

/** The per-entry checks, in the order their errors are reported. */
const ENTRY_CHECKS = Object.freeze([
	entryKeyErrors,
	idErrors,
	requiredStringErrors,
	scalarErrors,
	portalPageErrors,
	trackLinkActionErrors,
])

/**
 * Every error of one externalForms entry.
 *
 * @param {unknown} entry - the externalForms entry.
 * @param {{pointer: string, idx: number, seenIds: Map}} ctx - the entry context.
 * @return {string[]}
 */
function entryErrors(entry, ctx) {
	if (!isPlainObject(entry)) {
		return [entryError(ctx, 'buildiq.externalForm.error.invalid-shape')]
	}
	return ENTRY_CHECKS.flatMap((check) => check(entry, ctx))
}

/**
 * Validate the `runtime.externalForms[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
 */
export function validateExternalForms(manifest) {
	const externalForms =
		manifest && manifest.runtime && manifest.runtime.externalForms
	if (externalForms === undefined) {
		return []
	}
	if (!Array.isArray(externalForms)) {
		return ['/runtime/externalForms: buildiq.externalForm.error.not-array']
	}
	const seenIds = new Map()
	return externalForms.flatMap((entry, idx) =>
		entryErrors(entry, {
			pointer: `/runtime/externalForms/${idx}`,
			idx,
			seenIds,
		}),
	)
}
