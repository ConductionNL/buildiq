// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2

/**
 * One app's API reference, cut from OpenRegister's per-register OpenAPI
 * documents down to the schemas the app's pages and widgets use.
 *
 * Pure: it fetches nothing. The caller passes the version's manifest and the
 * documents of the registers it binds, and saves or renders what comes back,
 * so what a maker reads and what an integrator downloads are the same
 * document.
 *
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-document-holds-only-what-the-app-uses-req-bqad-002
 */

/**
 * A non-empty string, or ''.
 *
 * @param {unknown} value - the value.
 * @return {string}
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * The widgets of a page config.
 *
 * @param {object} config - the page config.
 * @return {Array<object>}
 */
function widgetsOf(config) {
	return Array.isArray(config.widgets) ? config.widgets.filter((widget) => widget && typeof widget === 'object') : []
}

/**
 * Every register/schema pair the manifest's pages and widgets bind, once, in page order.
 *
 * A page binds through `config.register` + `config.schema`; a widget through
 * `content.schema`, with `content.register` or else its page's register.
 *
 * @param {object} manifest - the version's manifest.
 * @return {Array<{register: string, schema: string, pages: Array<string>}>}
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-document-holds-only-what-the-app-uses-req-bqad-002
 */
export function boundSchemas(manifest) {
	const found = new Map()
	const add = (register, schema, pageId) => {
		if (!register || !schema) {
			return
		}
		const key = `${register}/${schema}`
		if (!found.has(key)) {
			found.set(key, { register, schema, pages: [] })
		}
		const entry = found.get(key)
		if (pageId && !entry.pages.includes(pageId)) {
			entry.pages.push(pageId)
		}
	}

	const pages = Array.isArray(manifest?.pages) ? manifest.pages : []
	for (const page of pages) {
		const config = (page && typeof page.config === 'object' && page.config) || {}
		const pageRegister = text(config.register)
		const pageId = text(page?.id)
		add(pageRegister, text(config.schema), pageId)
		for (const widget of widgetsOf(config)) {
			const content = (typeof widget.content === 'object' && widget.content) || {}
			add(text(content.register) || pageRegister, text(content.schema), pageId)
		}
	}

	return [...found.values()]
}

/**
 * Pages, or their widgets, whose data comes from a connector: they have no
 * OpenRegister schema, so the reference lists them with their endpoint path.
 *
 * @param {object} manifest - the version's manifest.
 * @return {Array<{page: string, title: string, endpointPath: string}>}
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-document-holds-only-what-the-app-uses-req-bqad-002
 */
export function connectorPages(manifest) {
	const listed = []
	const pages = Array.isArray(manifest?.pages) ? manifest.pages : []
	for (const page of pages) {
		const config = (page && typeof page.config === 'object' && page.config) || {}
		const sources = [config.dataSource, ...widgetsOf(config).map((widget) => widget.dataSource)]
		for (const source of sources) {
			const connector = source && typeof source === 'object' ? source.connector : null
			if (connector && typeof connector === 'object') {
				listed.push({
					page: text(page?.id),
					title: text(page?.title) || text(page?.id),
					endpointPath: text(connector.endpointPath),
				})
			}
		}
	}
	return listed
}

/**
 * Every `#/components/schemas/<name>` a value refers to, at any depth.
 *
 * @param {unknown} value - a path item or schema.
 * @param {Set<string>} into - collects the names.
 * @return {Set<string>}
 */
function schemaRefs(value, into = new Set()) {
	if (Array.isArray(value)) {
		value.forEach((item) => schemaRefs(item, into))
	} else if (value && typeof value === 'object') {
		for (const [key, item] of Object.entries(value)) {
			if (key === '$ref' && typeof item === 'string' && item.startsWith('#/components/schemas/')) {
				into.add(item.slice('#/components/schemas/'.length))
			} else {
				schemaRefs(item, into)
			}
		}
	}
	return into
}

/**
 * The paths of one schema in a register's document: its collection, its
 * item, and the item's sub-resources.
 *
 * @param {object} document - the register's OpenAPI document.
 * @param {string} register - the register slug.
 * @param {string} schema - the schema slug.
 * @return {object} The path items by path.
 */
function pathsOf(document, register, schema) {
	const base = `/objects/${register}/${schema}`
	const kept = {}
	for (const [path, item] of Object.entries(document?.paths || {})) {
		if (path === base || path.startsWith(`${base}/`)) {
			kept[path] = item
		}
	}
	return kept
}

/**
 * Build the app's own OpenAPI 3 document.
 *
 * @param {object} input - what to build from.
 * @param {object} input.manifest - the version's manifest.
 * @param {object} input.documents - OpenRegister's document per register slug.
 * @param {string} input.appName - the app's name, the document's title.
 * @param {string} input.version - the version's semver.
 * @param {string} input.apiBase - this instance's OpenRegister API base address.
 * @return {{document: object, schemas: Array<{register: string, schema: string, pages: Array<string>, documented: boolean}>, notDocumented: Array<{page: string, title: string, endpointPath: string}>}}
 *         The cut document, each bound schema with whether its register's document had it, and the connector pages.
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
 */
export function buildAppOpenApi({ manifest, documents, appName, version, apiBase }) {
	const paths = {}
	const components = {}
	const securitySchemes = {}
	let security = null
	const schemas = []

	for (const binding of boundSchemas(manifest)) {
		const document = documents?.[binding.register]
		const own = document ? pathsOf(document, binding.register, binding.schema) : {}
		schemas.push({ ...binding, documented: Object.keys(own).length > 0 })
		if (Object.keys(own).length === 0) {
			continue
		}

		Object.assign(paths, own)
		Object.assign(securitySchemes, document.components?.securitySchemes || {})
		if (security === null && Array.isArray(document.security)) {
			security = document.security
		}

		// The schemas the kept calls use, and the schemas those use, from this register's document only.
		const available = document.components?.schemas || {}
		const queue = [...schemaRefs(own)]
		while (queue.length > 0) {
			const name = queue.shift()
			if (components[name] !== undefined || available[name] === undefined) {
				continue
			}
			components[name] = available[name]
			queue.push(...schemaRefs(available[name]))
		}
	}

	const document = {
		openapi: '3.0.0',
		info: { title: text(appName), version: text(version) },
		servers: [{ url: text(apiBase) }],
		paths,
		components: { schemas: components },
	}
	if (Object.keys(securitySchemes).length > 0) {
		document.components.securitySchemes = securitySchemes
	}
	if (security !== null) {
		document.security = security
	}

	return { document, schemas, notDocumented: connectorPages(manifest) }
}

/**
 * The file name a download of the document gets: the app name and version, kebab-cased.
 *
 * @param {object} document - the cut document.
 * @return {string}
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003
 */
export function openApiFileName(document) {
	const slug = `${document?.info?.title || 'app'}-${document?.info?.version || ''}`
		.toLowerCase()
		.replace(/[^a-z0-9.]+/g, '-')
		.replace(/^-+|-+$/g, '')
	return `${slug || 'app'}.openapi.json`
}
