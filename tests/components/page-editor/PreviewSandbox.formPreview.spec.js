/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for previewing a draft form page
 * (capability-requirements-backfill T25, REQ-BQCV-025).
 *
 * What buildiq owns, and so what is asserted here:
 *  - the designer hands its preview the page types the published app uses
 *    (`builder.js`), so a form page previews as buildiq's form page, with its
 *    calculated fields and eligibility check, not as the bare library page;
 *  - the preview shell gets the draft form exactly as the maker wrote it:
 *    its fields, labels, conditional field and submit label;
 *  - the preview takes input but swallows submit, so filling the form in and
 *    pressing submit saves no record, and nothing is written to the version.
 *
 * Drawing the fields and evaluating `visibleWhen` as the first field is filled
 * in is nextcloud-vue's CnFormPage, the same component the published form
 * renders, and is not tested again here (the library is stubbed in Vitest).
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-page-is-previewed-as-its-users-see-it-before-it-is-published-req-bqcv-025
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ref, toRaw } from 'vue'

const requests = []
vi.mock('@nextcloud/axios', () => {
	const record = (method) => async (url) => {
		requests.push({ method, url })
		return { data: { results: [] } }
	}
	return {
		default: {
			get: record('get'),
			post: record('post'),
			put: record('put'),
			patch: record('patch'),
			delete: record('delete'),
		},
	}
})

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key) => key,
}))

vi.mock('../../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({ available: ref(false), previewProps: () => null }),
}))

const PreviewSandbox = (await import('../../../src/components/page-editor/PreviewSandbox.vue')).default
const BuildiqFormPage = (await import('../../../src/components/runtime/BuildiqFormPage.vue')).default
const PageDesigner = (await import('../../../src/views/PageDesigner.vue')).default

const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'string' },
	{ key: 'bedrijf', label: 'Bedrijfsnaam', type: 'string', visibleWhen: { field: 'naam', op: 'notEmpty' } },
	{ key: 'toelichting', label: 'Toelichting', type: 'textarea' },
]

const FORM_PAGE = {
	id: 'aanvraag',
	type: 'form',
	route: '/',
	title: 'Aanvraag',
	config: {
		mode: 'create',
		submitEndpoint: '/api/objects/vergunningen/aanvraag',
		submitLabel: 'Aanvraag indienen',
		fields: FIELDS,
	},
}

const MANIFEST = { version: '1.0.0', pages: [FORM_PAGE], menu: [] }

let wrapper = null

/**
 * Mount the preview of the draft form with the designer's page types.
 *
 * @return {Promise<object>} the wrapper.
 */
async function preview() {
	wrapper = mount(PreviewSandbox, {
		props: {
			appId: 'openbuild-preview-vergunningen',
			manifest: MANIFEST,
			pageTypes: PageDesigner.computed.previewPageTypes.call({}),
		},
		attachTo: document.body,
	})
	await flushPromises()
	return wrapper
}

/**
 * The props the preview's shell (CnAppRoot) was rendered with.
 *
 * @return {object}
 */
function shellProps() {
	return wrapper.vm._sandboxState
}

/**
 * Put a form shaped like the one CnFormPage draws into the preview host: a
 * text field, a submit button of type `submit`, and a listener standing in for
 * CnFormPage's `@submit.prevent="submit"`, which is what POSTs the record.
 *
 * @param {Function} onSubmit - called when the form would submit.
 * @return {{input: HTMLInputElement, button: HTMLButtonElement}}
 */
function renderedForm(onSubmit) {
	const form = document.createElement('form')
	form.className = 'cn-form-page__form'
	form.addEventListener('submit', (event) => {
		event.preventDefault()
		onSubmit()
	})
	const input = document.createElement('input')
	input.type = 'text'
	const button = document.createElement('button')
	button.type = 'submit'
	button.className = 'cn-form-page__primary'
	button.textContent = 'Aanvraag indienen'
	form.append(input, button)
	wrapper.element.appendChild(form)
	return { input, button }
}

describe('A draft form page previews as its users see it (REQ-BQCV-025)', () => {
	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		requests.length = 0
	})

	it('the designer previews a form page with the form page type the published app uses', () => {
		const pageTypes = PageDesigner.computed.previewPageTypes.call({})
		expect(pageTypes.form).toBe(BuildiqFormPage)
	})

	it('hands the preview shell the form page type and the draft form as written', async () => {
		await preview()
		const props = shellProps()
		expect(toRaw(props.pageTypes.form)).toBe(BuildiqFormPage)
		const page = props.manifest.pages[0]
		expect(page.type).toBe('form')
		expect(page.config.fields).toEqual(FIELDS)
		expect(page.config.fields[1].visibleWhen).toEqual({ field: 'naam', op: 'notEmpty' })
		expect(page.config.submitLabel).toBe('Aanvraag indienen')
		// The preview is not an editor: no Buildiq edit button in it.
		expect(props.manifest.openbuildEditable).toBe(false)
		// The form route is the preview's landing page.
		expect(wrapper.vm._sandboxRouter.currentRoute.value.name).toBe('aanvraag')
	})

	it('lets the maker fill in a field', async () => {
		await preview()
		const { input } = renderedForm(vi.fn())
		const typed = vi.fn()
		input.addEventListener('input', typed)
		input.addEventListener('click', typed)
		input.click()
		input.value = 'Jan'
		input.dispatchEvent(new Event('input', { bubbles: true }))
		expect(typed).toHaveBeenCalledTimes(2)
		expect(input.value).toBe('Jan')
	})

	it('saves no record and writes nothing to the version when the maker presses submit', async () => {
		await preview()
		const submitted = vi.fn()
		const { input, button } = renderedForm(submitted)
		input.value = 'Jan'
		input.dispatchEvent(new Event('input', { bubbles: true }))
		button.click()
		button.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
		await flushPromises()

		expect(submitted).not.toHaveBeenCalled()
		expect(requests.filter((request) => request.method !== 'get')).toEqual([])
		expect(wrapper.emitted()).not.toHaveProperty('update:manifest')
	})
})
