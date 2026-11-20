/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: finding library forms (REQ-BQGL-003) and using one in an app
 * (REQ-BQGL-004). The registration form body is validated against the real
 * `registrationForm` fragment with Ajv.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */

import Ajv from 'ajv'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import {
	filterLibraryForms,
	registrationFormFromLibrary,
	useLibraryForm,
	withLibraryFormPage,
} from '../../src/services/formLibrary.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/axios', () => ({ default: {} }))

const registrationForm = JSON.parse(
	readFileSync(
		resolve(
			__dirname,
			'../../lib/Settings/register.d/50-registration-forms.json',
		),
		'utf8',
	),
).components.schemas.registrationForm

const template = {
	slug: 'aanvraag-energiesubsidie',
	name: 'Aanvraag energiesubsidie',
	description: 'Vraag een energiesubsidie aan.',
	category: 'citizen-engagement',
	kind: 'registration-form',
	publisher: 'Gemeente Voorbeeld',
	sourceSchema: 'aanvraag',
	form: {
		fields: [
			{ name: 'naam', order: 0 },
			{ name: 'verbruikKwh', order: 1 },
		],
		confirmationText: 'Bedankt.',
	},
	schemaFragment: {
		naam: { type: 'string' },
		verbruikKwh: { type: 'number', minimum: 0 },
	},
}

const forms = [
	template,
	{
		...template,
		slug: 'melding-openbare-ruimte',
		name: 'Melding openbare ruimte',
		description: 'Meld een gebrek.',
		category: 'government-services',
		publisher: 'Gemeente Elders',
	},
	{
		...template,
		slug: 'verlofaanvraag',
		name: 'Verlofaanvraag',
		category: 'internal-operations',
		description: 'Verlof',
	},
]

/**
 * A fake axios that records every call.
 *
 * @return {object}
 */
function fakeClient() {
	const calls = []
	return {
		calls,
		patch: vi.fn(async (url, body) => {
			calls.push(['patch', url, body])
			return { data: body }
		}),
	}
}

describe('filterLibraryForms', () => {
	it('finds a form by part of its name', () => {
		expect(
			filterLibraryForms(forms, { query: 'subsidie' }).map((f) => f.slug),
		).toEqual(['aanvraag-energiesubsidie'])
	})

	it('narrows by category', () => {
		expect(
			filterLibraryForms(forms, { category: 'internal-operations' }).map(
				(f) => f.slug,
			),
		).toEqual(['verlofaanvraag'])
	})

	it('finds a form by its publisher', () => {
		expect(
			filterLibraryForms(forms, { query: 'elders' }).map((f) => f.slug),
		).toEqual(['melding-openbare-ruimte'])
	})
})

describe('withLibraryFormPage', () => {
	it('appends a form page that saves into the mapped schema, with a free id and route', () => {
		const pages = [
			{
				id: 'aanvraag-energiesubsidie',
				route: '/aanvraag-energiesubsidie',
				type: 'index',
			},
		]
		const next = withLibraryFormPage(pages, template, {
			register: 'subsidies',
			schema: 'subsidie-aanvraag',
		})

		expect(next).toHaveLength(2)
		expect(pages).toHaveLength(1)
		const page = next[1]
		expect(page.id).toBe('aanvraag-energiesubsidie-2')
		expect(page.route).toBe('/aanvraag-energiesubsidie-2')
		expect(page.type).toBe('form')
		expect(page.config.submitEndpoint).toBe(
			'/apps/openregister/api/objects/subsidies/subsidie-aanvraag',
		)
		expect(page.config.fields).toEqual(template.form.fields)
	})
})

