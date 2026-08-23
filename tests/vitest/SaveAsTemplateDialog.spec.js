/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/SaveAsTemplateDialog.vue`.
 *
 * Covers:
 *   - REQ-SAT-001: happy create flow POSTs the captured record (isSeeded:false)
 *     to OR REST; metadata prefilled from the app.
 *   - REQ-SAT-002: de-namespace collision hard-blocks Save.
 *   - REQ-SAT-003: invalid captured manifest disables Save.
 *   - REQ-SAT-004: update-in-place against an own slug PUTs + bumps version.
 *   - REQ-SAT-004: seeded slug disables Save with the seeded-slug error.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const { validateMock } = vi.hoisted(() => ({
	validateMock: vi.fn(() => ({ valid: true, errors: [] })),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ validateManifest: validateMock }))

import SaveAsTemplateDialog from '../../src/dialogs/SaveAsTemplateDialog.vue'

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'type'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label'],
		template:
			'<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['value', 'label'],
		template:
			'<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['value', 'options'],
		template: '<select />',
	},
}

const app = {
	slug: 'my-permits',
	name: 'My permits',
	version: '0.3.0',
	description: 'Permits app',
}
const schemas = [
	{ slug: 'my-permits-permit-application', title: 'Permit', properties: {} },
]
const manifest = {
	pages: [{ id: 'idx', config: { schema: 'my-permits-permit-application' } }],
}

/**
 * @param {object} props prop overrides.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountDialog(props = {}) {
	return mount(SaveAsTemplateDialog, {
		propsData: {
			open: true,
			application: app,
			manifest,
			schemas,
			existingTemplates: [],
			...props,
		},
		stubs: STUBS,
	})
}

describe('SaveAsTemplateDialog.vue', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		axiosMock.put.mockReset()
		validateMock.mockReset()
		validateMock.mockReturnValue({ valid: true, errors: [] })
	})

	it('prefills metadata from the app and allows a valid create save', async () => {
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.form.title).toBe('My permits')
		expect(wrapper.vm.form.slug).toBe('my-permits')
		expect(wrapper.vm.canSave).toBe(true)

		axiosMock.post.mockResolvedValueOnce({ data: { uuid: 'tpl-new' } })
		await wrapper.vm.save()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.post.mock.calls[0]
		expect(url).toContain(
			'/apps/openregister/api/objects/buildiq/application-template',
		)
		expect(payload.isSeeded).toBe(false)
		expect(payload.slug).toBe('my-permits')
		expect(payload.companionSchemas[0].slug).toBe('permit-application')
		expect(payload.version).toBe('0.3.0')
		expect(wrapper.emitted('saved')[0][0]).toMatchObject({ mode: 'create' })
	})

	it('disables Save when the captured manifest is invalid (REQ-SAT-003)', async () => {
		validateMock.mockReturnValue({
			valid: false,
			errors: ['/pages/0: bad page type'],
		})
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.validationErrors).toHaveLength(1)
		expect(wrapper.vm.canSave).toBe(false)
	})

	it('hard-blocks Save on a de-namespace collision (REQ-SAT-002)', async () => {
		const collidingSchemas = [
			{ slug: 'my-permits-tasks', title: 'Tasks', properties: {} },
			{ slug: 'tasks', title: 'Shared', properties: {} },
		]
		const wrapper = mountDialog({ schemas: collidingSchemas })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.collisionError).toContain('my-permits-tasks')
		expect(wrapper.vm.canSave).toBe(false)
	})

	it('updates-in-place against an own org-local slug, bumping the version (REQ-SAT-004)', async () => {
		const existing = [
			{
				slug: 'my-permits',
				isSeeded: false,
				version: '1.0.0',
				'@self': { id: 'tpl-existing' },
			},
		]
		const wrapper = mountDialog({ existingTemplates: existing })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.updateMode).toBe(true)
		expect(wrapper.vm.canSave).toBe(true)

		axiosMock.put.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.save()

		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.put.mock.calls[0]
		expect(url).toContain('/application-template/tpl-existing')
		expect(payload.version).toBe('1.1.0')
		expect(wrapper.emitted('saved')[0][0]).toMatchObject({ mode: 'update' })
	})

	it('rejects a seeded slug with the seeded-slug error (REQ-SAT-004)', async () => {
		const existing = [{ slug: 'my-permits', isSeeded: true }]
		const wrapper = mountDialog({ existingTemplates: existing })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.slugError).toBe('seeded-slug')
		expect(wrapper.vm.canSave).toBe(false)
	})

	it('rejects a non-writable org-local slug with slug-taken (ownership guard)', async () => {
		const existing = [
			{ slug: 'my-permits', isSeeded: false, '@self': { canWrite: false } },
		]
		const wrapper = mountDialog({ existingTemplates: existing })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.slugError).toBe('slug-taken')
		expect(wrapper.vm.canSave).toBe(false)
	})
})
