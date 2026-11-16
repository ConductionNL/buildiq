/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/dialogs/RuleSetCallersDialog.vue`, the rule set
 * setting "May be called by" (rules-evaluation-for-other-apps REQ-BQRX-001):
 * the value it is prefilled with, the value Save stores, and the label the
 * select carries.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), patch: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) => p.replace('{id}', params.id),
	generateOcsUrl: (p) => '/ocs/v2.php/' + p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import RuleSetCallersDialog from '../../src/dialogs/RuleSetCallersDialog.vue'

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'size'],
		emits: ['update:open', 'closing'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'multiple', 'taggable', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<div :data-label="inputLabel" />',
	},
}

const PARKING = {
	slug: 'parkeren-soort-vergunning',
	name: 'Soort parkeervergunning',
	callableBy: ['pipelinq'],
	'@self': { id: 'rs-1' },
}

/**
 * Mount the dialog and open it.
 *
 * @param {object|null} ruleSet - the rule set prop.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(ruleSet = PARKING) {
	const wrapper = mount(RuleSetCallersDialog, {
		props: { open: false, ruleSet },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	await new Promise((resolve) => setTimeout(resolve, 0))
	return wrapper
}

/**
 * Press Save and let the request settle.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Promise<void>}
 */
async function save(wrapper) {
	await wrapper.findAll('button').at(1).trigger('click')
	await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('RuleSetCallersDialog', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.patch.mockReset()
		axiosMock.get.mockResolvedValue({ data: { ocs: { data: { apps: ['portaliq', 'buildiq', 'decidiq'] } } } })
	})

	it('the select is labelled "May be called by" and offers the enabled apps except buildiq', async () => {
		const wrapper = await openDialog()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('inputLabel')).toBe('May be called by')
		expect(select.props('multiple')).toBe(true)
		expect(select.props('modelValue')).toEqual(['pipelinq'])
		expect(select.props('options')).toEqual(['decidiq', 'pipelinq', 'portaliq'])
		expect(axiosMock.get).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps?filter=enabled')
	})

	it('a maker adds portaliq and Save stores the list on the rule set', async () => {
		axiosMock.patch.mockResolvedValue({ data: {} })
		const wrapper = await openDialog()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', ['pipelinq', 'portaliq', ' portaliq ', ''])
		await save(wrapper)

		expect(axiosMock.patch).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/rule-set/rs-1',
			{ callableBy: ['pipelinq', 'portaliq'] },
		)
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'parkeren-soort-vergunning' })
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('emptying the list stores an empty list, so the rule set stays private', async () => {
		axiosMock.patch.mockResolvedValue({ data: {} })
		const wrapper = await openDialog()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [])
		await save(wrapper)

		expect(axiosMock.patch.mock.calls[0][1]).toEqual({ callableBy: [] })
	})

	it('a failed save says so and keeps the dialog open', async () => {
		axiosMock.patch.mockRejectedValue(new Error('403'))
		const wrapper = await openDialog()

		await save(wrapper)

		expect(wrapper.find('[role="alert"]').text()).toBe('Could not save who may call this rule set.')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('without the apps list or a stored value the choice starts empty and can still be typed', async () => {
		axiosMock.get.mockRejectedValue(new Error('403'))
		const wrapper = await openDialog({ slug: 'private', '@self': { id: 'rs-2' } })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('modelValue')).toEqual([])
		expect(select.props('options')).toEqual([])
		expect(select.props('taggable')).toBe(true)
	})

	it('a rule set without an id is not saved', async () => {
		const wrapper = await openDialog({ slug: 'unsaved' })

		await save(wrapper)

		expect(axiosMock.patch).not.toHaveBeenCalled()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})
})
