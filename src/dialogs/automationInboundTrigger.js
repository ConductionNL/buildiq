// SPDX-License-Identifier: EUPL-1.2
/**
 * The email and webhook triggers of the automation composer
 * (logic-email-and-webhook-triggers D1 to D6): which triggers start from
 * outside, why one is unavailable, the address integriq serves a webhook at,
 * the input fields a run receives, and the persisted trigger settings.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
 */

import { translate as t } from '@nextcloud/l10n'

export const EMAIL_TRIGGER = 'email-received'
export const WEBHOOK_TRIGGER = 'webhook-called'
export const INBOUND_TRIGGERS = Object.freeze([EMAIL_TRIGGER, WEBHOOK_TRIGGER])

/**
 * The fields of a received mail a run gets as input (D2).
 */
export const MAIL_INPUT_FIELDS = Object.freeze(['from', 'to', 'subject', 'receivedAt', 'bodyText', 'attachments'])

/**
 * Whether a trigger starts from outside.
 *
 * @param {string} type The trigger type.
 * @return {boolean}
 */
export function isInboundTrigger(type) {
	return INBOUND_TRIGGERS.includes(type)
}

/**
 * Why an inbound trigger cannot be used now, or '' when it can (D6).
 *
 * @param {object} state The composer state.
 * @param {string} state.type The trigger type.
 * @param {boolean} state.integriqAvailable Whether integriq is installed.
 * @param {boolean} state.mailboxesLoaded Whether the mailbox list loaded.
 * @param {number} state.mailboxCount How many mailboxes the maker can read.
 * @return {string}
 */
export function inboundTriggerReason({ type, integriqAvailable, mailboxesLoaded, mailboxCount }) {
	if (!isInboundTrigger(type)) {
		return ''
	}
	if (!integriqAvailable) {
		return t('buildiq', 'Email and webhook triggers need the Integriq app.')
	}
	if (type === EMAIL_TRIGGER && mailboxesLoaded && mailboxCount === 0) {
		return t('buildiq', 'There is no mailbox in Integriq that you can read.')
	}
	return ''
}

/**
 * The full address integriq serves a webhook automation at (D3), or '' while
 * the app or automation has no slug yet.
 *
 * @param {object} parts The address parts.
 * @param {string} parts.origin The server origin, e.g. `https://cloud.example.nl`.
 * @param {function(string): string} parts.generateUrl Nextcloud's URL generator.
 * @param {string} parts.integriqId The installed id of the integriq app.
 * @param {string} parts.appSlug The application slug.
 * @param {string} parts.automationSlug The automation slug.
 * @return {string}
 */
export function webhookAddress({ origin, generateUrl, integriqId, appSlug, automationSlug }) {
	if (!appSlug || !automationSlug) {
		return ''
	}
	const path = ['buildiq', appSlug, automationSlug].map((part) => encodeURIComponent(part)).join('/')
	return origin + generateUrl(`/apps/${integriqId}/api/endpoint/${path}`)
}

/**
 * The input fields the step pickers can offer for an inbound run.
 *
 * @param {string} type The trigger type.
 * @param {string} bodyExample The maker's JSON example of a webhook body.
 * @return {string[]}
 */
export function inboundInputFields(type, bodyExample) {
	if (type === EMAIL_TRIGGER) {
		return [...MAIL_INPUT_FIELDS]
	}
	if (type !== WEBHOOK_TRIGGER || !bodyExample || bodyExample.trim() === '') {
		return []
	}
	try {
		const parsed = JSON.parse(bodyExample)
		if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
			return Object.keys(parsed)
		}
	} catch {
		return []
	}
	return []
}

/**
 * Whether a webhook body example is empty or a JSON object.
 *
 * @param {string} bodyExample The example.
 * @return {boolean}
 */
export function isBodyExampleValid(bodyExample) {
	if (!bodyExample || bodyExample.trim() === '') {
		return true
	}
	try {
		const parsed = JSON.parse(bodyExample)
		return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
	} catch {
		return false
	}
}

/**
 * Add the inbound settings to a persisted trigger block (no secret, ever).
 *
 * @param {object} trigger The trigger block, `{type}` at least.
 * @param {object} state The composer state.
 * @return {object} The same block, with the settings of its type.
 */
export function withInboundSettings(trigger, state) {
	if (trigger.type === EMAIL_TRIGGER) {
		trigger.source = state.source || ''
		if (state.from && state.from.trim() !== '') {
			trigger.from = state.from.trim()
		}
		if (state.subjectContains && state.subjectContains.trim() !== '') {
			trigger.subjectContains = state.subjectContains.trim()
		}
	}
	if (trigger.type === WEBHOOK_TRIGGER) {
		trigger.authorization = state.authorization || 'api-key'
		if (state.bodyExample && state.bodyExample.trim() !== '') {
			trigger.bodyExample = state.bodyExample
		}
	}
	return trigger
}
