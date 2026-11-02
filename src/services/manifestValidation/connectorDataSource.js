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
 * Whether a value is a non-null, non-array object.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * One `<pointer>/connector: <code>` error string.
 *
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @param {string} code - the i18n error code.
 * @return {string}
 */
function connectorError(pointer, code) {
	return `${pointer}/connector: ${code}`
}

/**
 * Unknown and credential-carrying keys on the connector block.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function keyErrors(connector, pointer) {
	const errors = []
	for (const key of Object.keys(connector)) {
		if (CREDENTIAL_KEYS.includes(key)) {
			errors.push(
				connectorError(
					pointer,
					'buildiq.connector.error.credentials-forbidden',
				),
			)
		} else if (!ALLOWED_KEYS.includes(key)) {
			errors.push(
				`${pointer}/connector/${key}: buildiq.connector.error.unknown-key`,
			)
		}
	}
	return errors
}

/**
 * endpointPath: required, scheme/host-free, not an /apps/ path.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function endpointErrors(connector, pointer) {
	const ep = connector.endpointPath
	if (typeof ep !== 'string' || ep.trim() === '') {
		return [connectorError(pointer, 'buildiq.connector.error.endpoint-required')]
	}
	if (ep.includes('://')) {
		return [
			connectorError(pointer, 'buildiq.connector.error.endpoint-no-scheme'),
		]
	}
	if (ep.startsWith('/apps/')) {
		return [
			connectorError(
				pointer,
				'buildiq.connector.error.endpoint-no-apps-prefix',
			),
		]
	}
	return []
}

/**
 * method: optional, closed enum GET.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function methodErrors(connector, pointer) {
	if (
		connector.method !== undefined
		&& !CONNECTOR_METHODS.includes(connector.method)
	) {
		return [
			connectorError(pointer, 'buildiq.connector.error.method-unsupported'),
		]
	}
	return []
}

/**
 * query: optional, scalar-valued map.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function queryErrors(connector, pointer) {
	const q = connector.query
	if (q === undefined) {
		return []
	}
	if (!isPlainObject(q)) {
		return [connectorError(pointer, 'buildiq.connector.error.query-not-object')]
	}
	if (Object.values(q).some((v) => v !== null && typeof v === 'object')) {
		return [connectorError(pointer, 'buildiq.connector.error.query-not-scalar')]
	}
	return []
}

/**
 * itemsPath: optional dot-path.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function itemsPathErrors(connector, pointer) {
	const path = connector.itemsPath
	if (path === undefined || path === '') {
		return []
	}
	if (typeof path !== 'string' || !DOT_PATH.test(path)) {
		return [connectorError(pointer, 'buildiq.connector.error.itemspath-invalid')]
	}
	return []
}

/**
 * fields: required, min 1 entry, dot-path string values.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function fieldErrors(connector, pointer) {
	const fields = connector.fields
	if (!isPlainObject(fields) || Object.keys(fields).length === 0) {
		return [connectorError(pointer, 'buildiq.connector.error.fields-required')]
	}
	return Object.entries(fields)
		.filter(([, sel]) => typeof sel !== 'string' || !DOT_PATH.test(sel))
		.map(
			([name]) =>
				`${pointer}/connector/fields/${name}: buildiq.connector.error.field-selector-invalid`,
		)
}

/**
 * cacheTtl: optional int 0–3600.
 *
 * @param {object} connector - the connector block.
 * @param {string} pointer - JSON-Pointer to the dataSource.
 * @return {string[]}
 */
function cacheTtlErrors(connector, pointer) {
	const ttl = connector.cacheTtl
	if (ttl === undefined) {
		return []
	}
	if (!Number.isInteger(ttl) || ttl < 0 || ttl > 3600) {
		return [connectorError(pointer, 'buildiq.connector.error.cachettl-range')]
	}
	return []
}

/** The per-key checks, in the order their errors are reported. */
const CONNECTOR_CHECKS = Object.freeze([
	keyErrors,
	endpointErrors,
	methodErrors,
	queryErrors,
	itemsPathErrors,
	fieldErrors,
	cacheTtlErrors,
])

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
	if (!isPlainObject(dataSource) || dataSource.connector === undefined) {
		return []
	}
	const connector = dataSource.connector
	if (!isPlainObject(connector)) {
		return [connectorError(pointer, 'buildiq.connector.error.invalid-shape')]
	}

	const errors = []
	// Exclusivity: connector cannot coexist with register/schema or graphql.
	if (
		dataSource.register !== undefined
		|| dataSource.schema !== undefined
		|| dataSource.graphql !== undefined
	) {
		errors.push(`${pointer}: buildiq.connector.error.mixed-form`)
	}
	for (const check of CONNECTOR_CHECKS) {
		errors.push(...check(connector, pointer))
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
