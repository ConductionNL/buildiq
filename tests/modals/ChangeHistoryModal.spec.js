/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ChangeHistoryModal (capability-requirements-backfill T14,
 * REQ-BQCV-014): opening it reads the app's history endpoint and lists who
 * saved what and when, newest first as served; a refusal for a user without
 * a role shows its message and no rows.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import ChangeHistoryModal from '../../src/modals/ChangeHistoryModal.vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) => {
		let out = key
		Object.entries(vars || {}).forEach(([k, v]) => {
			out = out.replace(`{${k}}`, v)
		})
		return out
	}
})
afterAll(() => {
	globalThis.t = realT
})

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="nc-dialog-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="nc-empty-stub"><span class="nc-empty-name">{{ name }}</span><slot name="icon" /></div>',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="nc-note-stub"><slot /></div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	HistoryIcon: { name: 'HistoryIcon', template: '<span />' },
}

async function mountOpen() {
	const wrapper = mount(ChangeHistoryModal, {
		props: { open: true, slug: 'permits' },
		global: { stubs: STUBS },
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

beforeEach(() => {
	axiosMock.get.mockReset()
})

describe('ChangeHistoryModal', () => {
	it('an owner sees sanne\'s save of the development version', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				entries: [
					{ at: '2026-11-24T10:15:00+00:00', user: 'sanne', userName: 'Sanne', action: 'update', object: 'version', version: 'development', versionName: 'Development' },
					{ at: '2026-11-24T09:00:00+00:00', user: 'ruben', userName: null, action: 'create', object: 'application', version: null, versionName: null },
				],
			},
		})

		const wrapper = await mountOpen()

		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/permits/history')
		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		const first = rows[0].findAll('td').map((cell) => cell.text())
		expect(first[0]).toBe(new Date('2026-11-24T10:15:00+00:00').toLocaleString())
		expect(first.slice(1)).toEqual(['Sanne', 'Version Development saved'])
		const second = rows[1].findAll('td').map((cell) => cell.text())
		expect(second.slice(1)).toEqual(['ruben', 'App settings created'])
	})

	it('a user without a role sees the refusal and no rows', async () => {
		axiosMock.get.mockRejectedValue({ response: { status: 403 } })

		const wrapper = await mountOpen()

		expect(wrapper.find('.nc-note-stub').text()).toBe('You need a role on this app to see its change history.')
		expect(wrapper.findAll('tbody tr')).toHaveLength(0)
	})

	it('an empty history says nothing was recorded, and Close closes', async () => {
		axiosMock.get.mockResolvedValue({ data: { entries: [] } })

		const wrapper = await mountOpen()

		expect(wrapper.find('.nc-empty-name').text()).toBe('No changes recorded')
		await wrapper.find('.nc-button-stub').trigger('click')
		expect(wrapper.emitted('update:open').at(-1)).toEqual([false])
	})

	it('names a rollback and a deletion, and keeps a stamp Date cannot read', () => {
		const vm = mount(ChangeHistoryModal, { props: { open: false, slug: 'permits' }, global: { stubs: STUBS } }).vm
		expect(vm.subjectLabel({ action: 'revert', object: 'version', version: 'production' })).toBe('Version production rolled back')
		expect(vm.subjectLabel({ action: 'delete', object: 'application' })).toBe('App settings deleted')
		expect(vm.formatStamp('not a date')).toBe('not a date')
		expect(vm.formatStamp(null)).toBe('')
	})
})
