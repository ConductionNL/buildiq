// SPDX-License-Identifier: EUPL-1.2
/**
 * importEnvelope: reading an exported JSON envelope (`{ kind, <part>: {...} }`)
 * back in, shared by the block and form import parsers.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */

/**
 * The input as data: JSON text parsed, anything else as given.
 *
 * @param {unknown} input The file contents or an already parsed object.
 * @param {function(): Error} invalidJson Builds the error thrown for text that is not JSON.
 * @return {unknown} The data.
 * @throws {Error} The error `invalidJson` builds.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function parsedInput(input, invalidJson) {
	if (typeof input !== 'string') {
		return input
	}
	try {
		return JSON.parse(input)
	} catch {
		throw invalidJson()
	}
}

/**
 * Whether the data is an envelope of the given kind carrying an object part.
 *
 * @param {unknown} data The parsed data.
 * @param {{kind: string, part: string}} envelope The expected kind and the key of its part.
 * @return {boolean} True for a matching envelope.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function isEnvelope(data, envelope) {
	return (
		!!data
		&& typeof data === 'object'
		&& data.kind === envelope.kind
		&& !!data[envelope.part]
		&& typeof data[envelope.part] === 'object'
	)
}
