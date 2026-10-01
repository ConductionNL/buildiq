// SPDX-License-Identifier: EUPL-1.2
/**
 * formCopy: a registration form copied as a draft (change
 * apps-copy-app-and-page, REQ-BQCP-004).
 *
 * The copy keeps the source's fields, steps, rules, sections, presets,
 * audience, channel and sign-in settings for the same type, is named as a
 * copy, is a draft and is never the default, so the one-default rule cannot
 * be broken by copying. Only the keys the `registrationForm` schema declares
 * are carried over: the stored object's id and metadata stay behind.
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-a-registration-form-as-a-draft-req-bqcp-004
 */

/** The `registrationForm` properties a copy carries over unchanged. */
const CARRIED = Object.freeze([
	'audience',
	'channel',
	'channelProperty',
	'isPublic',
	'minTrust',
	'confirmationText',
	'targetApp',
	'register',
	'schema',
	'typeProperty',
	'typeValue',
	'sections',
	'fields',
	'steps',
	'formLogic',
	'presets',
	'allowSaveForLater',
])

/**
 * The body that saves a draft copy of a form.
 *
 * @param {object} form The stored source form.
 * @param {string} name The copy's name.
 * @return {object}
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-a-registration-form-as-a-draft-req-bqcp-004
 */
export function copyOfForm(form, name) {
	const copy = { name, isDefault: false, status: 'draft' }
	CARRIED.forEach((key) => {
		if (form && form[key] !== undefined && form[key] !== null) {
			copy[key] = JSON.parse(JSON.stringify(form[key]))
		}
	})
	return copy
}
