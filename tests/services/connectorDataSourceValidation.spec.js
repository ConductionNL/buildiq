/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side connector dataSource validation.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-001, REQ-OCAS-004).
 */
import { describe, it, expect } from 'vitest'
import { validateConnectorDataSource, validateManifestConnectors } from '../../src/services/manifestValidation/connectorDataSource.js'

const P = '/pages/0/config/dataSource'

describe('validateConnectorDataSource', () => {
	it('passes a valid connector block', () => {
		const ds = { connector: { endpointPath: 'kvk/companies', query: { city: 'Utrecht' }, itemsPath: 'resultaten', fields: { name: 'naam', kvk: 'kvkNummer' } } }
		expect(validateConnectorDataSource(ds, P)).toEqual([])
	})
	it('ignores a dataSource with no connector branch', () => {
		expect(validateConnectorDataSource({ register: 'r', schema: 's' }, P)).toEqual([])
	})
	it('rejects a credential-bearing key', () => {
		const ds = { connector: { endpointPath: 'x', fields: { a: 'a' }, headers: { Authorization: 'Bearer x' } } }
		const errs = validateConnectorDataSource(ds, P)
		expect(errs.some((e) => e.includes('openbuild.connector.error.credentials-forbidden'))).toBe(true)
	})
	it('rejects a mixed register+connector form', () => {
		const ds = { register: 'r', schema: 's', connector: { endpointPath: 'x', fields: { a: 'a' } } }
		const errs = validateConnectorDataSource(ds, P)
		expect(errs.some((e) => e.includes('openbuild.connector.error.mixed-form'))).toBe(true)
	})
	it('rejects a mixed graphql+connector form', () => {
		const ds = { graphql: {}, connector: { endpointPath: 'x', fields: { a: 'a' } } }
		const errs = validateConnectorDataSource(ds, P)
		expect(errs.some((e) => e.includes('openbuild.connector.error.mixed-form'))).toBe(true)
	})
	it('rejects endpointPath with a scheme', () => {
		const ds = { connector: { endpointPath: 'https://evil.example/x', fields: { a: 'a' } } }
		const errs = validateConnectorDataSource(ds, P)
		expect(errs.some((e) => e.includes('endpoint-no-scheme'))).toBe(true)
	})
	it('rejects an /apps/ prefixed endpointPath', () => {
		const ds = { connector: { endpointPath: '/apps/openconnector/x', fields: { a: 'a' } } }
		const errs = validateConnectorDataSource(ds, P)
		expect(errs.some((e) => e.includes('endpoint-no-apps-prefix'))).toBe(true)
	})
	it('rejects a missing endpointPath', () => {
		const errs = validateConnectorDataSource({ connector: { fields: { a: 'a' } } }, P)
		expect(errs.some((e) => e.includes('endpoint-required'))).toBe(true)
	})
	it('rejects an unsupported method', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', method: 'POST', fields: { a: 'a' } } }, P)
		expect(errs.some((e) => e.includes('method-unsupported'))).toBe(true)
	})
	it('rejects a non-scalar query value', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', query: { a: { nested: 1 } }, fields: { a: 'a' } } }, P)
		expect(errs.some((e) => e.includes('query-not-scalar'))).toBe(true)
	})
	it('rejects empty fields', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', fields: {} } }, P)
		expect(errs.some((e) => e.includes('fields-required'))).toBe(true)
	})
	it('rejects an invalid field selector', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', fields: { a: 'a..b' } } }, P)
		expect(errs.some((e) => e.includes('field-selector-invalid'))).toBe(true)
	})
	it('rejects an out-of-range cacheTtl', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', fields: { a: 'a' }, cacheTtl: 99999 } }, P)
		expect(errs.some((e) => e.includes('cachettl-range'))).toBe(true)
	})
	it('rejects an unknown key', () => {
		const errs = validateConnectorDataSource({ connector: { endpointPath: 'x', fields: { a: 'a' }, bogus: 1 } }, P)
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})
})

describe('validateManifestConnectors', () => {
	it('walks pages and widgets', () => {
		const manifest = {
			pages: [
				{ config: { dataSource: { connector: { endpointPath: 'x', fields: {} } } } },
				{ config: { widgets: [{ dataSource: { connector: { endpointPath: 'https://bad/x', fields: { a: 'a' } } } }] } },
			],
		}
		const errs = validateManifestConnectors(manifest)
		expect(errs.some((e) => e.startsWith('/pages/0/config/dataSource'))).toBe(true)
		expect(errs.some((e) => e.startsWith('/pages/1/config/widgets/0/dataSource'))).toBe(true)
	})
	it('returns nothing for a register-only manifest', () => {
		const manifest = { pages: [{ config: { register: 'r', schema: 's' } }] }
		expect(validateManifestConnectors(manifest)).toEqual([])
	})
})
