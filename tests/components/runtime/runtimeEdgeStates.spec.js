/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Edge states of the runtime widgets that the per-widget specs leave out:
 * a refused approve or reject in My approvals, the widgets mounted with no
 * props at all, and the schema an object without one reports.
 */
import { mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import ConnectorDataView from '../../../src/components/runtime/ConnectorDataView.vue'
import DocumentActions from '../../../src/components/runtime/DocumentActions.vue'
import MyApprovalsWidget from '../../../src/components/runtime/MyApprovalsWidget.vue'
import ProcestCaseStatusPanel from '../../../src/components/runtime/ProcestCaseStatusPanel.vue'

const flush = () => new Promise((r) => setTimeout(r, 0))

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
}

const TASK = {
	id: 5,
	uuid: 'task-5',
	state: 'available',
	candidateGroups: ['reviewers'],
	subject: { uuid: 'obj-5', title: 'Permit 5' },
	displayTitle: 'Approve: Permit 5',
	overdue: false,
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	loadState.mockReset()
})

describe('My approvals: a refused decision', () => {
	/**
	 * Mount the widget over one pooled task.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function widget() {
		loadState.mockReturnValue(['reviewers'])
		axios.get.mockImplementation((url, config) => Promise.resolve({
			data: config?.params?.scope === 'pooled' ? { results: [TASK], total: 1 } : { results: [], total: 0 },
		}))
		const wrapper = mount(MyApprovalsWidget, { global: { stubs } })
		await flush()
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('says the step could not be approved, and keeps the task listed', async () => {
		const wrapper = await widget()
		const reads = axios.get.mock.calls.length
		axios.post.mockRejectedValue(new Error('409'))

		await wrapper.vm.decide(TASK, 'approve')
		await wrapper.vm.$nextTick()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/flow-tasks/task-5/complete', { outcome: 'approved' })
		const note = wrapper.find('.note')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Could not approve this step.')
		expect(axios.get.mock.calls.length).toBe(reads)
		expect(wrapper.text()).toContain('Approve: Permit 5')
	})

	it('says the step could not be rejected, after sending the provenance comment', async () => {
		const wrapper = await widget()
		axios.post.mockRejectedValue(new Error('403'))

		await wrapper.vm.decide(TASK, 'reject')
		await wrapper.vm.$nextTick()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/flow-tasks/task-5/complete', {
			outcome: 'rejected',
			comment: 'Rejected from the My approvals widget.',
		})
		expect(wrapper.find('.note').text()).toBe('Could not reject this step.')
	})

	it('clears the error once a later decision goes through', async () => {
		const wrapper = await widget()
		axios.post.mockRejectedValueOnce(new Error('409')).mockResolvedValueOnce({ data: {} })

		await wrapper.vm.decide(TASK, 'approve')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.note').exists()).toBe(true)

		await wrapper.vm.decide({ id: 6 }, 'approve')
		await wrapper.vm.$nextTick()
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/flow-tasks/6/complete', { outcome: 'approved' })
		expect(wrapper.find('.note').exists()).toBe(false)
	})
})

describe('runtime widgets mounted with no props', () => {
	it('the case panel offers to start a case and loads nothing', () => {
		const wrapper = shallowMount(ProcestCaseStatusPanel, { global: { stubs } })

		expect(wrapper.text()).toContain('No Dossiq case is linked to this object yet.')
		expect(wrapper.vm.linkReference).toBe('')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('the connector view calls nothing without a bound endpoint and offers a retry', async () => {
		const wrapper = mount(ConnectorDataView, { global: { stubs } })
		await flush()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Could not load data from OpenConnector.')
		expect(wrapper.find('button').text()).toBe('Retry')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('document actions render nothing for an object without a schema', () => {
		const wrapper = shallowMount(DocumentActions, {
			props: { docudeskAvailable: true },
			global: { provide: { cnManifest: { runtime: { documents: [{ id: 'd', schema: 'permit', label: 'Letter' }] } } } },
		})

		expect(wrapper.vm.objectSchema).toBe('')
		expect(wrapper.vm.schemaAttachments).toEqual([])
		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('document actions report the first schema key of an object that has one', () => {
		const wrapper = shallowMount(DocumentActions, {
			props: { object: { '@self': { schema: 'permit' } }, docudeskAvailable: true },
			global: { provide: { cnManifest: { runtime: { documents: [{ id: 'd', schema: 'permit', label: 'Letter' }] } } } },
		})

		expect(wrapper.vm.objectSchema).toBe('permit')
		expect(wrapper.vm.schemaAttachments.map((a) => a.id)).toEqual(['d'])
	})
})
