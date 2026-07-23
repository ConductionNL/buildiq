/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the document-template preview sanitization
 * (harden-xss-dos-csrf, docudesk-document-templates). The preview is authored
 * in a (possibly shared) Docudesk template and rendered via v-html in this
 * user's session, so it MUST be DOMPurify-sanitized before binding.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const axiosPostMock = vi.fn()
const axiosGetMock = vi.fn().mockResolvedValue({ data: {} })
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (...args) => axiosPostMock(...args),
		get: (...args) => axiosGetMock(...args),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

const baseStubs = {
	NcDialog: { name: 'NcDialog', props: ['name', 'open'], template: '<div><slot /></div>' },
	NcButton: { name: 'NcButton', props: ['disabled', 'type'], template: '<button><slot /></button>' },
	NcSelect: { name: 'NcSelect', props: ['inputLabel', 'options', 'disabled'], template: '<div class="nc-select-stub" />' },
	NcTextField: { name: 'NcTextField', props: ['value', 'label'], template: '<input />' },
}

const DocumentTemplateAttachmentDialog = (await import('../../src/dialogs/DocumentTemplateAttachmentDialog.vue')).default

async function previewWith(payload) {
	const wrapper = mount(DocumentTemplateAttachmentDialog, {
		propsData: { docudeskAvailable: true },
		stubs: baseStubs,
	})
	// A resolvable template so canPreview is true (mounted with open=false, so
	// the fetchTemplates watcher never fires).
	await wrapper.setData({ templateOption: { uuid: 't1', label: 'T', name: 'T' } })
	axiosPostMock.mockResolvedValue({ data: payload })
	await wrapper.vm.onPreview()
	return wrapper.vm.previewContent
}

describe('DocumentTemplateAttachmentDialog — preview sanitization', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
	})

	it('strips a <script> element from the rendered preview', async () => {
		const out = await previewWith({ html: '<p>ok</p><script>alert(1)</script>' })
		expect(out).toContain('<p>ok</p>')
		expect(out.toLowerCase()).not.toContain('<script')
	})

	it('strips event-handler attributes from the preview', async () => {
		const out = await previewWith({ html: '<img src="x" onerror="alert(1)">' })
		expect(out.toLowerCase()).not.toContain('onerror')
	})

	it('preserves benign formatting markup', async () => {
		const out = await previewWith({ content: '<h2>Title</h2><ul><li>a</li></ul>' })
		expect(out).toContain('<h2>Title</h2>')
		expect(out).toContain('<li>a</li>')
	})
})
