// SPDX-License-Identifier: EUPL-1.2
/**
 * deviceFields — the form field types that use a device (a pen, the
 * microphone, the position), their option defaults and the limits both the
 * form field builder and manifest validation apply
 * (forms-signature-audio-and-location, REQ-BQSA-001, REQ-BQSA-002).
 *
 * An audio clip travels as inline `data:` content, so its length is capped by
 * the renderer's inline file cap. The size of a clip is estimated from a
 * speech bitrate (Opus at 32 kbit/s, 4000 bytes a second) plus the base64
 * overhead of a `data:` URL.
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001
 */

/** The device field types the form field builder offers. */
export const DEVICE_FIELD_TYPES = Object.freeze(['signature', 'audio', 'location'])

/** Device field types refused on a `public` form: those pages are the portal's surface. */
export const NOT_ON_PUBLIC_FORMS = Object.freeze(['audio', 'location'])

/** The shortest and longest audio length a maker may set, in seconds. */
export const AUDIO_MIN_SECONDS = 5
export const AUDIO_MAX_SECONDS = 120
export const AUDIO_DEFAULT_SECONDS = 60

/** The renderer's default inline file cap, in bytes (1 MB). */
export const INLINE_FILE_CAP = 1024 * 1024

/** Estimated bytes per second of recorded speech. */
export const AUDIO_BYTES_PER_SECOND = 4000

/**
 * The inline size of a clip of a given length: the recording plus base64.
 *
 * @param {number} seconds - the clip length.
 * @return {number} bytes.
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function audioClipBytes(seconds) {
	return Math.ceil((seconds * AUDIO_BYTES_PER_SECOND * 4) / 3)
}

/**
 * The longest clip that fits in an inline cap, in whole seconds.
 *
 * @param {number} [cap] - the cap in bytes; the renderer's default when absent.
 * @return {number}
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function longestAudioSeconds(cap = INLINE_FILE_CAP) {
	return Math.floor((cap * 3) / (4 * AUDIO_BYTES_PER_SECOND))
}

/**
 * The inline cap an audio field is held to: its own `maxSize` when set, else the default.
 *
 * @param {object} field - the form field.
 * @return {number}
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function inlineCapOf(field) {
	return Number.isFinite(field?.maxSize) && field.maxSize > 0 ? field.maxSize : INLINE_FILE_CAP
}

/**
 * The audio length of a field, its default when unset.
 *
 * @param {object} field - the form field.
 * @return {number} the stored length; a malformed value is returned as stored.
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function audioSecondsOf(field) {
	return field?.maxSeconds === undefined ? AUDIO_DEFAULT_SECONDS : field.maxSeconds
}

/**
 * The problem codes of one device field's options, empty when it is fine or not a device field.
 *
 * @param {object} field - the form field.
 * @param {string} mode - the form's mode (`public`, `create`, `edit`).
 * @return {Array<string>} codes such as `audio-length-too-long`.
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function deviceFieldProblems(field, mode) {
	const type = field?.type
	if (!DEVICE_FIELD_TYPES.includes(type)) {
		return []
	}
	const problems = []
	if (mode === 'public' && NOT_ON_PUBLIC_FORMS.includes(type)) {
		problems.push('device-field-on-public-form')
	}
	if (type === 'signature' && field.allowTyped === false && field.allowDrawn === false) {
		problems.push('signature-without-mode')
	}
	if (type === 'audio') {
		problems.push(...audioProblems(field))
	}
	if (type === 'location') {
		problems.push(...locationProblems(field))
	}
	return problems
}

/**
 * The problems of an audio field's length.
 *
 * @param {object} field - the audio field.
 * @return {Array<string>}
 */
function audioProblems(field) {
	const seconds = audioSecondsOf(field)
	if (!Number.isInteger(seconds) || seconds < AUDIO_MIN_SECONDS || seconds > AUDIO_MAX_SECONDS) {
		return ['audio-length-out-of-range']
	}
	return seconds > longestAudioSeconds(inlineCapOf(field)) ? ['audio-length-too-long'] : []
}

/**
 * The problems of a location field's target properties.
 *
 * @param {object} field - the location field.
 * @return {Array<string>}
 */
function locationProblems(field) {
	const lat = typeof field.latField === 'string' ? field.latField.trim() : ''
	const lng = typeof field.lngField === 'string' ? field.lngField.trim() : ''
	if (lat === '' || lng === '') {
		return ['location-without-fields']
	}
	return lat === lng ? ['location-same-fields'] : []
}

/**
 * The target-schema properties a device field writes, with the type each
 * needs: a `file` under the field's key for a signature or an audio clip, a
 * `number` for each of a location field's latitude, longitude and optional
 * accuracy properties. Empty for any other field.
 *
 * @param {object} field - the form field.
 * @return {{[name: string]: {type: string}}}
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function deviceFieldTargets(field) {
	const type = field?.type
	if (type === 'signature' || type === 'audio') {
		const key = typeof field.key === 'string' ? field.key.trim() : ''
		return key === '' ? {} : { [key]: { type: 'file' } }
	}
	if (type !== 'location') {
		return {}
	}
	const targets = {}
	for (const option of ['latField', 'lngField', 'accuracyField']) {
		const name = typeof field[option] === 'string' ? field[option].trim() : ''
		if (name !== '') {
			targets[name] = { type: 'number' }
		}
	}
	return targets
}

/**
 * The target properties a device field writes that the schema does not have
 * yet. A property that exists with another type is not offered: adding it
 * would overwrite nothing, and the destination check names the mismatch.
 *
 * @param {object} field - the form field.
 * @param {?object} properties - the target schema's properties; null when unknown.
 * @return {{[name: string]: {type: string}}} the properties to add, empty when none or unknown.
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
export function missingDeviceTargets(field, properties) {
	if (!properties || typeof properties !== 'object') {
		return {}
	}
	return Object.fromEntries(
		Object.entries(deviceFieldTargets(field)).filter(
			([name]) => !Object.hasOwn(properties, name),
		),
	)
}
