// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestSchemas: whether a manifest knows a schema slug, shared by the
 * document and workflow attachment validators.
 *
 * A schema is known when the manifest embeds it (a `schemas` list matched by
 * slug, id or title, or a `schemas` map keyed by slug) or a page binds it. A
 * manifest that embeds no schema set and binds none references external
 * schemas only, so nothing can be disproved and the slug is allowed.
 *
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-001
 */

/**
 * The manifest's embedded schema set, if any.
 *
 * @param {object} manifest - the manifest.
 * @return {unknown} The `schemas` value.
 */
function schemasOf(manifest) {
	return manifest && manifest.schemas
}

/**
 * The manifest's pages, or none.
 *
 * @param {object} manifest - the manifest.
 * @return {Array} The pages.
 */
function pagesOf(manifest) {
	return (manifest && manifest.pages) || []
}

/**
 * The name a schema list entry is matched by: its slug, else its id, else its title.
 *
 * @param {object} schema - a schema list entry.
 * @return {unknown} The name.
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-001
 */
export function schemaName(schema) {
	return schema.slug || schema.id || schema.title
}

/**
 * Whether the embedded schema set holds the slug.
 *
 * @param {unknown} schemas - the manifest's `schemas` value.
 * @param {string} schemaSlug - the schema slug.
 * @return {boolean}
 */
function embedsSchema(schemas, schemaSlug) {
	if (Array.isArray(schemas)) {
		return schemas.some((s) => schemaName(s) === schemaSlug)
	}
	return (
		!!schemas
		&& typeof schemas === 'object'
		&& Object.hasOwn(schemas, schemaSlug)
	)
}

/**
 * The schema a page's config binds, if any.
 *
 * @param {unknown} page - a page entry.
 * @return {unknown} The bound schema.
 */
function boundSchema(page) {
	return page && page.config && page.config.schema
}

/**
 * Whether a page's config binds the schema slug.
 *
 * @param {unknown} page - a page entry.
 * @param {string} schemaSlug - the schema slug.
 * @return {boolean}
 */
function pageBinds(page, schemaSlug) {
	return !!page && !!page.config && page.config.schema === schemaSlug
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
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-001
 */
export function schemaKnown(manifest, schemaSlug) {
	const schemas = schemasOf(manifest)
	if (embedsSchema(schemas, schemaSlug)) {
		return true
	}
	const pages = pagesOf(manifest)
	if (pages.some((p) => pageBinds(p, schemaSlug))) {
		return true
	}
	return !schemas && !pages.some((p) => boundSchema(p))
}
