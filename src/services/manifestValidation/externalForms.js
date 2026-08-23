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
 * Validate the `runtime.externalForms[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
 */
export function validateExternalForms(manifest) {
	const errors = []
	const externalForms =
		manifest && manifest.runtime && manifest.runtime.externalForms
	if (externalForms === undefined) {
		return errors
	}
	if (!Array.isArray(externalForms)) {
		errors.push('/runtime/externalForms: buildiq.externalForm.error.not-array')
		return errors
	}

	const seenIds = new Map()

	externalForms.forEach((entry, idx) => {
		const at = (code) => `/runtime/externalForms/${idx}: ${code}`
		if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
			errors.push(at('buildiq.externalForm.error.invalid-shape'))
			return
		}
		for (const key of Object.keys(entry)) {
			if (!ALLOWED_KEYS.includes(key)) {
				errors.push(
					`/runtime/externalForms/${idx}/${key}: buildiq.externalForm.error.unknown-key`,
				)
			}
		}
		// id — required, at most one entry per id.
		if (typeof entry.id !== 'string' || entry.id.trim() === '') {
			errors.push(at('buildiq.externalForm.error.id-required'))
		} else {
			if (seenIds.has(entry.id)) {
				errors.push(at('buildiq.externalForm.error.duplicate-id'))
			}
			seenIds.set(entry.id, idx)
		}
		// pageId — required.
		if (typeof entry.pageId !== 'string' || entry.pageId.trim() === '') {
			errors.push(at('buildiq.externalForm.error.page-id-required'))
		}
		// register — required.
		if (typeof entry.register !== 'string' || entry.register.trim() === '') {
			errors.push(at('buildiq.externalForm.error.register-required'))
		}
		// schema — required.
		if (typeof entry.schema !== 'string' || entry.schema.trim() === '') {
			errors.push(at('buildiq.externalForm.error.schema-required'))
		}
		// status — closed enum.
		if (!EXTERNAL_FORM_STATUSES.includes(entry.status)) {
			errors.push(at('buildiq.externalForm.error.status-invalid'))
		}
		// publicRead — boolean (default false when absent).
		if (
			entry.publicRead !== undefined
			&& typeof entry.publicRead !== 'boolean'
		) {
			errors.push(at('buildiq.externalForm.error.public-read-not-boolean'))
		}
		// organisationScope — string id or null (absent tolerated as null).
		if (
			entry.organisationScope !== undefined
			&& entry.organisationScope !== null
			&& typeof entry.organisationScope !== 'string'
		) {
			errors.push(at('buildiq.externalForm.error.organisation-scope-invalid'))
		}
		// portalPage — `{objectId, portalPath}` or null (absent tolerated as null).
		if (entry.portalPage !== undefined && entry.portalPage !== null) {
			const pp = entry.portalPage
			if (typeof pp !== 'object' || Array.isArray(pp)) {
				errors.push(at('buildiq.externalForm.error.portal-page-invalid'))
			} else {
				for (const key of Object.keys(pp)) {
					if (!ALLOWED_PORTAL_PAGE_KEYS.includes(key)) {
						errors.push(
							`/runtime/externalForms/${idx}/portalPage/${key}: buildiq.externalForm.error.unknown-key`,
						)
					}
				}
				if (typeof pp.objectId !== 'string' || pp.objectId.trim() === '') {
					errors.push(
						at(
							'buildiq.externalForm.error.portal-page-object-id-required',
						),
					)
				}
				if (
					pp.portalPath !== undefined
					&& typeof pp.portalPath !== 'string'
				) {
					errors.push(
						at('buildiq.externalForm.error.portal-page-path-invalid'),
					)
				}
			}
		}
		// trackLinkAction — `{enabled: boolean}` (absent tolerated as disabled).
		if (entry.trackLinkAction !== undefined) {
			const tla = entry.trackLinkAction
			if (!tla || typeof tla !== 'object' || Array.isArray(tla)) {
				errors.push(
					at('buildiq.externalForm.error.track-link-action-invalid'),
				)
			} else {
				for (const key of Object.keys(tla)) {
					if (!ALLOWED_TRACK_LINK_KEYS.includes(key)) {
						errors.push(
							`/runtime/externalForms/${idx}/trackLinkAction/${key}: buildiq.externalForm.error.unknown-key`,
						)
					}
				}
				if (typeof tla.enabled !== 'boolean') {
					errors.push(
						at(
							'buildiq.externalForm.error.track-link-action-enabled-not-boolean',
						),
					)
				}
			}
		}
	})

	return errors
}
