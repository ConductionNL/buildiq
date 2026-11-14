// SPDX-License-Identifier: EUPL-1.2
/**
 * journeyStore — the network side of the Journey Designer
 * (journey-designer).
 *
 * A journey is read like any OpenRegister object, but it is SAVED only
 * through buildiq's own route, which refuses anyone who may not author
 * journeys and judges every write through the validator the submit path uses
 * before it stores anything. The forms a step can reference are the form
 * library's forms; a step holds the library form's id.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md
 */
import defaultAxios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { fetchLibraryForms } from './formLibrary.js'

/** Where OpenRegister serves the journey objects. */
export const JOURNEY_PATH = '/apps/openregister/api/objects/buildiq/journey'

/** buildiq's save route for a journey. */
export const JOURNEY_SAVE_PATH = '/apps/buildiq/api/journeys'

/**
 * The id OpenRegister knows a stored object by.
 *
 * @param {object} object - a stored object.
 * @return {string}
 */
export function idOf(object) {
	if (!object || typeof object !== 'object') {
		return ''
	}
	const self = object['@self'] || {}
	return String(self.id || object.uuid || object.id || '')
}

/**
 * One journey by id.
 *
 * @param {string} id - the journey's id.
 * @param {object} [client] - axios-like client.
 * @return {Promise<object>} The journey: `{ id, title, description, steps }`.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
export async function fetchJourney(id, client = defaultAxios) {
	const { data } = await client.get(generateUrl(`${JOURNEY_PATH}/${encodeURIComponent(id)}`))
	const journey = data || {}
	return {
		id: idOf(journey) || String(id),
		title: journey.title || '',
		description: journey.description || '',
		steps: Array.isArray(journey.steps) ? journey.steps : [],
	}
}

/**
 * The forms a step can reference, by id, and the options to pick them from.
 *
 * @param {Array<object>} templates - the form library's forms.
 * @return {{forms: {[id: string]: object}, options: Array<{id: string, label: string}>}}
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
export function formsOf(templates) {
	const forms = {}
	const options = []
	for (const template of Array.isArray(templates) ? templates : []) {
		const id = idOf(template)
		if (id === '' || !template.form || typeof template.form !== 'object') {
			continue
		}
		forms[id] = template.form
		options.push({ id, label: String(template.name || template.slug || id) })
	}
	return { forms, options }
}

/**
 * The form library's forms, by id.
 *
 * @param {object} [client] - axios-like client.
 * @return {Promise<{forms: object, options: Array<object>}>}
 */
export async function fetchJourneyForms(client = defaultAxios) {
	return formsOf(await fetchLibraryForms(client))
}

/**
 * Save a journey through buildiq. A refusal is returned, not thrown: a 403
 * carries the reason (it names journey authoring) and a 422 the findings on
 * the writes.
 *
 * @param {object} journey - the journey as designed.
 * @param {object} [client] - axios-like client.
 * @return {Promise<{saved: boolean, id?: string, status?: number, message?: string, findings: Array<object>}>}
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
 */
export async function saveJourney(journey, client = defaultAxios) {
	try {
		const { data } = await client.post(generateUrl(JOURNEY_SAVE_PATH), { journey })
		return { saved: true, id: String((data && data.id) || ''), findings: [] }
	} catch (error) {
		const response = error && error.response
		if (!response) {
			throw error
		}
		const body = response.data || {}
		return {
			saved: false,
			status: response.status,
			message: String(body.message || ''),
			findings: Array.isArray(body.findings) ? body.findings : [],
		}
	}
}
