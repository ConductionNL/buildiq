// SPDX-License-Identifier: EUPL-1.2
/**
 * registrationForms — the network half of the registration-form builder
 * (spec `registration-form-builder`).
 *
 * No state lives here. A refusal keeps the sentence the server wrote, because
 * every rule on this endpoint refuses for a reason an administrator can act on:
 * a name already taken on this type, a second default, an audience the resolver
 * does not know.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/buildiq/api/registration-forms'

/**
 * Normalise an axios rejection into the server's own refusal shape.
 *
 * @param {Error} err - the axios error.
 * @return {{status: number, error: string, message: string}} The refusal.
 */
function normaliseError(err) {
	const response = err && err.response
	const data = (response && response.data) || {}
	return {
		status: response ? response.status : 0,
		error: data.error || 'network_error',
		message: data.message || (err && err.message) || 'Request failed.',
	}
}

/**
 * GET the forms stored for one register and schema.
 *
 * @param {{register: string, schema: string}} scope - which schema to read.
 * @return {Promise<Array<object>>} The forms.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md
 */
export async function fetchRegistrationForms({ register, schema }) {
	try {
		const { data } = await axios.get(generateUrl(BASE), {
			params: { register, schema },
		})
		return (data && data.items) || []
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * GET what the consuming schema declares: its property names and the values
 * the nominated channel property accepts.
 *
 * The builder offers these as pickers. A typed property name is a field whose
 * answer is dropped on save, and nothing on the form would say so.
 *
 * @param {{register: string, schema: string, channelProperty?: string}} scope - which schema to read.
 * @return {Promise<{properties: Array<string>|null, channels: Array<string>|null, note: string|null}>} What it declares.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
 */
export async function fetchTargetSchema({ register, schema, channelProperty }) {
	try {
		const { data } = await axios.get(generateUrl(`${BASE}/target`), {
			params: { register, schema, channelProperty: channelProperty || '' },
		})
		return {
			properties: (data && data.properties) || null,
			channels: (data && data.channels) || null,
			note: (data && data.note) || null,
		}
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * PUT one form.
 *
 * @param {object} form - the form to store.
 * @return {Promise<{form: object, warnings: Array<string>}>} What was stored.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md
 */
export async function saveRegistrationForm(form) {
	try {
		const { data } = await axios.put(generateUrl(BASE), form)
		return {
			form: (data && data.form) || form,
			warnings: (data && data.warnings) || [],
		}
	} catch (err) {
		throw normaliseError(err)
	}
}
