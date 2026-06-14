// SPDX-License-Identifier: EUPL-1.2
/**
 * templateCapture — pure capture + de-namespace logic for the
 * "save as template" flow (REQ-SAT-002, REQ-SAT-004, REQ-SAT-006).
 *
 * Closes the marketplace loop opened by `openbuild-template-catalogue`:
 * the clone path (REQ-OBTC-004/005) reads any `ApplicationTemplate` by
 * slug and PREFIXES every companion schema slug with the new app's slug,
 * rewriting `schema` / `relatedSchema` references in the manifest. This
 * module is the exact inverse — it STRIPS the source app's `<appSlug>-`
 * prefix and rewrites the same reference sites — so that
 * save-as-template followed by clone-from-template composes to a clean
 * rename without prefix stacking.
 *
 * Everything here is pure (no I/O): the OR write happens in the dialog
 * via `useObjectStore`. That keeps the round-trip property unit-testable
 * and honours REQ-SAT-006 (zero new PHP — template create/update/delete
 * are plain OR object CRUD).
 *
 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
 */

/**
 * The four `category` enum values declared on the `ApplicationTemplate`
 * schema (REQ-OBTC-001). Re-exported so the dialog's category picker and
 * the metadata-edit dialog share one source of truth.
 *
 * @type {string[]}
 */
export const TEMPLATE_CATEGORIES = [
	'government-services',
	'internal-operations',
	'citizen-engagement',
	'field-work',
]

/**
 * Deep-clone a JSON-serialisable value. Used so a captured manifest /
 * schema can never alias the live app objects (mutating the template
 * record must not mutate the running app).
 *
 * @param {*} value - any JSON-serialisable value.
 * @return {*} a structural deep copy.
 */
export function deepClone(value) {
	if (value === null || typeof value !== 'object') {
		return value
	}
	return JSON.parse(JSON.stringify(value))
}

/**
 * Derive a kebab-case slug from a free-text title. Mirrors the clone
 * dialog's slug pattern (`^[a-z0-9]+(-[a-z0-9]+)*$`, max 32 chars).
 *
 * @param {string} title - human-readable title.
 * @return {string} a kebab-case slug (possibly empty for empty input).
 */
export function suggestSlug(title) {
	return String(title || '')
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 32)
		.replace(/-+$/g, '')
}

/**
 * Strip a leading `<appSlug>-` prefix from a schema slug. Returns the
 * de-namespaced slug and whether the prefix was actually present.
 *
 * @param {string} schemaSlug - the companion schema slug.
 * @param {string} appSlug - the source application slug.
 * @return {{ slug: string, shared: boolean }} de-namespaced slug; `shared`
 *   is `true` when the slug did NOT carry the prefix (a hand-attached /
 *   genuinely shared schema captured unchanged).
 */
export function deNamespaceSlug(schemaSlug, appSlug) {
	const prefix = `${appSlug}-`
	if (appSlug && String(schemaSlug).startsWith(prefix)) {
		return { slug: String(schemaSlug).slice(prefix.length), shared: false }
	}
	return { slug: String(schemaSlug), shared: true }
}

/**
 * Recursively rewrite `schema` / `relatedSchema` string references in a
 * manifest node using a source-slug → canonical-slug map. Exact inverse
 * of the clone controller's `rewriteSchemaRefs` (which targets the same
 * two keys recursively). Returns a NEW node — never mutates the input.
 *
 * @param {*} node - the manifest node (object / array / scalar).
 * @param {Object<string,string>} map - source-slug → de-namespaced-slug.
 * @return {*} the rewritten node.
 */
export function rewriteSchemaRefs(node, map) {
	if (Array.isArray(node)) {
		return node.map((item) => rewriteSchemaRefs(item, map))
	}
	if (node === null || typeof node !== 'object') {
		return node
	}
	const out = {}
	for (const [key, value] of Object.entries(node)) {
		if ((key === 'schema' || key === 'relatedSchema')
			&& typeof value === 'string'
			&& Object.prototype.hasOwnProperty.call(map, value)) {
			out[key] = map[value]
			continue
		}
		out[key] = rewriteSchemaRefs(value, map)
	}
	return out
}

/**
 * Error thrown when two captured companion schemas would de-namespace to
 * the same canonical slug — an ambiguous capture that would silently
 * break the round-trip, so it fails loudly (REQ-SAT-002).
 */
export class SlugCollisionError extends Error {

	/**
	 * @param {string} canonicalSlug - the colliding canonical slug.
	 * @param {string[]} sourceSlugs - the two source slugs that collided.
	 */
	constructor(canonicalSlug, sourceSlugs) {

		super(`openbuild.templates.saveAs.error.slug-collision: ${sourceSlugs.join(', ')} → ${canonicalSlug}`)
		this.name = 'SlugCollisionError'
		this.code = 'slug-collision'
		this.canonicalSlug = canonicalSlug
		this.sourceSlugs = sourceSlugs

	}

}