describe('registrationFormFromLibrary', () => {
	it('builds a draft that the registrationForm schema accepts', () => {
		const body = registrationFormFromLibrary(template, {
			register: 'subsidies',
			schema: 'aanvraag',
			typeProperty: 'soort',
			typeValue: 'energie',
			targetApp: 'subsidies',
		})

		expect(body.status).toBe('draft')
		expect(body.isDefault).toBe(false)
		expect(body.fields).toEqual(template.form.fields)

		const { required, properties, type } = registrationForm
		const ajv = new Ajv({ strict: false, allErrors: true })
		const valid = ajv.validate(
			{ type, required, properties, additionalProperties: false },
			body,
		)
		expect(ajv.errors).toBeNull()
		expect(valid).toBe(true)
	})
})

describe('useLibraryForm', () => {
	const schema = {
		id: 42,
		slug: 'aanvraag',
		properties: { naam: { type: 'string' } },
	}

	it('writes nothing while a needed property is missing and not confirmed', async () => {
		const client = fakeClient()
		const save = vi.fn()

		await expect(
			useLibraryForm(
				{
					template,
					target: 'registration-form',
					schema,
					register: 'subsidies',
					addProperties: false,
					registration: {
						typeProperty: 'soort',
						typeValue: 'energie',
						targetApp: 'subsidies',
					},
					saveRegistrationForm: save,
				},
				client,
			),
		).rejects.toMatchObject({
			message: 'missing-properties',
			missing: ['verbruikKwh'],
		})

		expect(client.calls).toEqual([])
		expect(save).not.toHaveBeenCalled()
	})

	it('adds the missing property, keeps the others, then saves the registration form', async () => {
		const client = fakeClient()
		const save = vi.fn(async (body) => ({ form: body }))

		const result = await useLibraryForm(
			{
				template,
				target: 'registration-form',
				schema,
				register: 'subsidies',
				addProperties: true,
				registration: {
					typeProperty: 'soort',
					typeValue: 'energie',
					targetApp: 'subsidies',
				},
				saveRegistrationForm: save,
			},
			client,
		)

		expect(result.added).toEqual(['verbruikKwh'])
		expect(client.calls).toHaveLength(1)
		const [verb, url, body] = client.calls[0]
		expect(verb).toBe('patch')
		expect(url).toBe('/apps/openregister/api/schemas/42')
		expect(body.properties).toEqual({
			naam: { type: 'string' },
			verbruikKwh: { type: 'number', minimum: 0 },
		})
		expect(save).toHaveBeenCalledTimes(1)
		expect(save.mock.calls[0][0].schema).toBe('aanvraag')
	})

	it('adds a form page to the version manifest by a PATCH of the manifest only', async () => {
		const client = fakeClient()
		const version = {
			'@self': { id: 'v-uuid' },
			manifest: { menu: [], pages: [] },
		}

		const result = await useLibraryForm(
			{
				template,
				target: 'form-page',
				schema: { ...schema, properties: { naam: {}, verbruikKwh: {} } },
				register: 'subsidies',
				addProperties: false,
				version,
			},
			client,
		)

		expect(result.added).toEqual([])
		expect(client.calls).toHaveLength(1)
		const [, url, body] = client.calls[0]
		expect(url).toBe(
			'/apps/openregister/api/objects/buildiq/applicationVersion/v-uuid',
		)
		expect(Object.keys(body)).toEqual(['manifest'])
		expect(body.manifest.menu).toEqual([])
		expect(body.manifest.pages[0].type).toBe('form')
	})
})

describe('filterLibraryForms edges', () => {
	it('answers empty for a missing or non-array list and skips empty entries', () => {
		expect(filterLibraryForms(null)).toEqual([])
		expect(filterLibraryForms({ a: template })).toEqual([])
		expect(filterLibraryForms([null, template, undefined])).toEqual([template])
	})

	it('matches case-insensitively on a trimmed query, also in the description', () => {
		expect(
			filterLibraryForms(forms, { query: '  MELD een  ' }).map((f) => f.slug),
		).toEqual(['melding-openbare-ruimte'])
		expect(
			filterLibraryForms(forms, { query: null, category: 'internal-operations' }).map(
				(f) => f.slug,
			),
		).toEqual(['verlofaanvraag'])
	})

	it('ignores fields that are not strings', () => {
		const odd = { name: 7, description: null, publisher: ['subsidie'] }
		expect(filterLibraryForms([odd], { query: '7' })).toEqual([])
		expect(filterLibraryForms([odd], {})).toEqual([odd])
	})
})

