/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MergeVersionDialog (lifecycle-git-branches T06): previews the merge, lists
 * each conflict with a side to pick, keeps "Merge" off until every conflict
 * has a side and a breaking change is confirmed, sends the choices with the
 * previewed hash, and offers a new preview when the target moved.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) => p.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))

import axios from '@nextcloud/axios'
import MergeVersionDialog from '../../src/dialogs/MergeVersionDialog.vue'

const stubs = {
	NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<select data-test="merge-target" />' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label class="choice" @click="$emit(\'update:modelValue\', value === undefined ? !modelValue : value)"><slot /></label>',
	},
	ManifestDiff: { props: ['fromManifest', 'toManifest'], template: '<pre class="diff" />' },
}

const URL = '/apps/buildiq/api/applications/vergunningen/versions/permit-fees/merge/development'

/**
 * Mount the dialog with `development` chosen as target.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountDialog() {
	const wrapper = mount(MergeVersionDialog, {
		props: {
			open: true,
			appSlug: 'vergunningen',
			source: { slug: 'permit-fees', name: 'permit-fees' },
			targets: [{ slug: 'development', name: 'Development' }],
		},
		global: { stubs },
	})
	wrapper.findComponent(stubs.NcSelect).vm.$emit('update:modelValue', { id: 'development', label: 'Development' })
	await flushPromises()
	return wrapper
}

const mergeButton = (wrapper) => wrapper.find('[data-test="merge-apply"]')

describe('MergeVersionDialog', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('previews without writing and merges a clean branch', async () => {
		axios.post
			.mockResolvedValueOnce({ data: { conflicts: [], breaking: [], targetHash: 'h1' } })
			.mockResolvedValueOnce({ data: { slug: 'development' } })
		const wrapper = await mountDialog()

		await wrapper.find('[data-test="merge-preview"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(URL, { preview: true })
		expect(wrapper.find('[data-test="merge-clean"]').exists()).toBe(true)
		expect(mergeButton(wrapper).attributes('disabled')).toBeUndefined()

		await mergeButton(wrapper).trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith(URL, { choices: {}, targetHash: 'h1', acknowledgeBreaking: false })
		expect(wrapper.emitted('merged')).toEqual([[{ slug: 'development' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('keeps Merge off until every conflict has a side, then sends the sides', async () => {
		axios.post
			.mockResolvedValueOnce({
				data: {
					conflicts: [{ unit: 'page:permits', kind: 'page', key: 'permits', base: {}, source: { columns: ['fee'] }, target: { columns: ['status'] } }],
					breaking: [],
					targetHash: 'h1',
				},
			})
			.mockResolvedValueOnce({ data: {} })
		const wrapper = await mountDialog()
		await wrapper.find('[data-test="merge-preview"]').trigger('click')
		await flushPromises()

		const conflict = wrapper.find('[data-test="merge-conflict"]')
		expect(conflict.find('h4').text()).toMatch(/^Page/)
		const diff = wrapper.findComponent(stubs.ManifestDiff)
		expect(diff.props('fromManifest')).toEqual({ permits: { columns: ['status'] } })
		expect(diff.props('toManifest')).toEqual({ permits: { columns: ['fee'] } })
		expect(mergeButton(wrapper).attributes('disabled')).toBeDefined()

		const [, takeBranch] = conflict.findAll('.choice')
		await takeBranch.trigger('click')
		expect(mergeButton(wrapper).attributes('disabled')).toBeUndefined()

		await mergeButton(wrapper).trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith(URL, { choices: { 'page:permits': 'source' }, targetHash: 'h1', acknowledgeBreaking: false })
	})

	it('asks to confirm a breaking schema change before merging', async () => {
		axios.post.mockResolvedValueOnce({ data: { conflicts: [], breaking: ['The field "permit.fee" is removed.'], targetHash: 'h1' } })
		const wrapper = await mountDialog()
		await wrapper.find('[data-test="merge-preview"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The field "permit.fee" is removed.')
		expect(mergeButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-test="merge-acknowledge"]').trigger('click')
		expect(mergeButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('offers a new preview when the target changed after the preview', async () => {
		axios.post
			.mockResolvedValueOnce({ data: { conflicts: [], breaking: [], targetHash: 'h1' } })
			.mockRejectedValueOnce({ response: { status: 409, data: { error: 'target_changed', detail: 'changed' } } })
		const wrapper = await mountDialog()
		await wrapper.find('[data-test="merge-preview"]').trigger('click')
		await flushPromises()
		await mergeButton(wrapper).trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('Preview the merge again')
		expect(wrapper.find('[data-test="merge-preview"]').text()).toBe('Preview')
		expect(mergeButton(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.emitted('merged')).toBeUndefined()
	})

	it('shows the server reason when a merge is refused', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { error: 'target_is_production', detail: 'The production version changes through release and promotion only.' } } })
		const wrapper = await mountDialog()
		await wrapper.find('[data-test="merge-preview"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The production version changes through release and promotion only.')
	})
})
