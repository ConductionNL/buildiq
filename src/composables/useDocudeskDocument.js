import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// SPDX-License-Identifier: EUPL-1.2
/**
 * useDocudeskDocument — runtime integration with Docudesk's correspondence
 * API for document-template attachments (REQ-DDT-003). Buildiq stays a pure
 * API consumer of Docudesk's existing public surface (ADR-022): it POSTs a
 * `dataRefs` reference to the current OR object, lets Docudesk render the
 * branded document, and hands the result to the browser as a download. No
 * document rendering, no template logic, no buildiq PHP.
 *
 * Failure-tolerant by design (REQ-DDT-003): a generate failure NEVER mutates
 * the object or navigates away — it surfaces a typed, non-blocking error
 * (`no-access` for 403, `generate-failed` otherwise) the caller renders as a
 * toast. Generation is idempotent under double-click via a per-attachment+
 * object in-flight guard.
 *
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
 */
import { ref } from 'vue'

const GENERATE = '/apps/filinq/api/correspondence/generate'

/** Extension per Docudesk output format (pinned set, REQ-DDT-001). */
const FORMAT_EXT = Object.freeze({
	pdf: 'pdf',
	docx: 'docx',
	html: 'html',
	email: 'html',
})

/**
 * Render a `filenameTemplate` with `{{objectProperty}}` placeholders resolved
 * against the object's top-level (and `@self`) properties. Safe: pure string
 * substitution, no eval; a missing property → empty string.
 *
 * @param {string} template - the filename template.
 * @param {object} object - the OR object.
 * @return {string} - the rendered filename (may be empty).
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
 */
export function renderFilename(template, object) {
	if (typeof template !== 'string' || template === '') {
		return ''
	}
	return template.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, key) => {
		const value =
			(object && object[key])
			?? (object && object['@self'] && object['@self'][key])
		return value === undefined || value === null ? '' : String(value)
	})
}

/**
 * Resolve a single `dataRefs` entry `{ register, schema, id }` from the OR
 * object's `@self` envelope (the version-routed register/schema the runtime is
 * reading + the object UUID) — never a serialized copy of the object's data.
 * Falls back to the attachment's declared schema when the object omits it.
 *
 * @param {object} object - the OR object.
 * @param {object} attachment - the `runtime.documents[]` entry.
 * @return {?{register: (string|number), schema: (string|number), id: string}}
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
 */
export function resolveDataRef(object, attachment) {
	const self = selfOf(object)
	const register = recordRegister(self, object)
	const schema = recordSchema(self, object) ?? attachmentSchema(attachment)
	const id = recordId(self, object)
	if (isNullish(register) || !schema || !id) {
		return null
	}
	return { register, schema, id }
}

/**
 * The object's `@self` envelope, or an empty one.
 *
 * @param {object} object - the OR object.
 * @return {object}
 */
function selfOf(object) {
	return (object && object['@self']) || {}
}

/**
 * The register the object names, from `@self` first, then the top level.
 *
 * @param {object} self - the object's `@self` envelope.
 * @param {object} object - the OR object.
 * @return {(string|number|undefined|null)}
 */
function recordRegister(self, object) {
	return self.register ?? object.register
}

/**
 * The schema the object names, from `@self` first, then the top level.
 *
 * @param {object} self - the object's `@self` envelope.
 * @param {object} object - the OR object.
 * @return {(string|number|undefined|null)}
 */
function recordSchema(self, object) {
	return self.schema ?? object.schema
}

/**
 * The schema the attachment declares, if any.
 *
 * @param {?object} attachment - the `runtime.documents[]` entry.
 * @return {(string|number|undefined|null)}
 */
function attachmentSchema(attachment) {
	return attachment && attachment.schema
}

/**
 * The object's id: the `@self` id, else its uuid, else its id.
 *
 * @param {object} self - the object's `@self` envelope.
 * @param {object} object - the OR object.
 * @return {(string|undefined)}
 */
function recordId(self, object) {
	return self.id || object.uuid || object.id
}

/**
 * Whether a value is undefined or null.
 *
 * @param {unknown} value - any value.
 * @return {boolean}
 */
function isNullish(value) {
	return value === undefined || value === null
}

/**
 * The typed error code for a failed generate request.
 *
 * @param {unknown} e - the rejection.
 * @return {string} - `no-access` for a 403, `generate-failed` otherwise.
 */
function generateErrorCode(e) {
	const status = e && e.response && e.response.status
	return status === 403 ? 'no-access' : 'generate-failed'
}

/**
 * Document-generation integration for one document attachment.
 *
 * @param {object} [opts] - options.
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @param {Function} [opts.download] - download injection for tests
 *   (default: anchor-click on an object URL).
 * @return {object} - `{ busyFor, errorFor, generate }`.
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
 */
