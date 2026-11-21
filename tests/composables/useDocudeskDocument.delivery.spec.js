/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for how useDocudeskDocument delivers a generated document:
 * JSON-content responses wrapped as an HTML blob, the default filename for an
 * unlabelled or unknown-format attachment, and the built-in browser download
 * sink used when no sink is injected.
 *
 * Spec: docudesk-document-templates (REQ-DDT-001, REQ-DDT-003).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useDocudeskDocument } from '../../src/composables/useDocudeskDocument.js'

const attachment = {
	id: 'kap-confirm',
	schema: 'kapaanvraag',
	templateId: '11111111-2222-3333-4444-555555555555',
	label: 'Generate confirmation letter',
}

const object = {
	'@self': { id: 'abc-123', register: 'kap', schema: 'kapaanvraag' },
}

/**
 * Generate once with an injected sink and return what it received.
 *
 * @param {object} data The response body the client resolves with.
 * @param {object} [overrides] Attachment overrides.
 * @return {Promise<{blob: Blob, filename: string, body: object}>}
 */
async function generateWith(data, overrides = {}) {
	const client = { post: vi.fn().mockResolvedValue({ data }) }
	const download = vi.fn()
	const result = await useDocudeskDocument({ client, download }).generate({ ...attachment, ...overrides }, object)

	expect(result).toBeNull()
	expect(download).toHaveBeenCalledTimes(1)
	const [blob, filename] = download.mock.calls[0]
	return { blob, filename, body: client.post.mock.calls[0][1] }
}

describe('useDocudeskDocument — delivery', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('wraps a JSON content response into an HTML blob named after the format', async () => {
		const { blob, filename, body } = await generateWith({ content: '<p>Beste aanvrager</p>' }, { format: 'html' })

		expect(blob).toBeInstanceOf(Blob)
		expect(blob.type).toBe('text/html')
		expect(await blob.text()).toBe('<p>Beste aanvrager</p>')
		expect(filename).toBe('Generate-confirmation-letter-abc-123.html')
		expect(body.options).toEqual({ format: 'html' })
	})

	it('reads an html key when there is no content key, and names email as .html', async () => {
		const { blob, filename } = await generateWith({ html: '<h1>Mail</h1>' }, { format: 'email' })

		expect(await blob.text()).toBe('<h1>Mail</h1>')
		expect(filename).toBe('Generate-confirmation-letter-abc-123.html')
	})

	it('delivers an empty HTML blob for an empty response', async () => {
		const { blob } = await generateWith(null, { format: 'html' })

		expect(blob.type).toBe('text/html')
		expect(await blob.text()).toBe('')
	})

	it('names an unlabelled attachment "document" and an unknown format .pdf', async () => {
		const { filename, body } = await generateWith(new Blob(['X']), { label: '', format: 'odt' })

		expect(filename).toBe('document-abc-123.pdf')
		expect(body.options).toEqual({ format: 'odt' })
	})

	it('downloads through a temporary anchor and revokes its URL when no sink is injected', async () => {
		const createObjectURL = vi.fn(() => 'blob:docudesk-1')
		const revokeObjectURL = vi.fn()
		vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
		const clicked = []
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			clicked.push({ href: this.getAttribute('href'), download: this.download, attached: document.body.contains(this) })
		})
		const pdf = new Blob(['PDF'], { type: 'application/pdf' })
		const client = { post: vi.fn().mockResolvedValue({ data: pdf }) }

		const result = await useDocudeskDocument({ client }).generate(attachment, object)

		expect(result).toBeNull()
		expect(createObjectURL).toHaveBeenCalledWith(pdf)
		expect(clicked).toEqual([{ href: 'blob:docudesk-1', download: 'Generate-confirmation-letter-abc-123.pdf', attached: true }])
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:docudesk-1')
		expect(document.body.querySelector('a[download]')).toBeNull()
	})

	it('silently skips the download where the browser cannot create object URLs', async () => {
		vi.stubGlobal('URL', {})
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')
		const client = { post: vi.fn().mockResolvedValue({ data: new Blob(['PDF']) }) }
		const docs = useDocudeskDocument({ client })

		expect(await docs.generate(attachment, object)).toBeNull()
		expect(click).not.toHaveBeenCalled()
		expect(docs.errorFor(attachment, object)).toBeNull()
		expect(docs.busyFor(attachment, object)).toBe(false)
	})
})
