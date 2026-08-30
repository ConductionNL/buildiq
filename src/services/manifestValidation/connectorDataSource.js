// SPDX-License-Identifier: EUPL-1.2
/**
 * connectorDataSource — app-side validation of the manifest v2
 * `dataSource.connector` form. The canonical `app-manifest-v2.schema.json`
 * carries the `dataSource` $def with `additionalProperties: true`, so the
 * library validator accepts the `connector` branch without complaint; this
 * module supplies the strict shape checks buildiq needs on top of it
 * (REQ-OCAS-001), surfaced through the `useManifestValidator` pipeline.
 *
 * Returned errors are JSON-Pointer-prefixed strings of the shape
 * `<pointer>: <i18n-error-code>` so the existing path-prefix → inline-mark
 * mechanism (REQ-OBPD-011) lights up the offending editor field, and the
 * side panel shows the human message resolved from the i18n code.
 *
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-1.1
 */

/** Closed enum of supported HTTP methods for v1 read-bindings. */
export const CONNECTOR_METHODS = Object.freeze(['GET'])

/** Keys that would carry credential material — explicitly forbidden. */
const CREDENTIAL_KEYS = Object.freeze([
	'headers',
	'token',
	'apiKey',
	'authorization',
	'auth',
	'secret',
])

/** The only keys a connector block may carry. */
const ALLOWED_KEYS = Object.freeze([
	'endpointPath',
	'method',
	'query',
	'itemsPath',
	'fields',
	'cacheTtl',
])

/** Dot-path grammar: object keys / numeric indices, dot-separated. */
const DOT_PATH = /^[A-Za-z0-9_$]+(\.[A-Za-z0-9_$]+)*$/

/**
 * Validate a single `dataSource` object that may carry a `connector` branch.
 * Returns a list of `<pointer>: <code>` error strings (empty when valid or
 * when no `connector` branch is present).
 *
 * @param {object} dataSource - the `dataSource` object from a page/widget.
 * @param {string} pointer - JSON-Pointer to this dataSource (e.g.
 *   `/pages/1/config/dataSource`).
 * @return {string[]}
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-001
 */
export function validateConnectorDataSource(dataSource, pointer) {
	const errors = []
	if (!dataSource || typeof dataSource !== 'object' || Array.isArray(dataSource)) {
		return errors
	}
	const connector = dataSource.connector
	if (connector === undefined) {
		return errors
	}
	const at = (code) => `${pointer}/connector: ${code}`

	if (
		connector === null
		|| typeof connector !== 'object'
		|| Array.isArray(connector)
	) {
		errors.push(at('buildiq.connector.error.invalid-shape'))
		return errors
	}

	// Exclusivity: connector cannot coexist with register/schema or graphql.
	const hasRegisterForm =
		dataSource.register !== undefined || dataSource.schema !== undefined
	const hasGraphqlForm = dataSource.graphql !== undefined
	if (hasRegisterForm || hasGraphqlForm) {
		errors.push(`${pointer}: buildiq.connector.error.mixed-form`)
	}

	// Unknown / credential keys.
	for (const key of Object.keys(connector)) {
		if (CREDENTIAL_KEYS.includes(key)) {
			errors.push(at('buildiq.connector.error.credentials-forbidden'))
		} else if (!ALLOWED_KEYS.includes(key)) {
			errors.push(
				`${pointer}/connector/${key}: buildiq.connector.error.unknown-key`,
			)
		}
	}

	// endpointPath — required, scheme/host-free, not an /apps/ path.
	const ep = connector.endpointPath
	if (typeof ep !== 'string' || ep.trim() === '') {
		errors.push(at('buildiq.connector.error.endpoint-required'))
	} else if (ep.includes('://')) {
		errors.push(at('buildiq.connector.error.endpoint-no-scheme'))
	} else if (ep.startsWith('/apps/')) {
		errors.push(at('buildiq.connector.error.endpoint-no-apps-prefix'))
	}

	// method — optional, closed enum GET.
	if (
		connector.method !== undefined
		&& !CONNECTOR_METHODS.includes(connector.method)
	) {
		errors.push(at('buildiq.connector.error.method-unsupported'))
	}

	// query — optional, scalar-valued map.
	if (connector.query !== undefined) {
		const q = connector.query
		if (q === null || typeof q !== 'object' || Array.isArray(q)) {
			errors.push(at('buildiq.connector.error.query-not-object'))
		} else {
			for (const v of Object.values(q)) {
				if (v !== null && typeof v === 'object') {
					errors.push(at('buildiq.connector.error.query-not-scalar'))
					break
				}
			}
		}
	}

	// itemsPath — optional dot-path.
	if (connector.itemsPath !== undefined && connector.itemsPath !== '') {
		if (
			typeof connector.itemsPath !== 'string'
			|| !DOT_PATH.test(connector.itemsPath)
		) {
			errors.push(at('buildiq.connector.error.itemspath-invalid'))
		}
	}

	// fields — required, min 1 entry, dot-path string values.
	const fields = connector.fields
	if (
		fields === null
		|| typeof fields !== 'object'
		|| Array.isArray(fields)
		|| Object.keys(fields).length === 0
	) {
		errors.push(at('buildiq.connector.error.fields-required'))
	} else {
		for (const [name, sel] of Object.entries(fields)) {
			if (typeof sel !== 'string' || !DOT_PATH.test(sel)) {
				errors.push(
					`${pointer}/connector/fields/${name}: buildiq.connector.error.field-selector-invalid`,
				)
			}
		}
	}

	// cacheTtl — optional int 0–3600.
	if (connector.cacheTtl !== undefined) {
		const ttl = connector.cacheTtl
		if (!Number.isInteger(ttl) || ttl < 0 || ttl > 3600) {
			errors.push(at('buildiq.connector.error.cachettl-range'))
		}
	}

	return errors
}

/**
 * Walk every page/widget `dataSource` in a manifest and validate any
 * connector branch. Returns the merged error list for the side panel +
 * inline marks.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]}
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-001
 */
export function validateManifestConnectors(manifest) {
	const errors = []
	if (!manifest || !Array.isArray(manifest.pages)) {
		return errors
	}
	manifest.pages.forEach((page, pageIdx) => {
		const cfg = page && page.config
		if (!cfg) {
			return
		}
		if (cfg.dataSource) {
			errors.push(
				...validateConnectorDataSource(
					cfg.dataSource,
					`/pages/${pageIdx}/config/dataSource`,
				),
			)
		}
		if (Array.isArray(cfg.widgets)) {
			cfg.widgets.forEach((widget, widgetIdx) => {
				if (widget && widget.dataSource) {
					errors.push(
						...validateConnectorDataSource(
							widget.dataSource,
							`/pages/${pageIdx}/config/widgets/${widgetIdx}/dataSource`,
						),
					)
				}
			})
		}
	})
	return errors
}
