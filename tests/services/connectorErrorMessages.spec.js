/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for turning a connector validation code into a sentence.
 *
 * The validator returns `<pointer>: <code>` and the page designer used to
 * render that string as-is, so the Validation panel showed a user
 * `…/connector: buildiq.connector.error.endpoint-required`. These assert the
 * missing last step, and that it cannot damage anything it does not know.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-001).
 */
import { describe, expect, it } from 'vitest'
import { validateConnectorDataSource } from '../../src/services/manifestValidation/connectorDataSource.js'
import { resolveValidationMessage } from '../../src/services/manifestValidation/connectorErrorMessages.js'

const P = '/pages/0/config/dataSource'

describe('resolveValidationMessage', () => {
	it('replaces a known code with a sentence and keeps the pointer', () => {
		const resolved = resolveValidationMessage(
			`${P}/connector: buildiq.connector.error.endpoint-required`,
		)

		expect(resolved).toBe(
			`${P}/connector: Give the connector an endpoint path to read from.`,
		)
	})

	it('leaves an error it does not recognise exactly as it was', () => {
		const other = `${P}: some other validator said this in prose`

		expect(resolveValidationMessage(other)).toBe(other)
	})

	it('passes through a non-string unchanged rather than throwing', () => {
		expect(resolveValidationMessage(undefined)).toBe(undefined)
		expect(resolveValidationMessage(null)).toBe(null)
	})

	/*
	 * The reason this file exists: every code the validator can emit must have
	 * a sentence. A code added later with no message would otherwise reach a
	 * user as a raw identifier, which is the exact bug this fixes, and no
	 * other test would notice.
	 */
	it('has a sentence for every code the validator can produce', () => {
		const cases = [
			{ connector: null },
			{ connector: {}, register: 'r', schema: 's' },
			{ connector: { token: 'x', endpointPath: 'a', fields: { a: 'a' } } },
			{ connector: { nope: 1, endpointPath: 'a', fields: { a: 'a' } } },
			{ connector: { fields: { a: 'a' } } },
			{ connector: { endpointPath: 'https://x/y', fields: { a: 'a' } } },
			{ connector: { endpointPath: '/apps/x', fields: { a: 'a' } } },
			{ connector: { endpointPath: 'a', method: 'POST', fields: { a: 'a' } } },
			{ connector: { endpointPath: 'a', query: [], fields: { a: 'a' } } },
			{
				connector: {
					endpointPath: 'a',
					query: { a: { b: 1 } },
					fields: { a: 'a' },
				},
			},
			{
				connector: {
					endpointPath: 'a',
					itemsPath: 'not a path!',
					fields: { a: 'a' },
				},
			},
			{ connector: { endpointPath: 'a' } },
			{ connector: { endpointPath: 'a', fields: { a: 'not a path!' } } },
			{
				connector: {
					endpointPath: 'a',
					fields: { a: 'a' },
					cacheTtl: 99999,
				},
			},
		]

		const emitted = new Set()
		for (const ds of cases) {
			for (const err of validateConnectorDataSource(ds, P)) {
				emitted.add(err.slice(err.indexOf(': ') + 2))
			}
		}

		expect(emitted.size).toBeGreaterThan(0)

		const unresolved = [...emitted].filter(
			(code) => resolveValidationMessage(`${P}: ${code}`) === `${P}: ${code}`,
		)

		expect(unresolved, 'every emitted code needs a sentence').toEqual([])
	})
})
