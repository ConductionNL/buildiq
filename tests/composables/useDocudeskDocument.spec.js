/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for useDocudeskDocument — runtime generate + download.
 *
 * Spec: docudesk-document-templates (REQ-DDT-003).
 */
import { describe, expect, it, vi } from 'vitest'
import {
	renderFilename,
	resolveDataRef,
	useDocudeskDocument,
} from '../../src/composables/useDocudeskDocument.js'

const UUID = '11111111-2222-3333-4444-555555555555'

const attachment = {
	id: 'kap-confirm',
	schema: 'kapaanvraag',
	templateId: UUID,
	templateName: 'Bevestigingsbrief',
	label: 'Generate confirmation letter',
}

const object = {
	'@self': { id: 'abc-123', register: 'kap', schema: 'kapaanvraag' },
	dossiernummer: '2026-0042',
}

describe('renderFilename', () => {
	it('interpolates object properties', () => {
		expect(renderFilename('bevestiging-{{dossiernummer}}.pdf', object)).toBe(
			'bevestiging-2026-0042.pdf',
		)
	})
	it('renders missing properties as empty', () => {
		expect(renderFilename('x-{{nope}}.pdf', object)).toBe('x-.pdf')
	})
	it('returns empty for an empty template', () => {
		expect(renderFilename('', object)).toBe('')
	})
})

describe('resolveDataRef', () => {
	it('resolves register/schema/id from the @self envelope', () => {
		expect(resolveDataRef(object, attachment)).toEqual({
			register: 'kap',
			schema: 'kapaanvraag',
			id: 'abc-123',
		})
	})
	it('returns null when the id is missing', () => {
		expect(
			resolveDataRef(
				{ '@self': { register: 'kap', schema: 'kapaanvraag' } },
				attachment,
			),
		).toBeNull()
	})
})

describe('useDocudeskDocument', () => {
	const blobResponse = () => ({
		data: new Blob(['PDF'], { type: 'application/pdf' }),
	})

	it('sends the correct request shape and downloads', async () => {
		const client = { post: vi.fn().mockResolvedValue(blobResponse()) }
		const download = vi.fn()
		const docs = useDocudeskDocument({ client, download })
		const result = await docs.generate(attachment, object)

		expect(result).toBeNull()
		expect(client.post).toHaveBeenCalledTimes(1)
		const [, body, opts] = client.post.mock.calls[0]
		expect(body.templateId).toBe(UUID)
		expect(body.dataRefs).toEqual([
			{ register: 'kap', schema: 'kapaanvraag', id: 'abc-123' },
		])
		expect(body.options.format).toBe('pdf')
		expect(opts.responseType).toBe('blob')
		expect(download).toHaveBeenCalledTimes(1)
	})

	it('renders the filename template into the download name', async () => {
		const client = { post: vi.fn().mockResolvedValue(blobResponse()) }
		const download = vi.fn()
		const docs = useDocudeskDocument({ client, download })
		await docs.generate(
			{ ...attachment, filenameTemplate: 'bevestiging-{{dossiernummer}}.pdf' },
			object,
		)
		const [body] = [client.post.mock.calls[0][1]]
		expect(body.filename).toBe('bevestiging-2026-0042.pdf')
	})

	it('defaults the filename to <label>-<id>.<ext>', async () => {
		const client = { post: vi.fn().mockResolvedValue(blobResponse()) }
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		await docs.generate(attachment, object)
		const body = client.post.mock.calls[0][1]
		expect(body.filename).toBe('Generate-confirmation-letter-abc-123.pdf')
	})

	it('maps a 403 to the no-access error code', async () => {
		const client = {
			post: vi.fn().mockRejectedValue({ response: { status: 403 } }),
		}
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		const result = await docs.generate(attachment, object)
		expect(result).toBe('no-access')
		expect(docs.errorFor(attachment, object)).toBe('no-access')
	})

	it('maps other failures to generate-failed', async () => {
		const client = {
			post: vi.fn().mockRejectedValue({ response: { status: 500 } }),
		}
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		const result = await docs.generate(attachment, object)
		expect(result).toBe('generate-failed')
	})

	it('guards against double-click: two rapid calls issue one request', async () => {
		let resolve
		const client = {
			post: vi.fn().mockImplementation(
				() =>
					new Promise((r) => {
						resolve = r
					}),
			),
		}
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		const p1 = docs.generate(attachment, object)
		const p2 = docs.generate(attachment, object)
		resolve(blobResponse())
		await Promise.all([p1, p2])
		expect(client.post).toHaveBeenCalledTimes(1)
	})

	it('never mutates the object', async () => {
		const client = { post: vi.fn().mockResolvedValue(blobResponse()) }
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		const snapshot = JSON.stringify(object)
		await docs.generate(attachment, object)
		expect(JSON.stringify(object)).toBe(snapshot)
	})
})

