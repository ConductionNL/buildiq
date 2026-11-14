/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FileFieldSettings.vue (REQ-BQFK-001, REQ-BQFK-002).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FileFieldSettings from '../../../src/components/page-editor/fields/FileFieldSettings.vue'

function lastEmit(wrapper) {
	const all = wrapper.emitted('update')
	return all[all.length - 1][0]
}

describe('FileFieldSettings', () => {
	const field = { key: 'bonnetjes', label: 'Bonnetjes', type: 'file', extra: 'kept' }

	it('writes accept, maxSize in bytes and multiple for receipts as PDF or image', async () => {
		const wrapper = mount(FileFieldSettings, { props: { field } })
		await wrapper.find('[data-testid="file-accept"]').setValue(' .pdf,image/* ')
		expect(lastEmit(wrapper)).toEqual({ ...field, accept: '.pdf,image/*' })

		await wrapper.find('[data-testid="file-max-size"]').setValue('10')
		expect(lastEmit(wrapper).maxSize).toBe(10485760)

		await wrapper.find('[data-testid="file-multiple"]').setValue(true)
		expect(lastEmit(wrapper).multiple).toBe(true)
		expect(lastEmit(wrapper).extra).toBe('kept')
	})

	it('shows a stored maxSize in megabytes', () => {
		const wrapper = mount(FileFieldSettings, { props: { field: { ...field, maxSize: 5242880 } } })
		expect(wrapper.find('[data-testid="file-max-size"]').element.value).toBe('5')
	})

	it('removes multiple when unticked and maxSize when cleared', async () => {
		const wrapper = mount(FileFieldSettings, { props: { field: { ...field, multiple: true, maxSize: 1048576 } } })
		await wrapper.find('[data-testid="file-multiple"]').setValue(false)
		expect('multiple' in lastEmit(wrapper)).toBe(false)
		await wrapper.find('[data-testid="file-max-size"]').setValue('')
		expect('maxSize' in lastEmit(wrapper)).toBe(false)
	})

	it('sets the back camera as capture environment and the front as user', async () => {
		const wrapper = mount(FileFieldSettings, { props: { field } })
		await wrapper.find('[data-testid="file-capture"]').setValue('environment')
		expect(lastEmit(wrapper).capture).toBe('environment')
		await wrapper.find('[data-testid="file-capture"]').setValue('user')
		expect(lastEmit(wrapper).capture).toBe('user')
	})

	it('removes capture when the camera is set to off', async () => {
		const wrapper = mount(FileFieldSettings, { props: { field: { ...field, capture: 'environment' } } })
		await wrapper.find('[data-testid="file-capture"]').setValue('')
		expect('capture' in lastEmit(wrapper)).toBe(false)
		expect(lastEmit(wrapper).type).toBe('file')
	})
})
