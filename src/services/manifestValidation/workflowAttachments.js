// SPDX-License-Identifier: EUPL-1.2
/**
 * workflowAttachments — app-side validation of the manifest v2
 * `runtime.workflows[]` array (Procest workflow attachments, REQ-PWA-001).
 * The canonical `app-manifest-v2.schema.json` carries `runtime` with
 * `additionalProperties: true`, so the library validator accepts the
 * `workflows` branch; this module supplies the strict shape + cross-reference
 * checks buildiq needs, surfaced through the `useManifestValidator`
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
function workflowError(ctx, code) {
	return `${ctx.pointer}: ${code}`
}

/**
 * Unknown keys on the entry.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function keyErrors(wf, ctx) {
	return Object.keys(wf)
		.filter((key) => !ALLOWED_KEYS.includes(key))
		.map((key) => `${ctx.pointer}/${key}: buildiq.workflow.error.unknown-key`)
}

/**
 * id: required, at most one entry per id. Records the id as seen.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string, idx: number, seenIds: Map}} ctx - the entry context.
 * @return {string[]}
 */
function idErrors(wf, ctx) {
	if (!isFilledString(wf.id)) {
		return [workflowError(ctx, 'buildiq.workflow.error.id-required')]
	}
	const duplicate = ctx.seenIds.has(wf.id)
	ctx.seenIds.set(wf.id, ctx.idx)
	return duplicate
		? [workflowError(ctx, 'buildiq.workflow.error.duplicate-id')]
		: []
}

/**
 * schema: required and known to the manifest. Counts the schema for the
 * at-most-one-per-schema pass.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string, manifest: object, schemaCounts: Map}} ctx - the
 *   entry context.
 * @return {string[]}
 */
function schemaErrors(wf, ctx) {
	if (!isFilledString(wf.schema)) {
		return [workflowError(ctx, 'buildiq.workflow.error.schema-required')]
	}
	ctx.schemaCounts.set(wf.schema, (ctx.schemaCounts.get(wf.schema) || 0) + 1)
	if (!schemaKnown(ctx.manifest, wf.schema)) {
		return [workflowError(ctx, 'buildiq.workflow.error.schema-unknown')]
	}
	return []
}

/**
 * caseTypeUuid, caseTypeName and trigger.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function caseTypeErrors(wf, ctx) {
	const errors = []
	if (typeof wf.caseTypeUuid !== 'string' || !UUID_RE.test(wf.caseTypeUuid)) {
		errors.push(
			workflowError(ctx, 'buildiq.workflow.error.casetype-uuid-invalid'),
		)
	}
	if (!isFilledString(wf.caseTypeName)) {
		errors.push(
			workflowError(ctx, 'buildiq.workflow.error.casetype-name-required'),
		)
	}
	if (!WORKFLOW_TRIGGERS.includes(wf.trigger)) {
		errors.push(workflowError(ctx, 'buildiq.workflow.error.trigger-unsupported'))
	}
	return errors
}

/**
 * linkProperty: required, and a string property of the schema when the
 * manifest embeds that schema's properties.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string, manifest: object}} ctx - the entry context.
 * @return {string[]}
 */
function linkPropertyErrors(wf, ctx) {
	if (!isFilledString(wf.linkProperty)) {
		return [workflowError(ctx, 'buildiq.workflow.error.link-property-required')]
	}
	const accessor =
		typeof wf.schema === 'string'
			? schemaPropsAccessor(ctx.manifest, wf.schema)
			: null
	if (!accessor) {
		return []
	}
	if (!accessor.has(wf.linkProperty)) {
		return [workflowError(ctx, 'buildiq.workflow.error.link-property-missing')]
	}
	if (!accessor.isString(wf.linkProperty)) {
		return [
			workflowError(ctx, 'buildiq.workflow.error.link-property-not-string'),
		]
	}
	return []
}

/**
 * descriptionTemplate: optional string.
 *
 * @param {object} wf - the workflow entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function descriptionTemplateErrors(wf, ctx) {
	if (
		wf.descriptionTemplate !== undefined
		&& typeof wf.descriptionTemplate !== 'string'
	) {
		return [
			workflowError(
				ctx,
				'buildiq.workflow.error.description-template-invalid',
			),
		]
	}
	return []
}

/** The per-entry checks, in the order their errors are reported. */
const WORKFLOW_CHECKS = Object.freeze([
	keyErrors,
	idErrors,
	schemaErrors,
	caseTypeErrors,
	linkPropertyErrors,
	descriptionTemplateErrors,
])

/**
 * Every error of one workflow entry.
 *
 * @param {unknown} wf - the workflow entry.
 * @param {object} ctx - the entry context: pointer, idx, manifest, seenIds
 *   and schemaCounts.
 * @return {string[]}
 */
function workflowErrors(wf, ctx) {
	if (!isPlainObject(wf)) {
		return [workflowError(ctx, 'buildiq.workflow.error.invalid-shape')]
	}
	return WORKFLOW_CHECKS.flatMap((check) => check(wf, ctx))
}

/**
 * Mark every entry that shares a schema with another (duplicate-schema).
 *
 * @param {Array} workflows - the workflows array.
 * @param {Map<string, number>} schemaCounts - entries per schema.
 * @return {string[]}
 */
function duplicateSchemaErrors(workflows, schemaCounts) {
	const errors = []
	workflows.forEach((wf, idx) => {
		if (
			wf
			&& typeof wf.schema === 'string'
			&& (schemaCounts.get(wf.schema) || 0) > 1
		) {
			errors.push(
				`/runtime/workflows/${idx}: buildiq.workflow.error.duplicate-schema-attachment`,
			)
		}
	})
	return errors
}

/**
 * Validate the `runtime.workflows[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-001
 */
export function validateWorkflowAttachments(manifest) {
	const workflows = manifest && manifest.runtime && manifest.runtime.workflows
	if (workflows === undefined) {
		return []
	}
	if (!Array.isArray(workflows)) {
		return ['/runtime/workflows: buildiq.workflow.error.not-array']
	}
	const seenIds = new Map()
	const schemaCounts = new Map()
	const errors = workflows.flatMap((wf, idx) =>
		workflowErrors(wf, {
			pointer: `/runtime/workflows/${idx}`,
			idx,
			manifest,
			seenIds,
			schemaCounts,
		}),
	)
	return [...errors, ...duplicateSchemaErrors(workflows, schemaCounts)]
}
