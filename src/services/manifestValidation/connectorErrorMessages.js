// SPDX-License-Identifier: EUPL-1.2
/**
 * connectorErrorMessages — turn a connector validation code into a sentence.
 *
 * `connectorDataSource.js` returns errors shaped `<pointer>: <code>`, and its
 * header says "the side panel shows the human message resolved from the i18n
 * code". Nothing resolved them. The page designer rendered the raw string, so
 * the Validation panel printed
 * `/pages/0/config/dataSource/connector: buildiq.connector.error.endpoint-required`
 * at a user who has no way to know what that means.
 *
 * The codes stay: they are the machine-readable contract the pointer-prefix
 * mechanism (REQ-OBPD-011) and the validator's own tests match on. This module
 * is the missing last step, applied at render time only.
 *
 * Anything it does not recognise is returned untouched, so the other
 * validators in the pipeline keep whatever they already produce.
 *
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-001
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * One sentence per code the connector validator can emit, saying what is
 * wrong and, where there is one, what to do instead.
 *
 * @return {Record<string, string>} Code to message.
 */
function messages() {
	return {
		'buildiq.connector.error.invalid-shape': t(
			'buildiq',
			'The connector settings must be a block of keys and values.',
		),
		'buildiq.connector.error.mixed-form': t(
			'buildiq',
			'A page reads either from a connector or from a register and schema, not from both.',
		),
		'buildiq.connector.error.credentials-forbidden': t(
			'buildiq',
			'Tokens and headers do not belong in a page. Store the secret as a credential and let the connection use it.',
		),
		'buildiq.connector.error.unknown-key': t(
			'buildiq',
			'A connector accepts only endpointPath, method, query, itemsPath, fields and cacheTtl.',
		),
		'buildiq.connector.error.endpoint-required': t(
			'buildiq',
			'Give the connector an endpoint path to read from.',
		),
		'buildiq.connector.error.endpoint-no-scheme': t(
			'buildiq',
			'The endpoint is a path, not a full address. Leave out the scheme and the host.',
		),
		'buildiq.connector.error.endpoint-no-apps-prefix': t(
			'buildiq',
			'The endpoint path cannot start with /apps/.',
		),
		'buildiq.connector.error.method-unsupported': t(
			'buildiq',
			'Only GET is supported here.',
		),
		'buildiq.connector.error.query-not-object': t(
			'buildiq',
			'The query must be a set of keys and values.',
		),
		'buildiq.connector.error.query-not-scalar': t(
			'buildiq',
			'Each query value must be a single value, not a list or a block.',
		),
		'buildiq.connector.error.itemspath-invalid': t(
			'buildiq',
			'The items path is written with dots, such as results.items.',
		),
		'buildiq.connector.error.fields-required': t(
			'buildiq',
			'Map at least one field before this page can read from the connector.',
		),
		'buildiq.connector.error.field-selector-invalid': t(
			'buildiq',
			'A field selector is written with dots, such as data.name.',
		),
		'buildiq.connector.error.cachettl-range': t(
			'buildiq',
			'Cache lifetime is a whole number of seconds, from 0 to 3600.',
		),
	}
}

/**
 * Resolve one validation error for display.
 *
 * Splits on the FIRST `: ` only, because a pointer never contains one and a
 * message may.
 *
 * @param {string} error - an error as the validators return it.
 * @return {string} The same error with a known code replaced by its sentence.
 */
export function resolveValidationMessage(error) {
	if (typeof error !== 'string') {
		return error
	}

	const split = error.indexOf(': ')
	if (split === -1) {
		return messages()[error] || error
	}

	const pointer = error.slice(0, split)
	const code = error.slice(split + 2)
	const message = messages()[code]

	return message === undefined ? error : `${pointer}: ${message}`
}
