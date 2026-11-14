import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CopilotGenerateDialog.vue
 * (spec ai-copilot REQ-OBAIC-001/006).
 *
 * Covers: brief -> review -> confirm emits `created`; cancel sends no
 * execute request; the health probe reaching 503 keeps the dialog usable
 * for its own local error state (the "button hidden + admin hint" scenario
 * lives on Step1Basics, covered separately).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const pickRequirementsDocument = vi.fn()
vi.mock('../../src/services/documentPicker.js', () => ({
	pickRequirementsDocument: (...a) => pickRequirementsDocument(...a),
}))

import CopilotGenerateDialog from '../../src/dialogs/CopilotGenerateDialog.vue'
import { clearCopilotHealthCache } from '../../src/composables/useCopilot.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

// `NcModal` in @nextcloud/vue 9 renders its body through `<Teleport to="body">`,
// so under VTU v2 none of the dialog's markup lives inside `wrapper.element`
// and every `wrapper.find(...)` comes back empty. Stubbing the modal keeps the
// content in-tree, which is what the sibling dialog specs here already do.
//
// `emits: ['click']` on the button stub is likewise load-bearing: without it
// Vue 3 leaves the parent's `@click` in `$attrs`, it falls through onto the
// root <button>, and one click fires the handler twice. The real NcButton
// declares `emits: ['click', 'update:pressed']`.
const stubs = {
	NcModal: {
		name: 'NcModal',
		props: ['name', 'canClose'],
		template: '<div class="ncmodal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled || false" :data-type="type" @click="$emit(\'click\')"><slot /></button>',
	},
	// Vue 3 model API: `modelValue` in, `update:modelValue` out.
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'rows'],
		emits: ['update:modelValue'],
		template:
			'<textarea class="nctextarea-stub" :value="modelValue" :disabled="disabled || false" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

describe('CopilotGenerateDialog.vue — spec ai-copilot REQ-OBAIC-001/006', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		clearCopilotHealthCache()
	})

	it('renders nothing when open is false', () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: false },
			stubs,
		})
		expect(wrapper.find('[data-testid="copilot-brief-input"]').exists()).toBe(
			false,
		)
	})

	it('brief -> review -> confirm emits created with the app slug', async () => {
		axiosPost
			.mockResolvedValueOnce({
				data: {
					summary: 'A tool library',
					steps: [
						{
							tool: 'buildiq.createApp',
							arguments: {
								slug: 'tool-library',
								name: 'Tool Library',
							},
						},
					],
					manifests: {},
				},
			})
			.mockResolvedValueOnce({
				data: {
					results: [
						{
							success: true,
							created: true,
							app: {
								uuid: 'u1',
								slug: 'tool-library',
								name: 'Tool Library',
							},
						},
					],
				},
			})

		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper
			.find('[data-testid="copilot-brief-input"]')
			.setValue('A tool library where members borrow tools')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="copilot-plan-review"]').exists()).toBe(
			true,
		)

		await wrapper.vm.onConfirm()
		await flush()

		expect(wrapper.emitted('created')).toBeTruthy()
		expect(wrapper.emitted('created')[0][0]).toBe('tool-library')
		expect(wrapper.emitted('update:open')).toBeTruthy()
		expect(wrapper.emitted('update:open')[0][0]).toBe(false)
	})

	it('cancel sends no execute request', async () => {
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'x',
				steps: [
					{
						tool: 'buildiq.createApp',
						arguments: { slug: 'x', name: 'X' },
					},
				],
				manifests: {},
			},
		})

		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('x')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		axiosPost.mockClear()
		await wrapper.find('[data-testid="copilot-cancel"]').trigger('click')

		expect(axiosPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('created')).toBeFalsy()
		expect(wrapper.emitted('update:open')[0][0]).toBe(false)
	})

	it('names the fields that failed instead of blaming the brief', async () => {
		// A plan whose predicted manifest carries a widget with no id and no
		// title: exactly what the builder tools used to append. The dialog
		// used to answer this with "Try rephrasing your brief", which sent
		// the reader after a fault that was never theirs.
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'A tool library',
				steps: [
					{
						tool: 'buildiq.addWidget',
						arguments: { pageId: 'overview', widgetType: 'stat' },
					},
				],
				manifests: {
					'tool-library@development': {
						current: { version: '1.0.0', menu: [], pages: [] },
						predicted: {
							version: '1.0.0',
							menu: [],
							pages: [
								{
									id: 'overview',
									route: '/',
									type: 'dashboard',
									title: 'Overview',
									config: {
										widgets: [{ type: 'stat', config: {} }],
									},
								},
							],
						},
					},
				},
			},
		})

		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('x')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canApprove).toBe(false)

		const block = wrapper.find('[data-testid="copilot-validation-errors"]')
		expect(block.exists()).toBe(true)
		expect(block.text()).not.toContain('rephrasing')
		expect(block.text()).toContain('/pages/0/config/widgets/0/id')
		expect(block.text()).toContain('/pages/0/config/widgets/0/title')
		// The version key is an internal record id and stays off the screen.
		expect(block.text()).not.toContain('tool-library@development')
	})

	it('Generate is disabled with a blank brief', () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		// VTU v2 `findAll` returns a plain Array — v1's `.wrappers` is gone.
		const generateButton = wrapper
			.findAll('button')
			.find((b) => b.text().includes('Generate'))
		// Vue 3 renders a true boolean attribute as `disabled=""` — a falsy
		// empty string — so presence is the signal, not truthiness.
		expect(generateButton.attributes('disabled')).toBeDefined()
	})

	it('Generate is enabled once a brief is entered', async () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper
			.find('[data-testid="copilot-brief-input"]')
			.setValue('A tool library')
		const generateButton = wrapper
			.findAll('button')
			.find((b) => b.text().includes('Generate'))
		expect(generateButton.attributes('disabled')).toBeUndefined()
	})
})

