/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AiFieldEditor.vue: declaring an AI field on the staged
 * schema, writing its `origin: ai-field` automation after the schema saved,
 * removing it, and the disabled state without Hermiq's node.
 *
 * Spec: ai-llm-steps-and-computed-fields (REQ-BQLS-004, REQ-BQLS-005).
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AiFieldEditor from '../../../src/components/schema-editor/AiFieldEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'ariaLabel', 'title', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" :title="title" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input />' },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label'], template: '<textarea />' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel', 'multiple'], template: '<div />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Serve the node catalogue, the agents and the stored automations.
 *
 * @param {boolean} withNode Whether Hermiq's node is in the catalogue.
 * @param {Array<object>} automations The stored automations.
 * @return {void}
 */
function serve(withNode, automations = []) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/flow/node-catalog')) {
			return Promise.resolve({ data: { results: withNode ? [{ id: 'hermiq.agent-step' }] : [] } })
		}
		if (url.includes('/agent')) {
			return Promise.resolve({ data: { results: [{ uuid: 'ag-1', name: 'Writer', tools: [] }] } })
		}
		if (url.includes('/objects/buildiq/automation')) {
			return Promise.resolve({ data: { results: automations } })
		}
		return Promise.resolve({ data: { results: [] } })
	})
}

/**
 * Mount the editor for the `bezwaar` schema.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountEditor() {
	const wrapper = mount(AiFieldEditor, {
		props: { schemaSlug: 'bezwaar', applicationSlug: 'bezwaren', versionUuid: 'version-1', fieldNames: ['toelichting', 'naam'] },
		global: { stubs },
	})
	await flush()
	await flush()
	return wrapper
}

describe('AiFieldEditor', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
	})

	it('REQ-BQLS-004: a translate field is declared read-only and its automation is written after the save', async () => {
		serve(true)
		axios.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/compile') ? {} : { id: 'aut-ai-1' } }))
		const wrapper = await mountEditor()

		wrapper.vm.startDraft()
		wrapper.vm.setDraft('field', 'summary_en')
		wrapper.vm.setDraft('operation', 'translate')
		wrapper.vm.setDraft('sourceFields', ['toelichting'])
		wrapper.vm.setDraft('agent', 'ag-1')
		expect(wrapper.vm.draftProblem).toBe('Pick the language the AI step translates into.')
		wrapper.vm.setDraft('language', 'English')
		expect(wrapper.vm.draftProblem).toBe('')
		wrapper.vm.confirmDraft()

		expect(wrapper.emitted('declare')[0][0]).toEqual({
			name: 'summary_en',
			property: { type: 'string', readOnly: true, description: 'Filled in by the AI from toelichting.' },
		})
		expect(axios.post).not.toHaveBeenCalled()

		expect(await wrapper.vm.persist()).toBe(true)

		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/automations')
		expect(body).toMatchObject({
			slug: 'ai-field-bezwaar-summary-en',
			applicationSlug: 'bezwaren',
			versionUuid: 'version-1',
			origin: 'ai-field',
			trigger: { type: 'object-created', schema: 'bezwaar' },
			actions: [{ type: 'ai-step', preset: 'translate', agent: 'ag-1', sourceFields: ['toelichting'], targetField: 'summary_en', language: 'English' }],
		})
		expect(axios.post.mock.calls[1][0]).toBe('/apps/buildiq/api/automations/aut-ai-1/compile')
	})

	it('REQ-BQLS-004: categorise, clean and sentiment map onto their presets', async () => {
		serve(true)
		const wrapper = await mountEditor()
		const add = (field, operation, extra = {}) => {
			wrapper.vm.startDraft()
			wrapper.vm.draft = { ...wrapper.vm.draft, field, operation, sourceFields: ['toelichting'], agent: 'ag-1', ...extra }
			wrapper.vm.confirmDraft()
			return wrapper.vm.rows[wrapper.vm.rows.length - 1].step
		}

		expect(add('categorie', 'categorise', { labels: ['openbare ruimte', 'verkeer', 'afval'] })).toMatchObject({ preset: 'classify', labels: ['openbare ruimte', 'verkeer', 'afval'] })
		expect(wrapper.emitted('declare')[0][0].property.enum).toEqual(['openbare ruimte', 'verkeer', 'afval'])
		expect(add('omschrijving_schoon', 'clean')).toMatchObject({ preset: 'prompt', targetField: 'omschrijving_schoon' })
		expect(add('toon', 'sentiment')).toMatchObject({ preset: 'classify', labels: ['positive', 'neutral', 'negative'] })
		expect(wrapper.emitted('declare')[2][0].property.enum).toEqual(['positive', 'neutral', 'negative'])
		expect(wrapper.vm.visibleRows.map((row) => row.operation)).toEqual(['categorise', 'clean', 'sentiment'])
	})

	it('REQ-BQLS-004: a stored AI field is listed and removing it deletes its automation with the schema', async () => {
		serve(true, [
			{ id: 'aut-7', origin: 'ai-field', versionUuid: 'version-1', trigger: { type: 'object-created', schema: 'bezwaar' }, actions: [{ type: 'ai-step', preset: 'classify', labels: ['negative', 'neutral', 'positive'], sourceFields: ['toelichting'], targetField: 'toon' }] },
			{ id: 'aut-8', origin: 'ai-field', versionUuid: 'version-1', trigger: { type: 'object-created', schema: 'melding' }, actions: [{ type: 'ai-step', targetField: 'x' }] },
			{ id: 'aut-9', origin: 'composer', versionUuid: 'version-1', trigger: { type: 'object-created', schema: 'bezwaar' }, actions: [{ type: 'ai-step', targetField: 'y' }] },
		])
		axios.delete.mockResolvedValue({ data: {} })
		const wrapper = await mountEditor()

		expect(wrapper.vm.visibleRows.map((row) => [row.field, row.operation])).toEqual([['toon', 'sentiment']])

		wrapper.vm.removeRow(wrapper.vm.visibleRows[0])
		expect(wrapper.emitted('remove')[0]).toEqual(['toon'])
		expect(axios.delete).not.toHaveBeenCalled()

		await wrapper.vm.persist()
		expect(axios.delete).toHaveBeenCalledWith('/apps/buildiq/api/automations/aut-7')
		expect(wrapper.vm.rows).toEqual([])
	})

	it('a name already on the schema is refused, and a failed write says why', async () => {
		serve(true)
		const error = new Error('unprocessable')
		error.response = { status: 422, data: { detail: 'Make toon a choice list of your labels first.' } }
		axios.post.mockRejectedValue(error)
		const wrapper = await mountEditor()

		wrapper.vm.startDraft()
		wrapper.vm.draft = { ...wrapper.vm.draft, field: 'naam', operation: 'summarise', sourceFields: ['toelichting'], agent: 'ag-1' }
		expect(wrapper.vm.draftProblem).toBe('This schema already has a field naam.')
		wrapper.vm.setDraft('field', 'samenvatting')
		wrapper.vm.confirmDraft()

		expect(await wrapper.vm.persist()).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('Make toon a choice list of your labels first.')
	})

	it('REQ-BQLS-005: without Hermiq the add button is disabled and says why', async () => {
		serve(false)
		const wrapper = await mountEditor()

		const button = wrapper.find('[data-testid="ai-field-add"]')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.attributes('title')).toBe('Install Hermiq to use AI steps.')
		expect(wrapper.find('[data-testid="ai-field-no-node"]').text()).toBe('Install Hermiq to use AI steps.')
	})
})
