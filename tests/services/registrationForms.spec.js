import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registration-forms client: request URLs and params, defaults for a
 * sparse response, and a refusal normalised with its findings.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	fetchRegistrationForms,
	fetchTargetSchema,
	saveRegistrationForm,
} from '../../src/services/registrationForms.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const BASE = '/apps/buildiq/api/registration-forms'
const refusal = (status, data) => Object.assign(new Error('Request failed'), { response: { status, data } })

describe('registrationForms', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it('lists the forms of a register and schema', async () => {
		axios.get.mockResolvedValue({ data: { items: [{ id: 1 }] } })
		expect(await fetchRegistrationForms({ register: 'r', schema: 's' })).toEqual([{ id: 1 }])
		expect(axios.get).toHaveBeenCalledWith(BASE, { params: { register: 'r', schema: 's' } })
	})

	it('lists nothing for a response without items', async () => {
		axios.get.mockResolvedValue({ data: null })
		expect(await fetchRegistrationForms({ register: 'r', schema: 's' })).toEqual([])
	})

	it('reads the target schema with an empty channel property by default', async () => {
		axios.get.mockResolvedValue({ data: { properties: { a: {} }, channels: ['web'], note: 'n' } })
		expect(await fetchTargetSchema({ register: 'r', schema: 's' })).toEqual({ properties: { a: {} }, channels: ['web'], note: 'n' })
		expect(axios.get).toHaveBeenCalledWith(`${BASE}/target`, { params: { register: 'r', schema: 's', channelProperty: '' } })
	})

	it('passes the channel property and nulls what the target lacks', async () => {
		axios.get.mockResolvedValue({ data: {} })
		expect(await fetchTargetSchema({ register: 'r', schema: 's', channelProperty: 'kanaal' })).toEqual({ properties: null, channels: null, note: null })
		expect(axios.get.mock.calls[0][1].params.channelProperty).toBe('kanaal')
	})

	it('saves a form and returns the stored form, warnings and findings', async () => {
		axios.put.mockResolvedValue({ data: { form: { id: 2 }, warnings: ['w'], findings: [{ code: 'f' }] } })
		expect(await saveRegistrationForm({ name: 'x' })).toEqual({ form: { id: 2 }, warnings: ['w'], findings: [{ code: 'f' }] })
		expect(axios.put).toHaveBeenCalledWith(BASE, { name: 'x' })
	})

	it('falls back to the sent form, no warnings and no findings', async () => {
		axios.put.mockResolvedValue({ data: { findings: 'not a list' } })
		expect(await saveRegistrationForm({ name: 'x' })).toEqual({ form: { name: 'x' }, warnings: [], findings: [] })
	})

	it.each([
		['fetchRegistrationForms', () => fetchRegistrationForms({}), 'get'],
		['fetchTargetSchema', () => fetchTargetSchema({}), 'get'],
		['saveRegistrationForm', () => saveRegistrationForm({}), 'put'],
	])('%s throws a normalised refusal carrying its findings', async (_n, call, verb) => {
		axios[verb].mockRejectedValue(refusal(422, { error: 'invalid_form', message: 'Bad form', findings: [{ code: 'x' }] }))
		await expect(call()).rejects.toEqual({ status: 422, error: 'invalid_form', message: 'Bad form', findings: [{ code: 'x' }] })
	})

	it('normalises a network failure with no findings', async () => {
		axios.get.mockRejectedValue(new Error('Network Error'))
		await expect(fetchRegistrationForms({})).rejects.toEqual({ status: 0, error: 'network_error', message: 'Network Error', findings: [] })
	})
})
