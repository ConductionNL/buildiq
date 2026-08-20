// SPDX-License-Identifier: EUPL-1.2
/**
 * workflowAttachments — app-side validation of the manifest v2
 * `runtime.workflows[]` array (Procest workflow attachments, REQ-PWA-001).
 * The canonical `app-manifest-v2.schema.json` carries `runtime` with
 * `additionalProperties: true`, so the library validator accepts the
 * `workflows` branch; this module supplies the strict shape + cross-reference
 * checks openbuild needs, surfaced through the `useManifestValidator`
 * pipeline.
 *
 * Returned errors are `<pointer>: <i18n-error-code>` strings so the existing
 * path-prefix → inline-mark mechanism (REQ-OBPD-011) lights up the offending
 * editor entry.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-001
 */

/** Closed enum of supported triggers for v1. */
export const WORKFLOW_TRIGGERS = Object.freeze(['on-create'])

/** The only keys a workflow entry may carry. */
const ALLOWED_KEYS = Object.freeze([
	'id',
	'schema',
	'caseTypeUuid',
	'caseTypeName',
	'trigger',
	'linkProperty',
	'descriptionTemplate',
])

/** Loose UUID check (8-4-4-4-12 hex). */
const UUID_RE =
	/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/

/**
 * Resolve the declared property names for a schema in the manifest. Looks at
 * the manifest's `schemas` map/array (each carrying a JSON-schema `properties`
 * object) when present; returns null when the schema set is not embedded so
 * the caller can skip the cross-reference check rather than false-reject.
 *
 * @param {object} manifest - the manifest.
 * @param {string} schemaSlug - the schema slug.
 * @return {?object} - `{ has(name), isString(name) }` accessor, or null.
 */
function schemaPropsAccessor(manifest, schemaSlug) {
	const schemas = manifest && manifest.schemas
	let entry = null
	if (Array.isArray(schemas)) {
		entry = schemas.find((s) => (s.slug || s.id || s.title) === schemaSlug)
	} else if (schemas && typeof schemas === 'object') {
		entry = schemas[schemaSlug]
	}
	if (!entry) {
		return null
	}
	const props =
		entry.properties || (entry.schema && entry.schema.properties) || null
	if (!props || typeof props !== 'object') {
		return null
	}
	return {
		has: (name) => Object.hasOwn(props, name),
		isString: (name) => {
			const p = props[name]
			if (!p) {
				return false
			}
			const type = p.type || (p.format ? 'string' : undefined)
			return type === 'string' || p.format === 'uri' || p.format === 'url'
		},
	}
}

/**
 * Whether a schema slug is known to the manifest (in `schemas` or referenced
 * by any page's register/schema binding). Returns true when the schema set is
 * not embedded, to avoid false rejects in manifests that reference external
 * schemas only.
 *
 * @param {object} manifest - the manifest.
 * @param {string} schemaSlug - the schema slug.
 * @return {boolean}
 */
function schemaKnown(manifest, schemaSlug) {
	const schemas = manifest && manifest.schemas
	if (Array.isArray(schemas)) {
		if (schemas.some((s) => (s.slug || s.id || s.title) === schemaSlug)) {
			return true
		}
	} else if (schemas && typeof schemas === 'object') {
		if (Object.hasOwn(schemas, schemaSlug)) {
			return true
		}
	}
	// Page bindings reference schemas by slug.
	const pages = (manifest && manifest.pages) || []
	if (pages.some((p) => p && p.config && p.config.schema === schemaSlug)) {
		return true
	}
	// No embedded schema set and no page reference — cannot disprove; allow.
	return !schemas && !pages.some((p) => p && p.config && p.config.schema)
}

/**
 * Validate the `runtime.workflows[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-001
 */
export function validateWorkflowAttachments(manifest) {
	const errors = []
	const workflows = manifest && manifest.runtime && manifest.runtime.workflows
	if (workflows === undefined) {
		return errors
	}
	if (!Array.isArray(workflows)) {
		errors.push('/runtime/workflows: openbuild.workflow.error.not-array')
		return errors
	}

	const seenIds = new Map()
	const schemaCounts = new Map()

	workflows.forEach((wf, idx) => {
		const at = (code) => `/runtime/workflows/${idx}: ${code}`
		if (!wf || typeof wf !== 'object' || Array.isArray(wf)) {
			errors.push(at('openbuild.workflow.error.invalid-shape'))
			return
		}
		for (const key of Object.keys(wf)) {
			if (!ALLOWED_KEYS.includes(key)) {
				errors.push(
					`/runtime/workflows/${idx}/${key}: openbuild.workflow.error.unknown-key`,
				)
			}
		}
		// id
		if (typeof wf.id !== 'string' || wf.id.trim() === '') {
			errors.push(at('openbuild.workflow.error.id-required'))
		} else {
			if (seenIds.has(wf.id)) {
				errors.push(at('openbuild.workflow.error.duplicate-id'))
			}
			seenIds.set(wf.id, idx)
		}
		// schema + at-most-one-per-schema
		if (typeof wf.schema !== 'string' || wf.schema.trim() === '') {
			errors.push(at('openbuild.workflow.error.schema-required'))
		} else {
			schemaCounts.set(wf.schema, (schemaCounts.get(wf.schema) || 0) + 1)
			if (!schemaKnown(manifest, wf.schema)) {
				errors.push(at('openbuild.workflow.error.schema-unknown'))
			}
		}
		// caseTypeUuid
		if (typeof wf.caseTypeUuid !== 'string' || !UUID_RE.test(wf.caseTypeUuid)) {
			errors.push(at('openbuild.workflow.error.casetype-uuid-invalid'))
		}
		// caseTypeName
		if (typeof wf.caseTypeName !== 'string' || wf.caseTypeName.trim() === '') {
			errors.push(at('openbuild.workflow.error.casetype-name-required'))
		}
		// trigger
		if (!WORKFLOW_TRIGGERS.includes(wf.trigger)) {
			errors.push(at('openbuild.workflow.error.trigger-unsupported'))
		}
		// linkProperty + cross-reference
		if (typeof wf.linkProperty !== 'string' || wf.linkProperty.trim() === '') {
			errors.push(at('openbuild.workflow.error.link-property-required'))
		} else if (typeof wf.schema === 'string') {
			const accessor = schemaPropsAccessor(manifest, wf.schema)
			if (accessor) {
				if (!accessor.has(wf.linkProperty)) {
					errors.push(at('openbuild.workflow.error.link-property-missing'))
				} else if (!accessor.isString(wf.linkProperty)) {
					errors.push(
						at('openbuild.workflow.error.link-property-not-string'),
					)
				}
			}
		}
		// descriptionTemplate
		if (
			wf.descriptionTemplate !== undefined
			&& typeof wf.descriptionTemplate !== 'string'
		) {
			errors.push(at('openbuild.workflow.error.description-template-invalid'))
		}
	})

	// Mark every entry that shares a schema with another (duplicate-schema).
	workflows.forEach((wf, idx) => {
		if (
			wf
			&& typeof wf.schema === 'string'
			&& (schemaCounts.get(wf.schema) || 0) > 1
		) {
			errors.push(
				`/runtime/workflows/${idx}: openbuild.workflow.error.duplicate-schema-attachment`,
			)
		}
	})

	return errors
}