describe('form mapping onto the target schema', () => {
	const linked = {
		...template,
		form: { fields: [{ name: 'x', schema: 'aanvraag' }], steps: null },
	}

	it('rewrites the source schema to the mapped one', () => {
		const pages = withLibraryFormPage([], linked, { register: 'r', schema: 'doel' })
		expect(pages[0].config.fields).toEqual([{ name: 'x', schema: 'doel' }])
		expect(linked.form.fields[0].schema).toBe('aanvraag')
	})

	it('keeps the form when the schema is the same, unnamed or the template has no source', () => {
		const same = withLibraryFormPage([], linked, { register: 'r', schema: 'aanvraag' })
		expect(same[0].config.fields[0].schema).toBe('aanvraag')
		const unnamed = registrationFormFromLibrary(linked, { schema: '' })
		expect(unnamed.fields[0].schema).toBe('aanvraag')
		expect(Object.hasOwn(unnamed, 'steps')).toBe(false)
		const noSource = registrationFormFromLibrary(
			{ ...linked, sourceSchema: '' },
			{ schema: 'doel' },
		)
		expect(noSource.fields[0].schema).toBe('aanvraag')
	})

	it('treats a template without a form as an empty form', () => {
		const pages = withLibraryFormPage(null, { slug: 'x' }, { register: 'r', schema: 's' })
		expect(pages).toEqual([
			{
				id: 'x',
				route: '/x',
				type: 'form',
				title: 'x',
				config: { submitEndpoint: '/apps/openregister/api/objects/r/s' },
			},
		])
	})
})

describe('useLibraryForm edges', () => {
	const schema = { id: 9, slug: 'aanvraag', properties: { naam: {}, verbruikKwh: {} } }

	it('refuses an unknown target before anything else', async () => {
		const client = fakeClient()
		await expect(
			useLibraryForm({ template, target: 'page', schema: null }, client),
		).rejects.toThrow('Unknown target: page')
		expect(client.calls).toEqual([])
	})

	it('patches a version known by uuid, by id, or by nothing', async () => {
		for (const [version, key] of [
			[{ uuid: 'u 1', manifest: { pages: [{ id: 'aanvraag-energiesubsidie', route: '/x' }] } }, 'u%201'],
			[{ id: 5 }, '5'],
			[undefined, ''],
		]) {
			const client = fakeClient()
			const result = await useLibraryForm(
				{ template, target: 'form-page', schema, register: 'r', version },
				client,
			)
			expect(client.calls[0][1]).toBe(
				`/apps/openregister/api/objects/buildiq/applicationVersion/${key}`,
			)
			expect(result.page.id).toBe(
				version && version.manifest ? 'aanvraag-energiesubsidie-2' : 'aanvraag-energiesubsidie',
			)
			expect(Object.keys(result)).toEqual(['added', 'page'])
		}
	})

	it('answers the saved form, or the body when the save returns none', async () => {
		const saved = { id: 'f' }
		const withForm = await useLibraryForm({
			template,
			target: 'registration-form',
			schema,
			register: 'r',
			saveRegistrationForm: async () => ({ form: saved }),
		})
		expect(withForm).toEqual({ added: [], form: saved })
		const withoutForm = await useLibraryForm({
			template,
			target: 'registration-form',
			schema,
			register: 'r',
			saveRegistrationForm: async () => null,
		})
		expect(withoutForm.form).toMatchObject({
			register: 'r',
			schema: 'aanvraag',
			status: 'draft',
			typeProperty: undefined,
		})
	})
})
