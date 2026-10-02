/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: capturing a form for the form library (REQ-BQGL-002), and the
 * properties a target schema lacks (REQ-BQGL-004). The captured record is
 * validated against the real `formTemplate` fragment with Ajv.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */

import Ajv from 'ajv'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	boundProperties,
	captureForm,
	FormBindingError,
	missingProperties,
} from '../../src/services/formCapture.js'

const fragment = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../lib/Settings/register.d/83-form-library.json'),
		'utf8',
	),
).components.schemas.formTemplate

/**
 * Validate a record against the real fragment, keeping only the JSON Schema keywords.
 *
 * @param {object} record The record.
 * @return {boolean}
 */
function validFragment(record) {
	const { required, properties, type } = fragment
	const ajv = new Ajv({ strict: false, allErrors: true })
	return ajv.validate(
		{ type, required, properties, additionalProperties: false },
		record,
	)
}

const schema = {
	slug: 'subsidies-aanvraag',
	properties: {
		naam: { type: 'string', title: 'Naam' },
		verbruikKwh: { type: 'number', title: 'Verbruik (kWh)', minimum: 0 },
		kanaal: { type: 'string', enum: ['portal', 'desk'] },
		intern: { type: 'string' },
	},
}

const registrationForm = {
	name: 'Aanvraag energiesubsidie',
	targetApp: 'subsidies',
	register: 'subsidies',
	schema: 'subsidies-aanvraag',
	typeProperty: 'soort',
	typeValue: 'energie',
	audience: 'applicant',
	status: 'published',
	isDefault: true,
	sections: [{ id: 'gegevens', label: 'Uw gegevens' }],
	fields: [
		{
			name: 'naam',
			label: 'Naam',
			type: 'string',
			section: 'gegevens',
			order: 10,
		},
		{
			name: 'verbruikKwh',
			label: 'Verbruik',
			type: 'number',
			section: 'gegevens',
			order: 20,
		},
	],
	presets: [{ field: 'kanaal', value: 'portal', hidden: true }],
	confirmationText: 'Dank u wel.',
	formLogic: { visibleWhen: {} },
}

const metadata = {
	slug: 'aanvraag-energiesubsidie',
	name: 'Aanvraag energiesubsidie',
	category: 'citizen-engagement',
	publisher: 'Gemeente Voorbeeld',
	createdBy: 'maker',
}

describe('captureForm (REQ-BQGL-002)', () => {
	it('captures a registration form with the definitions of its bound properties, and nothing else', () => {
		const record = captureForm({
			kind: 'registration-form',
			form: registrationForm,
			schema,
			appSlug: 'subsidies',
			metadata,
		})

		expect(record.kind).toBe('registration-form')
		expect(record.sourceSchema).toBe('aanvraag')
		expect(Object.keys(record.schemaFragment)).toEqual([
			'naam',
			'verbruikKwh',
			'kanaal',
		])
		expect(record.schemaFragment.verbruikKwh).toEqual({
			type: 'number',
			title: 'Verbruik (kWh)',
			minimum: 0,
		})
		expect(Object.keys(record.form).sort()).toEqual([
			'confirmationText',
			'fields',
			'formLogic',
			'presets',
			'sections',
		])
		expect(record.form).not.toHaveProperty('isDefault')
		expect(record.form).not.toHaveProperty('register')
		expect(record.publisher).toBe('Gemeente Voorbeeld')
		expect(validFragment(record)).toBe(true)
	})

	it('does not change the form it captured', () => {
		const before = JSON.stringify(registrationForm)
		const record = captureForm({
			kind: 'registration-form',
			form: registrationForm,
			schema,
			appSlug: 'subsidies',
			metadata,
		})
		record.form.fields[0].label = 'changed'
		record.schemaFragment.naam.title = 'changed'

		expect(JSON.stringify(registrationForm)).toBe(before)
		expect(schema.properties.naam.title).toBe('Naam')
	})

	it('captures a form page by its field keys and de-namespaces its schema references', () => {
		const page = {
			submitEndpoint:
				'/apps/openregister/api/objects/subsidies/subsidies-aanvraag',
			fields: [
				{ key: 'naam', label: 'Naam', type: 'string' },
				{
					key: 'relatie',
					type: 'relation',
					relatedSchema: 'subsidies-aanvraag',
				},
			],
		}
		const withRelation = {
			...schema,
			properties: { ...schema.properties, relatie: { type: 'string' } },
		}
		const record = captureForm({
			kind: 'form-page',
			form: page,
			schema: withRelation,
			appSlug: 'subsidies',
			metadata,
		})

		expect(record.form).not.toHaveProperty('submitEndpoint')
		expect(record.form.fields[1].relatedSchema).toBe('aanvraag')
		expect(Object.keys(record.schemaFragment)).toEqual(['naam', 'relatie'])
		expect(validFragment(record)).toBe(true)
	})

	it('refuses a form bound to a property its schema lacks, naming the property', () => {
		const broken = {
			...registrationForm,
			fields: [...registrationForm.fields, { name: 'iban', label: 'IBAN' }],
		}

		let error = null
		try {
			captureForm({
				kind: 'registration-form',
				form: broken,
				schema,
				appSlug: 'subsidies',
				metadata,
			})
		} catch (e) {
			error = e
		}
		expect(error).toBeInstanceOf(FormBindingError)
		expect(error.missing).toEqual(['iban'])
	})

	it('the fragment refuses a record with a kind it does not know (control)', () => {
		const record = captureForm({
			kind: 'registration-form',
			form: registrationForm,
			schema,
			appSlug: 'subsidies',
			metadata,
		})

		expect(validFragment({ ...record, kind: 'component-block' })).toBe(false)
		expect(validFragment({ ...record, objects: [{ naam: 'Jan' }] })).toBe(false)
	})
})

describe('missingProperties (REQ-BQGL-004)', () => {
	it('lists what the target schema lacks, with the stored definition', () => {
		const record = captureForm({
			kind: 'registration-form',
			form: registrationForm,
			schema,
			appSlug: 'subsidies',
			metadata,
		})
		const target = {
			properties: { naam: { type: 'string' }, kanaal: { type: 'string' } },
		}

		expect(missingProperties(record, target)).toEqual({
			verbruikKwh: { type: 'number', title: 'Verbruik (kWh)', minimum: 0 },
		})
		expect(boundProperties(record.form, record.kind)).toEqual([
			'naam',
			'verbruikKwh',
			'kanaal',
		])
	})
})
