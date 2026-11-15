import manifestModalDoneMixin from '@conduction/nextcloud-vue/src/mixins/manifestModalDoneMixin.js'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The support note editor on the app detail page saves. Done in the library
 * dialog sends one PUT of the working manifest with its support block; a
 * close without Done sends nothing; a failed save keeps the editor and its
 * working copy and says why; the action is only there for a user who may
 * edit the app's versions.
 *
 * The library dialog is replaced by one built on the library's own Done
 * mixin, so the save goes through the same `cnManifestEditor` contract the
 * real dialog uses.
 *
 * @spec openspec/changes/apps-support-note-editor-saves/specs/support-note/spec.md
 */
import { mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => {
	const { default: doneMixin } = await import('@conduction/nextcloud-vue/src/mixins/manifestModalDoneMixin.js')
	return {
		...(await importOriginal()),
		CnActionButtons: { name: 'CnActionButtons', props: ['actions', 'inline', 'overflowLabel'], template: '<div />' },
		CnEditSupportModal: {
			name: 'CnEditSupportModal',
			mixins: [doneMixin],
			props: ['working'],
			emits: ['close'],
			template: '<div><button class="done" @click="onDone">Done</button><button class="dismiss" @click="$emit(\'close\')">x</button></div>',
		},
	}
})
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['admin'],
}))

import axios from '@nextcloud/axios'
import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'
import SupportEditorModal from '../../src/modals/SupportEditorModal.vue'

function t(app, text, vars) {
	let out = String(text)
	Object.entries(vars || {}).forEach(([k, v]) => {
		out = out.replace(`{${k}}`, v)
	})
	return out
}
globalThis.t = t

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

function application(permissions) {
	return {
		'@self': { id: 'app-uuid' },
		id: 'app-uuid',
		slug: 'subsidieloket',
		name: 'Subsidieloket',
		permissions,
	}
}

function mountActions(permissions = { owners: ['admin'], editors: [], viewers: [] }) {
	return shallowMount(ApplicationDetailActions, {
		props: { object: application(permissions), objectId: 'app-uuid' },
		global: {
			mocks: { t, $router: { push: vi.fn() }, $route: { name: 'VirtualAppDetail', params: {}, query: {} } },
			stubs: { SupportEditorModal: { name: 'SupportEditorModal', props: ['slug', 'working'], emits: ['close', 'failed'], template: '<div />' } },
		},
	})
}

function mountEditor(working) {
	return mount(SupportEditorModal, { props: { slug: 'subsidieloket', working } })
}

beforeEach(() => {
	axios.get.mockReset()
	axios.put.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('SupportEditorModal', () => {
	it('Done sends one PUT of the working manifest with its support block, then closes', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const working = { name: 'Subsidieloket', support: { enabled: true, title: 'Over het Subsidieloket' } }
		const wrapper = mountEditor(working)

		await wrapper.find('.done').trigger('click')
		await flush()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/subsidieloket/manifest',
			{ manifest: { name: 'Subsidieloket', support: { enabled: true, title: 'Over het Subsidieloket' } } },
		)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('failed')).toBeUndefined()
	})

	it('closing without Done sends nothing', async () => {
		const wrapper = mountEditor({ support: { enabled: true } })

		await wrapper.find('.dismiss').trigger('click')

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('a failed save stays open, says so, and Done can be clicked again', async () => {
		const failure = Object.assign(new Error('Request failed'), { response: { status: 500, data: { detail: 'disk full' } } })
		axios.put.mockRejectedValueOnce(failure).mockResolvedValueOnce({ data: {} })
		const wrapper = mountEditor({ support: { enabled: true, title: 'Edited' } })

		await wrapper.find('.done').trigger('click')
		await flush()

		expect(wrapper.emitted('failed')).toEqual([[failure]])
		expect(wrapper.emitted('close')).toBeUndefined()

		await wrapper.find('.done').trigger('click')
		await flush()

		expect(axios.put).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('a cancel after a failed save still closes', async () => {
		axios.put.mockRejectedValue(new Error('offline'))
		const wrapper = mountEditor({ support: { enabled: true } })

		await wrapper.find('.done').trigger('click')
		await flush()
		await wrapper.find('.dismiss').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('is the library Done mixin that drives the save', () => {
		expect(typeof manifestModalDoneMixin.methods.onDone).toBe('function')
	})
})

describe('ApplicationDetailActions support note', () => {
	beforeEach(() => {
		axios.get.mockImplementation((url) => {
			if (String(url).endsWith('/manifest')) {
				return Promise.resolve({ data: { name: 'Subsidieloket', support: { enabled: false } } })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	})

	it('a failed save keeps the editor and its working copy, and the page says why', async () => {
		const wrapper = mountActions()
		await wrapper.vm.openSupportEditor()
		await wrapper.vm.$nextTick()

		const editor = wrapper.findComponent({ name: 'SupportEditorModal' })
		expect(editor.props('slug')).toBe('subsidieloket')
		editor.props('working').support.title = 'Over het Subsidieloket'
		editor.vm.$emit('failed', { response: { data: { detail: 'disk full' } } })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.supportOpen).toBe(true)
		expect(wrapper.vm.supportWorkingManifest.support.title).toBe('Over het Subsidieloket')
		expect(wrapper.vm.error).toBe('Could not save the support note: disk full')

		editor.vm.$emit('close')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.supportOpen).toBe(false)
		expect(wrapper.vm.supportWorkingManifest).toBeNull()
	})

	it('offers the action to an owner and not to a viewer', async () => {
		const owner = mountActions()
		const viewer = mountActions({ owners: ['someone'], editors: [], viewers: ['admin'] })
		await owner.vm.$nextTick()
		await viewer.vm.$nextTick()

		expect(owner.vm.actionDescriptors.map((a) => a.id)).toContain('app-edit-support')
		expect(viewer.vm.actionDescriptors.map((a) => a.id)).not.toContain('app-edit-support')
	})
})
