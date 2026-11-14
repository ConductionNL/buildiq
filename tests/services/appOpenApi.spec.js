/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/appOpenApi.js: an app's API reference holds only
 * the schemas its pages and widgets bind, and lists connector pages apart.
 *
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md
 */
import { describe, expect, it } from 'vitest'
import { boundSchemas, buildAppOpenApi, connectorPages, openApiFileName } from '../../src/services/appOpenApi.js'

/**
 * A register document the way OpenRegister's OasService shapes it.
 *
 * @param {string} register - the register slug.
 * @param {Array<string>} schemas - schema slugs; each gets a component of the capitalised name.
 * @return {object}
 */
function oas(register, schemas) {
	const paths = {}
	const components = { Error: { type: 'object', properties: { message: { type: 'string' } } } }
	for (const slug of schemas) {
		const name = slug.charAt(0).toUpperCase() + slug.slice(1)
		const ref = { $ref: `#/components/schemas/${name}` }
		paths[`/objects/${register}/${slug}`] = {
			get: { responses: { 200: { content: { 'application/json': { schema: { type: 'array', items: ref } } } } } },
			post: { requestBody: { content: { 'application/json': { schema: ref } } } },
		}
		paths[`/objects/${register}/${slug}/{id}`] = {
			get: { responses: { 200: { content: { 'application/json': { schema: ref } } }, 404: { content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } } } },
		}
		components[name] = { type: 'object', properties: { title: { type: 'string', maxLength: 255 } } }
	}
	return {
		openapi: '3.0.0',
		info: { title: `${register} API`, version: '1.0.0' },
		paths,
		components: { schemas: components, securitySchemes: { basicAuth: { type: 'http', scheme: 'basic' } } },
		security: [{ basicAuth: [] }],
	}
}

const BAG = oas('bag', Array.from({ length: 30 }, (_, i) => (i === 7 ? 'address' : `table${i}`)))

const MANIFEST = {
	pages: [
		{ id: 'permits', type: 'index', config: { register: 'openbuild-vergunningen-production', schema: 'permit' } },
		{ id: 'permit', type: 'detail', config: { register: 'openbuild-vergunningen-production', schema: 'permit' } },
		{
			id: 'dashboard',
			type: 'dashboard',
			config: {
				register: 'openbuild-vergunningen-production',
				widgets: [
					{ id: 'applicants', content: { schema: 'applicant' } },
					{ id: 'addresses', content: { register: 'bag', schema: 'address' } },
				],
			},
		},
		{ id: 'weather', title: 'Weather', type: 'index', config: { dataSource: { connector: { endpointPath: '/api/endpoints/weather', fields: {} } } } },
	],
}

const DOCUMENTS = {
	'openbuild-vergunningen-production': (() => {
		const doc = oas('openbuild-vergunningen-production', ['permit', 'applicant', 'archive'])
		// A permit refers to its applicant: the applicant component comes along.
		doc.components.schemas.Permit.properties.applicant = { $ref: '#/components/schemas/Applicant' }
		return doc
	})(),
	bag: BAG,
}

describe('boundSchemas', () => {
	it('lists every page and widget binding once, a widget falling back to its page register', () => {
		expect(boundSchemas(MANIFEST)).toEqual([
			{ register: 'openbuild-vergunningen-production', schema: 'permit', pages: ['permits', 'permit'] },
			{ register: 'openbuild-vergunningen-production', schema: 'applicant', pages: ['dashboard'] },
			{ register: 'bag', schema: 'address', pages: ['dashboard'] },
		])
	})

	it('answers nothing for an empty or malformed manifest', () => {
		expect(boundSchemas({})).toEqual([])
		expect(boundSchemas({ pages: [null, { id: 'x', config: { schema: 'no-register' } }] })).toEqual([])
	})
})

describe('buildAppOpenApi', () => {
	const built = buildAppOpenApi({
		manifest: MANIFEST,
		documents: DOCUMENTS,
		appName: 'Vergunningen',
		version: '1.4.0',
		apiBase: 'https://cloud.example.nl/index.php/apps/openregister/api',
	})

	it('titles the document after the app and carries the version and this instance\'s base', () => {
		expect(built.document.openapi).toBe('3.0.0')
		expect(built.document.info).toEqual({ title: 'Vergunningen', version: '1.4.0' })
		expect(built.document.servers).toEqual([{ url: 'https://cloud.example.nl/index.php/apps/openregister/api' }])
	})

	it('keeps the calls of the bound schemas of the app\'s own register and drops the rest', () => {
		const paths = Object.keys(built.document.paths)
		expect(paths).toContain('/objects/openbuild-vergunningen-production/permit')
		expect(paths).toContain('/objects/openbuild-vergunningen-production/permit/{id}')
		expect(paths).toContain('/objects/openbuild-vergunningen-production/applicant')
		expect(paths.some((path) => path.includes('/archive'))).toBe(false)
	})

	it('takes only the bound schema from a shared data register', () => {
		const bagPaths = Object.keys(built.document.paths).filter((path) => path.startsWith('/objects/bag/'))
		expect(bagPaths).toEqual(['/objects/bag/address', '/objects/bag/address/{id}'])
		expect(Object.keys(built.document.components.schemas).sort()).toEqual(['Address', 'Applicant', 'Error', 'Permit'])
		expect(built.document.components.schemas.Table3).toBeUndefined()
	})

	it('keeps how to sign in, without any credential', () => {
		expect(built.document.components.securitySchemes).toEqual({ basicAuth: { type: 'http', scheme: 'basic' } })
		expect(built.document.security).toEqual([{ basicAuth: [] }])
	})

	it('reports which bound schemas were documented', () => {
		const missing = buildAppOpenApi({ manifest: MANIFEST, documents: { bag: BAG }, appName: 'X', version: '1.0.0', apiBase: '' })
		expect(missing.schemas.map((entry) => [entry.schema, entry.documented])).toEqual([
			['permit', false],
			['applicant', false],
			['address', true],
		])
	})

	it('lists the connector page as not documented here, with its endpoint path', () => {
		expect(built.notDocumented).toEqual([{ page: 'weather', title: 'Weather', endpointPath: '/api/endpoints/weather' }])
		expect(connectorPages({ pages: [{ id: 'p', config: { widgets: [{ dataSource: { connector: { endpointPath: '/e' } } }] } }] }))
			.toEqual([{ page: 'p', title: 'p', endpointPath: '/e' }])
	})
})

describe('openApiFileName', () => {
	it('names the download after the app and version', () => {
		expect(openApiFileName({ info: { title: 'Vergunningen', version: '1.4.0' } })).toBe('vergunningen-1.4.0.openapi.json')
		expect(openApiFileName({})).toBe('app.openapi.json')
	})
})