/**
 * Capture a virtual app + its schemas into an `ApplicationTemplate`
 * record shape — de-namespaced so the existing clone flow round-trips
 * to a clean rename (REQ-SAT-002). Pure function; performs NO I/O and
 * NEVER includes object rows (a template is a definition, not a
 * dataset — REQ-SAT-001).
 *
 * @param {object} application - the source Application record. Must carry
 *   `slug`; `version` is read for the template's recorded version.
 * @param {Array<object>} schemas - the app's companion schema definitions
 *   (JSON-schema blobs, each with a `slug`). Object rows are not part of
 *   this input and are never read.
 * @param {object} manifest - the app's current manifest blob.
 * @param {object} metadata - { title, slug, description, useCase,
 *   category, sourceUrl }.
 * @return {{ record: object, summary: { companionSchemas: Array<{ sourceSlug: string, slug: string, shared: boolean }> } }}
 *   the `ApplicationTemplate` record (`isSeeded: false`) plus a capture
 *   summary the dialog renders.
 * @throws {SlugCollisionError} when two schemas de-namespace to the same slug.
 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
 */
export function captureTemplate(application, schemas, manifest, metadata) {
	const appSlug = (application && application.slug) || ''
	const sourceSchemas = Array.isArray(schemas) ? schemas : []

	// 1. Build the source-slug → canonical-slug rewrite map, detecting collisions.
	const rewriteMap = {}
	const canonicalToSource = {}
	const summaryEntries = []
	for (const schema of sourceSchemas) {
		const sourceSlug = String((schema && schema.slug) || '')
		if (!sourceSlug) {
			continue
		}
		const { slug: canonical, shared } = deNamespaceSlug(sourceSlug, appSlug)
		if (Object.prototype.hasOwnProperty.call(canonicalToSource, canonical)
			&& canonicalToSource[canonical] !== sourceSlug) {
			throw new SlugCollisionError(canonical, [canonicalToSource[canonical], sourceSlug])
		}
		canonicalToSource[canonical] = sourceSlug
		rewriteMap[sourceSlug] = canonical
		summaryEntries.push({ sourceSlug, slug: canonical, shared })
	}

	// 2. Deep-copy + de-namespace the companion schemas (definition only — no rows).
	const companionSchemas = sourceSchemas
		.filter((schema) => schema && schema.slug)
		.map((schema) => {
			const copy = deepClone(schema)
			copy.slug = rewriteMap[String(schema.slug)]
			return copy
		})

	// 3. Deep-copy the manifest and rewrite every schema reference site.
	const capturedManifest = rewriteSchemaRefs(deepClone(manifest || {}), rewriteMap)

	const record = {
		slug: metadata.slug,
		title: metadata.title,
		description: metadata.description || '',
		useCase: metadata.useCase || '',
		category: metadata.category,
		manifest: capturedManifest,
		companionSchemas,
		isSeeded: false,
		version: (application && application.version) || '0.1.0',
	}
	if (metadata.sourceUrl) {
		record.sourceUrl = metadata.sourceUrl
	}

	return {
		record,
		summary: { companionSchemas: summaryEntries },
	}
}

/**
 * Bump a semver string's minor component, resetting patch to 0. Used by
 * update-in-place (REQ-SAT-004). Tolerates a missing / malformed version
 * by treating it as `0.0.0`.
 *
 * @param {string} version - a `MAJOR.MINOR.PATCH` semver string.
 * @return {string} the minor-bumped version.
 */
export function bumpMinor(version) {
	const parts = String(version || '0.0.0').split('.')
	const major = Number.parseInt(parts[0], 10) || 0
	const minor = Number.parseInt(parts[1], 10) || 0
	return `${major}.${minor + 1}.0`
}

/**
 * Resolve what a save against `slug` should do, given the existing
 * template records in the caller's organisation (REQ-SAT-004).
 *
 * Pure decision function — the dialog supplies the candidate list it
 * already fetched for the gallery; this never performs I/O. Writability
 * is read from OR's standard per-object rights (no openbuild-local role
 * logic — REQ-SAT-006).
 *
 * @param {string} slug - the chosen template slug.
 * @param {Array<object>} existingTemplates - templates visible to the
 *   caller (each may carry `slug`, `isSeeded`, and a writability hint).
 * @param {function(object):boolean} canWrite - predicate returning whether
 *   the caller may write a given template record (reads OR's per-object
 *   rights from the record).
 * @return {{ mode: 'create'|'update', record?: object } | { error: string }}
 *   `create` for a free slug; `update` (with the existing record) for an
 *   own org-local slug; `{ error }` of `seeded-slug` or `slug-taken`.
 */
export function resolveSaveTarget(slug, existingTemplates, canWrite) {
	const match = (Array.isArray(existingTemplates) ? existingTemplates : [])
		.find((tpl) => tpl && tpl.slug === slug)
	if (!match) {
		return { mode: 'create' }
	}
	if (match.isSeeded === true) {
		return { error: 'seeded-slug' }
	}
	if (canWrite(match)) {
		return { mode: 'update', record: match }
	}
	return { error: 'slug-taken' }
}
