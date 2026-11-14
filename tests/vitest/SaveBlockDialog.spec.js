/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/SaveBlockDialog.vue`.
 *
 * Pins the create-only save (POST of the captured record), the
 * de-namespace collision message and the error message fallback chain.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))

import SaveBlockDialog from '../../src/dialogs/SaveBlockDialog.vue'

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
	NcTextArea: { name: 'NcTextArea', template: '<textarea />' },
}

const WIDGET = { id: 'w1', widgetKey: 'permit-list', config: { schema: 'my-app-permit' } }

/**
 * @param {object} fragment the fragment prop.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountDialog(fragment = WIDGET) {
	const wrapper = mount(SaveBlockDialog, {
		props: { open: true, application: { slug: 'my-app' }, fragment, existingBlocks: [] },
		global: { stubs: STUBS },
	})
	wrapper.vm.form.category = 'display'
	return wrapper
}

describe('SaveBlockDialog.vue', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('POSTs the captured record and emits its slug', async () => {
		const wrapper = mountDialog()
		axiosMock.post.mockResolvedValueOnce({ data: {} })

		expect(wrapper.vm.canSave).toBe(true)
		await wrapper.vm.save()

		const [url, record] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/buildiq/component-block')
		expect(record).toMatchObject({ slug: 'permit-list', name: 'permit-list', createdBy: 'alice' })
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'permit-list' })
		expect(wrapper.emitted('update:open').at(-1)).toEqual([false])
	})

	it('names both schemas of a de-namespace collision and clears it again', async () => {
		const colliding = { id: 's', widgets: [
			{ id: 'a', widgetKey: 'a', config: { schema: 'my-app-tasks' } },
			{ id: 'b', widgetKey: 'b', config: { schema: 'tasks' } },
		] }
		const wrapper = mountDialog(colliding)

		expect(wrapper.vm.collisionError).toBe('my-app-tasks, tasks')
		expect(wrapper.vm.canSave).toBe(false)

		await wrapper.setProps({ fragment: WIDGET })
		expect(wrapper.vm.collisionError).toBe('')
		await wrapper.setProps({ fragment: null })
		expect(wrapper.vm.collisionError).toBe('')
	})

	it.each([
		[{ response: { data: { detail: 'D', error: 'E' } }, message: 'M' }, 'D'],
		[{ response: { data: { error: 'E' } }, message: 'M' }, 'E'],
		[{ message: 'M' }, 'M'],
		[{}, 'Saving the block failed.'],
	])('reports a failed save as %j -> %s', async (error, expected) => {
		const wrapper = mountDialog()
		axiosMock.post.mockRejectedValueOnce(error)

		await wrapper.vm.save()

		expect(wrapper.vm.saveError).toBe(expected)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