describe('CopilotGenerateDialog.vue — Add a document (ai-copilot-documents-and-code-help REQ-BQCH-005)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		pickRequirementsDocument.mockReset()
		clearCopilotHealthCache()
	})

	const mountOpen = () => mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })

	it('picks a document, shows its name under the brief, and sends only its id', async () => {
		pickRequirementsDocument.mockResolvedValue({ id: 42, name: 'Eisen tuinvergunning.md' })
		axiosPost.mockResolvedValueOnce({ data: { summary: 'Garden permits', steps: [], manifests: {} } })
		const wrapper = mountOpen()

		await wrapper.find('[data-testid="copilot-document-add"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="copilot-document-name"]').text()).toBe('Eisen tuinvergunning.md')
		expect(wrapper.vm.canGenerate).toBe(true)

		await wrapper.vm.onGenerate()
		await flush()

		const [url, body] = axiosPost.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/copilot/plan')
		expect(body).toEqual({ brief: '', appSlug: undefined, agentId: undefined, versionSlug: undefined, documentFileId: 42 })
	})

	it('keeps Generate disabled with neither a brief nor a document', () => {
		const wrapper = mountOpen()
		expect(wrapper.vm.canGenerate).toBe(false)
	})

	it('a closed picker leaves the dialog as it was', async () => {
		pickRequirementsDocument.mockResolvedValue(null)
		const wrapper = mountOpen()

		await wrapper.find('[data-testid="copilot-document-add"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="copilot-document-name"]').exists()).toBe(false)
		expect(wrapper.vm.document).toBeNull()
	})

	it('removes the document, and forgets it when reopened', async () => {
		pickRequirementsDocument.mockResolvedValue({ id: 7, name: 'Eisen.txt' })
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		await wrapper.find('[data-testid="copilot-document-add"]').trigger('click')
		await flush()

		await wrapper.find('[data-testid="copilot-document-remove"]').trigger('click')
		expect(wrapper.vm.document).toBeNull()

		await wrapper.find('[data-testid="copilot-document-add"]').trigger('click')
		await flush()
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.document).toBeNull()
	})

	it('shows the server refusal for an unsupported or oversized document', async () => {
		pickRequirementsDocument.mockResolvedValue({ id: 9, name: 'Eisen.pdf' })
		axiosPost.mockRejectedValueOnce({
			response: { status: 422, data: { error: 'unsupported_document', message: 'This document type cannot be read yet. Use a plain text (.txt) or Markdown (.md) file.' } },
		})
		const wrapper = mountOpen()
		await wrapper.find('[data-testid="copilot-document-add"]').trigger('click')
		await flush()

		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[role="alert"]').text()).toBe('This document type cannot be read yet. Use a plain text (.txt) or Markdown (.md) file.')
	})
})

describe('CopilotGenerateDialog.vue — User stories (ai-copilot-documents-and-code-help REQ-BQCH-006)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		clearCopilotHealthCache()
	})

	it('lists the stories first, each with the schemas and pages that serve it', async () => {
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'Garden permits',
				stories: [
					{ id: 's1', role: 'an applicant', goal: 'track my permit', servedBy: [1, 2, 3] },
					{ id: 's2', role: 'a reviewer', goal: '', servedBy: [] },
				],
				steps: [
					{ tool: 'buildiq.createApp', arguments: { slug: 'tuin', name: 'Tuin' } },
					{ tool: 'buildiq.upsertSchema', arguments: { slug: 'aanvraag', title: 'Aanvraag' } },
					{ tool: 'buildiq.upsertPage', arguments: { pageId: 'mine', title: 'Mijn aanvragen', type: 'index' } },
					{ tool: 'buildiq.upsertMenuItem', arguments: { label: 'Mijn aanvragen' } },
				],
				manifests: {},
			},
		})
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('Garden permits')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		const groups = wrapper.findAll('.copilot-generate__group h3').map((h) => h.text())
		expect(groups[0]).toBe('User stories')
		const stories = wrapper.findAll('[data-testid="copilot-plan-story"]')
		expect(stories).toHaveLength(1)
		expect(wrapper.vm.stories).toEqual([{ text: 'As {role} I want to {goal}', servedBy: ['Aanvraag', 'Mijn aanvragen'] }])
		expect(stories[0].findAll('.copilot-generate__served-by li').map((li) => li.text())).toEqual(['Aanvraag', 'Mijn aanvragen'])
	})

	it('shows no stories group for a plan without stories', async () => {
		axiosPost.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'buildiq.createApp', arguments: { slug: 'x', name: 'X' } }], manifests: {} } })
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('x')
		await wrapper.vm.onGenerate()
		await flush()

		expect(wrapper.find('[data-testid="copilot-plan-stories"]').exists()).toBe(false)
	})

	it('writes a story with a benefit as one sentence', () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		expect(wrapper.vm.storySentence({ role: 'r', goal: 'g', benefit: 'b' })).toBe('As {role} I want to {goal}, so that {benefit}')
	})
})