export function useDocudeskDocument(opts = {}) {
	const client = opts.client || axios
	const doDownload = opts.download || defaultDownload

	// Per-key (attachment+object) reactive busy + error state.
	const busy = ref({})
	const errorState = ref({})

	/**
	 * Stable key for the in-flight guard + per-action state.
	 *
	 * @param {object} attachment - the attachment.
	 * @param {object} object - the OR object.
	 * @return {string}
	 */
	function keyOf(attachment, object) {
		const ref2 = resolveDataRef(object, attachment) || {}
		return `${(attachment && attachment.id) || ''}::${ref2.id || ''}`
	}

	/**
	 * Reactive busy flag for an attachment+object pair.
	 *
	 * @param {object} attachment - the attachment.
	 * @param {object} object - the OR object.
	 * @return {boolean}
	 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-004
	 */
	function busyFor(attachment, object) {
		return !!busy.value[keyOf(attachment, object)]
	}

	/**
	 * Reactive typed error code for an attachment+object pair (or null).
	 *
	 * @param {object} attachment - the attachment.
	 * @param {object} object - the OR object.
	 * @return {?string} - `no-access` | `generate-failed` | null.
	 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-004
	 */
	function errorFor(attachment, object) {
		return errorState.value[keyOf(attachment, object)] || null
	}

	/**
	 * Generate the document for `attachment` against `object` and hand the
	 * result to the browser as a download. Resolves to a typed error code
	 * (string) on failure, or null on success — never throws past the caller.
	 *
	 * @param {object} attachment - the `runtime.documents[]` entry.
	 * @param {object} object - the OR object being viewed.
	 * @return {Promise<?string>} - null on success, error code on failure.
	 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
	 */
	async function generate(attachment, object) {
		const key = keyOf(attachment, object)
		if (busy.value[key]) {
			// In-flight guard — double-click yields exactly one request.
			return null
		}
		if (!resolveDataRef(object, attachment)) {
			setError(key, 'generate-failed')
			return 'generate-failed'
		}
		setBusy(key, true)
		setError(key, null)
		return postDocument(key, documentRequest(attachment, object))
	}

	/**
	 * POST a generate request and deliver the document; record the typed error
	 * code on failure and always clear the busy flag.
	 *
	 * @param {string} key - state key.
	 * @param {{format: string, filename: string, body: object}} request - the request.
	 * @return {Promise<?string>} - null on success, error code on failure.
	 */
	async function postDocument(key, request) {
		try {
			const response = await client.post(generateUrl(GENERATE), request.body, {
				responseType: 'blob',
			})
			deliver(response, request.filename, request.format, doDownload)
			return null
		} catch (e) {
			const code = generateErrorCode(e)
			setError(key, code)
			return code
		} finally {
			setBusy(key, false)
		}
	}

	/**
	 * @param {string} key - state key.
	 * @param {boolean} value - busy value.
	 */
	function setBusy(key, value) {
		busy.value = { ...busy.value, [key]: value }
	}

	/**
	 * @param {string} key - state key.
	 * @param {?string} code - error code or null.
	 */
	function setError(key, code) {
		errorState.value = { ...errorState.value, [key]: code }
	}

	return { busyFor, errorFor, generate, renderFilename, resolveDataRef }
}

/**
 * The generate request for an attachment against an object: the resolved
 * format, the download filename and the POST body.
 *
 * @param {object} attachment - the `runtime.documents[]` entry.
 * @param {object} object - the OR object being viewed.
 * @return {{format: string, filename: string, body: object}}
 */
function documentRequest(attachment, object) {
	const format = attachment.format || 'pdf'
	const filename = buildFilename(attachment, object, format)
	const body = {
		templateId: attachment.templateId,
		dataRefs: [resolveDataRef(object, attachment)],
		options: { format },
		filename,
	}
	return { format, filename, body }
}

/**
 * Compose the download filename: rendered template, else the default
 * `<label>-<objectUuid>.<ext>` (REQ-DDT-001).
 *
 * @param {object} attachment - the attachment.
 * @param {object} object - the OR object.
 * @param {string} format - the resolved format.
 * @return {string}
 */
function buildFilename(attachment, object, format) {
	const rendered = renderFilename(attachment.filenameTemplate, object)
	if (rendered) {
		return rendered
	}
	const ref2 = resolveDataRef(object, attachment) || {}
	const ext = FORMAT_EXT[format] || 'pdf'
	const safeLabel = String(attachment.label || 'document').replace(
		/[^\w.-]+/g,
		'-',
	)
	return `${safeLabel}-${ref2.id || 'object'}.${ext}`
}

/**
 * Deliver a generate response as a browser download. Binary formats arrive as
 * a blob (the controller's DataDownloadResponse); html/email may arrive as a
 * JSON `{ content }` payload — wrapped into a text blob for download.
 *
 * @param {object} response - the axios response.
 * @param {string} filename - the download filename.
 * @param {string} format - the resolved format.
 * @param {Function} doDownload - the download sink.
 * @return {void}
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-003
 */
function deliver(response, filename, format, doDownload) {
	let blob = response && response.data
	const isBlob = typeof Blob !== 'undefined' && blob instanceof Blob
	if (!isBlob) {
		// JSON-content format (html/email): wrap the rendered content.
		const content = (blob && (blob.content || blob.html)) || ''
		blob = new Blob([content], { type: 'text/html' })
	}
	doDownload(blob, filename)
}

/**
 * Default download sink: create an object URL, click a synthetic anchor, then
 * revoke the URL.
 *
 * @param {Blob} blob - the document blob.
 * @param {string} filename - the download filename.
 * @return {void}
 */
function defaultDownload(blob, filename) {
	if (
		typeof document === 'undefined'
		|| typeof URL === 'undefined'
		|| !URL.createObjectURL
	) {
		return
	}
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = filename
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}