describe('resolveDataRef fallbacks', () => {
	it('falls back to top-level register, schema, uuid and id', () => {
		expect(
			resolveDataRef({ register: 'r', schema: 's', uuid: 'u', id: 'i' }, null),
		).toEqual({ register: 'r', schema: 's', id: 'u' })
		expect(resolveDataRef({ register: 'r', schema: 's', id: 'i' }, {})).toEqual({
			register: 'r',
			schema: 's',
			id: 'i',
		})
	})
	it('falls back to the attachment schema and keeps a register of 0', () => {
		expect(resolveDataRef({ '@self': { register: 0, id: 'x' } }, attachment)).toEqual(
			{ register: 0, schema: 'kapaanvraag', id: 'x' },
		)
	})
	it('prefers @self over the top level, even when @self is falsy-but-set', () => {
		expect(
			resolveDataRef(
				{ '@self': { register: 'a', schema: 's1', id: 'x' }, register: 'b', schema: 's2' },
				attachment,
			),
		).toEqual({ register: 'a', schema: 's1', id: 'x' })
	})
	it('returns null without a register or a schema', () => {
		expect(resolveDataRef({ '@self': { register: null, schema: 's', id: 'x' } }, {})).toBeNull()
		expect(resolveDataRef({ '@self': { schema: 's', id: 'x' } }, {})).toBeNull()
		expect(resolveDataRef({ '@self': { register: 'r', id: 'x' } }, {})).toBeNull()
		expect(resolveDataRef({ '@self': { register: 'r', schema: '', id: 'x' } }, null)).toBeNull()
	})
	it('throws on a missing object', () => {
		expect(() => resolveDataRef(null, attachment)).toThrow(TypeError)
	})
})

describe('useDocudeskDocument generate outcomes', () => {
	const blobResponse = () => ({
		data: new Blob(['PDF'], { type: 'application/pdf' }),
	})

	it('fails without a request when the object reference cannot be resolved', async () => {
		const client = { post: vi.fn() }
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		const bare = { '@self': { register: 'kap' } }
		await expect(docs.generate(attachment, bare)).resolves.toBe('generate-failed')
		expect(client.post).not.toHaveBeenCalled()
		expect(docs.errorFor(attachment, bare)).toBe('generate-failed')
		expect(docs.busyFor(attachment, bare)).toBe(false)
	})

	it('sends the attachment format and names the download after it', async () => {
		const client = { post: vi.fn().mockResolvedValue(blobResponse()) }
		const download = vi.fn()
		const docs = useDocudeskDocument({ client, download })
		const result = await docs.generate({ ...attachment, format: 'docx' }, object)
		expect(result).toBeNull()
		const [url, body] = client.post.mock.calls[0]
		expect(url).toBe('//index.php/apps/filinq/api/correspondence/generate')
		expect(body).toEqual({
			templateId: UUID,
			dataRefs: [{ register: 'kap', schema: 'kapaanvraag', id: 'abc-123' }],
			options: { format: 'docx' },
			filename: 'Generate-confirmation-letter-abc-123.docx',
		})
		expect(download).toHaveBeenCalledTimes(1)
		expect(download.mock.calls[0][1]).toBe(
			'Generate-confirmation-letter-abc-123.docx',
		)
	})

	it('clears the busy flag and a previous error after a later success', async () => {
		const client = {
			post: vi
				.fn()
				.mockRejectedValueOnce(undefined)
				.mockResolvedValueOnce({ data: { content: '<p>x</p>' } }),
		}
		const download = vi.fn()
		const docs = useDocudeskDocument({ client, download })
		await expect(docs.generate(attachment, object)).resolves.toBe('generate-failed')
		expect(docs.errorFor(attachment, object)).toBe('generate-failed')
		expect(docs.busyFor(attachment, object)).toBe(false)
		await expect(docs.generate(attachment, object)).resolves.toBeNull()
		expect(docs.errorFor(attachment, object)).toBeNull()
		expect(download.mock.calls[0][0]).toBeInstanceOf(Blob)
	})

	it('maps a failure without a response to generate-failed', async () => {
		const client = { post: vi.fn().mockRejectedValue(new Error('net')) }
		const docs = useDocudeskDocument({ client, download: vi.fn() })
		await expect(docs.generate(attachment, object)).resolves.toBe('generate-failed')
	})
})
