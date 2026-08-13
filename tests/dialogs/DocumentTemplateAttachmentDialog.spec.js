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
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		template: '<div><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'type'],
		template: '<button><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'options', 'disabled'],
		template: '<div class="nc-select-stub" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label'],
		template: '<input />',
	},
}

const DocumentTemplateAttachmentDialog = (
	await import('../../src/dialogs/DocumentTemplateAttachmentDialog.vue')
).default

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
		const out = await previewWith({
			content: '<h2>Title</h2><ul><li>a</li></ul>',
		})
		expect(out).toContain('<h2>Title</h2>')
		expect(out).toContain('<li>a</li>')
	})
})

/**
 * The two `.ob-document-attach__warn` paragraphs are mutually exclusive claims
 * about the same subject: "Docudesk is not installed" and "the template you
 * attached no longer exists IN Docudesk". The second is not knowable when the
 * first is true.
 *
 * They were authored as two independent `v-if`s, and both rendered together
 * whenever `docudeskAvailable` arrived late — the normal case, since
 * PageDesignerHost initialises it to `true` and resolves the real value
 * asynchronously. The dialog's `open` watcher then ran its snapshot refresh,
 * took a 404 from a route that does not exist, set `templateMissing`, and the
 * late `false` stacked the absence message on top.
 *
 * Playwright saw it as `strict mode violation: locator('.ob-document-attach__warn')
 * resolved to 2 elements` in run 31083894467. These assertions pin the
 * invariant directly so the next regression is caught in milliseconds by the
 * unit suite instead of in a 19-minute browser run.
 */
describe('DocumentTemplateAttachmentDialog — mutually exclusive warnings', () => {
	/**
	 * Mount the dialog and drive it into the both-warnings-eligible state.
	 *
	 * @param {boolean} docudeskAvailable Value of the capability prop.
	 * @return {Promise<object>} The mounted wrapper.
	 */
	async function warnState(docudeskAvailable) {
		const wrapper = mount(DocumentTemplateAttachmentDialog, {
			propsData: { docudeskAvailable },
			stubs: baseStubs,
		})
		// `templateMissing` is what a 404 from the snapshot refresh sets.
		await wrapper.setData({ templateMissing: true })
		return wrapper
	}

	it('renders exactly one warning when Docudesk is absent AND a template 404d', async () => {
		const wrapper = await warnState(false)
		const warns = wrapper.findAll('.ob-document-attach__warn')
		expect(warns).toHaveLength(1)
		expect(warns[0].text()).toContain('not installed')
	})

	it('renders the deleted-template warning when Docudesk IS available', async () => {
		const wrapper = await warnState(true)
		const warns = wrapper.findAll('.ob-document-attach__warn')
		expect(warns).toHaveLength(1)
		expect(warns[0].text()).toContain('no longer exists')
	})
})
