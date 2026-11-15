/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * FormPageEditor adds the properties a device field writes to the target
 * schema and puts the stored schema back in its list
 * (forms-signature-audio-and-location, REQ-BQSA-002).
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/dialogs/ExternalFormAccessDialog.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'ExternalFormAccessDialog',
			props: ['open', 'register', 'schema', 'pageId', 'entry'],
			render() {
				return h('div')
			},
		},
	}
})

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

const SCHEMA = { id: 7, slug: 'oplevering', title: 'Oplevering', properties: { naam: { type: 'string' } } }

/**
 * Mount the editor with one schema in its list.
 *
 * @return {object} the wrapper.
 */
async function editor() {
	const wrapper = mount(FormPageEditor, { props: { config: {} } })
	await wrapper.setData({ schemas: [SCHEMA, { id: 8, slug: 'ander' }] })
	return wrapper
}

describe('FormPageEditor adds a device field property', () => {
	it('patches the schema with the addition and keeps what it had', async () => {
		const wrapper = await editor()
		const client = {
			patch: vi.fn().mockResolvedValue({
				data: { ...SCHEMA, properties: { naam: { type: 'string' }, handtekening: { type: 'file' } } },
			}),
		}
		await wrapper.vm.addTargetProperties({ schema: SCHEMA, additions: { handtekening: { type: 'file' } } }, client)

		expect(client.patch).toHaveBeenCalledTimes(1)
		const [url, body] = client.patch.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/schemas/7')
		expect(body).toEqual({ properties: { naam: { type: 'string' }, handtekening: { type: 'file' } } })
		expect(wrapper.vm.schemas[0].properties.handtekening).toEqual({ type: 'file' })
		expect(wrapper.vm.schemas[1]).toEqual({ id: 8, slug: 'ander' })
		expect(wrapper.vm.propertyNote).toBe('Added {properties} to {schema}.')
	})

	it('says so when the schema refuses, and leaves the list alone', async () => {
		const wrapper = await editor()
		const client = { patch: vi.fn().mockRejectedValue(new Error('403')) }
		await wrapper.vm.addTargetProperties({ schema: SCHEMA, additions: { lat: { type: 'number' } } }, client)

		expect(wrapper.vm.schemas[0]).toEqual(SCHEMA)
		expect(wrapper.vm.propertyNote).toBe('Could not add the property to {schema}: {reason}')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="device-property-note"]').exists()).toBe(true)
	})

	it('sends nothing without additions', async () => {
		const wrapper = await editor()
		const client = { patch: vi.fn() }
		await wrapper.vm.addTargetProperties({ schema: SCHEMA, additions: {} }, client)
		expect(client.patch).not.toHaveBeenCalled()
	})
})
