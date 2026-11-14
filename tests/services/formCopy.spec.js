/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/formCopy.js and "Copy form" in the
 * registration form list (change apps-copy-app-and-page, T05, REQ-BQCP-004).
 *
 * The copy is validated with Ajv against the real `registrationForm` fragment
 * in lib/Settings/register.d/50-registration-forms.json, the schema the
 * server saves it under, so a key the schema does not declare or a value of
 * the wrong type fails here rather than on a live save.
 */
import { mount } from '@vue/test-utils'
import Ajv from 'ajv'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

globalThis.t = (app, text, vars) =>
	Object.entries(vars || {}).reduce(
		(out, [k, v]) => out.replace(`{${k}}`, v),
		String(text),
	)

vi.mock('../../src/services/registrationForms.js', () => ({
	fetchRegistrationForms: vi.fn(),
	fetchTargetSchema: vi.fn(),
	saveRegistrationForm: vi.fn(),
}))

const { copyOfForm } = await import('../../src/services/formCopy.js')
const { default: RegistrationFormList } =
	await import('../../src/components/page-editor/fields/RegistrationFormList.vue')
const { fetchRegistrationForms, fetchTargetSchema, saveRegistrationForm } =
	await import('../../src/services/registrationForms.js')

const fragment = JSON.parse(
	readFileSync(
		resolve(
			__dirname,
			'../../lib/Settings/register.d/50-registration-forms.json',
		),
		'utf8',
	),
).components.schemas.registrationForm

/**
 * The stored default citizen form, as the list endpoint answers it.
 *
 * @return {object}
 */
function clientIntake() {
	return {
		id: 'f1',
		'@self': { id: 'f1', owner: 'anna' },
		name: 'client-intake',
		audience: 'client',
		isDefault: true,
		status: 'published',
		channel: 'portal',
		targetApp: 'dossiq',
		register: 'dossiq',
		schema: 'Zaak',
		typeProperty: 'caseType',
		typeValue: 'bouwvergunning',
		fields: [{ name: 'naam', label: 'Naam', type: 'string' }],
		steps: [{ id: 's1', title: 'You', fields: ['naam'] }],
		formLogic: { rules: [] },
		presets: [{ field: 'intakeChannel', value: 'portal', hidden: true }],
	}
}

describe('copyOfForm (REQ-BQCP-004)', () => {
	it('is a draft, never the default, named as a copy, with the same fields, steps, rules and presets', () => {
		const copy = copyOfForm(clientIntake(), 'Copy of client-intake')
		const source = clientIntake()

		expect(copy).toEqual({
			name: 'Copy of client-intake',
			audience: 'client',
			isDefault: false,
			status: 'draft',
			channel: 'portal',
			targetApp: 'dossiq',
			register: 'dossiq',
			schema: 'Zaak',
			typeProperty: 'caseType',
			typeValue: 'bouwvergunning',
			fields: source.fields,
			steps: source.steps,
			formLogic: source.formLogic,
			presets: source.presets,
		})
	})

	it('validates against the real registrationForm schema', () => {
		const ajv = new Ajv({ strict: false, allErrors: true })
		const validate = ajv.compile({ ...fragment, additionalProperties: false })
		const valid = validate(copyOfForm(clientIntake(), 'Copy of client-intake'))
		expect(validate.errors).toBe(null)
		expect(valid).toBe(true)
	})
})

describe('Copy form in the list', () => {
	beforeEach(() => {
		fetchTargetSchema.mockResolvedValue({
			properties: ['naam'],
			channels: ['portal'],
			note: null,
		})
		saveRegistrationForm.mockReset().mockImplementation(async (form) => ({
			form: { ...form, id: 'f2' },
			warnings: [],
		}))
	})

	it('saves a draft copy and opens it, leaving the original the default', async () => {
		fetchRegistrationForms.mockResolvedValue([clientIntake()])
		const wrapper = mount(RegistrationFormList, {
			props: {
				register: 'dossiq',
				schema: 'Zaak',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
				targetApp: 'dossiq',
			},
		})
		await new Promise((r) => setTimeout(r, 0))
		await wrapper.vm.$nextTick()

		await wrapper.find('.form-list__copy').trigger('click')
		await new Promise((r) => setTimeout(r, 0))

		const saved = saveRegistrationForm.mock.calls[0][0]
		expect(saved.isDefault).toBe(false)
		expect(saved.status).toBe('draft')
		expect(saved.name).toBe('Copy of client-intake')
		expect(wrapper.vm.editing.id).toBe('f2')
	})
})
