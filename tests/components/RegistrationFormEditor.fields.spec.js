/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the rest of RegistrationFormEditor.vue: the form's own
 * fields, the channel pickers that become lists once the other app's values
 * are known, public access clearing a sign-in level, the destination
 * findings, and the action buttons.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RegistrationFormEditor from '../../src/components/page-editor/fields/RegistrationFormEditor.vue'

const stubs = {
	FormLayoutBuilder: {
		name: 'FormLayoutBuilder',
		props: ['sections', 'fields', 'properties', 'findings'],
		emits: ['update:sections', 'update:fields'],
		template: '<div class="layout" />',
	},
	FormPresetsBuilder: {
		name: 'FormPresetsBuilder',
		props: ['presets', 'properties', 'findings'],
		emits: ['update:presets'],
		template: '<div class="presets" />',
	},
}

/**
 * Mount the editor.
 *
 * @param {object} modelValue The form.
 * @param {object} props Other props.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function factory(modelValue = {}, props = {}) {
	return mount(RegistrationFormEditor, {
		props: { modelValue: { name: 'intake', ...modelValue }, ...props },
		global: { stubs },
	})
}

/**
 * The form emitted last.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The editor.
 * @return {object}
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

/**
 * The control inside the label with the given text.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The editor.
 * @param {string} label The label text it starts with.
 * @param {string} selector The control selector.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function control(wrapper, label, selector) {
	const found = wrapper.findAll('label').find((l) => l.text().startsWith(label))
	return found.find(selector)
}

describe('RegistrationFormEditor fields', () => {
	it('titles itself with the form name, or a generic title without one', () => {
		expect(factory().find('h4').text()).toBe('intake')
		expect(factory({ name: '' }).find('h4').text()).toBe('This form')
	})

	it('writes the name, audience, state, default flag and confirmation text', async () => {
		const wrapper = factory({ audience: 'client', status: 'draft' })

		await control(wrapper, 'Name', 'input').setValue('Permit')
		expect(lastEmitted(wrapper)).toEqual({ name: 'Permit', audience: 'client', status: 'draft' })

		await control(wrapper, 'Who fills it in', 'select').setValue('supplier')
		expect(lastEmitted(wrapper).audience).toBe('supplier')

		await control(wrapper, 'State', 'select').setValue('published')
		expect(lastEmitted(wrapper).status).toBe('published')

		await control(wrapper, 'The intake picks this one', 'input').setValue(true)
		expect(lastEmitted(wrapper).isDefault).toBe(true)

		await control(wrapper, 'What they read after sending', 'textarea').setValue('Received.')
		expect(lastEmitted(wrapper).confirmationText).toBe('Received.')
	})

	it('defaults the audience to the client and the state to draft', () => {
		const wrapper = factory()

		expect(control(wrapper, 'Who fills it in', 'select').element.value).toBe('client')
		expect(control(wrapper, 'State', 'select').element.value).toBe('draft')
	})

	it('without known properties and channels both are typed, with a hint', async () => {
		const wrapper = factory()

		await control(wrapper, 'Which property carries the channel', 'input').setValue('intakeChannel')
		expect(lastEmitted(wrapper).channelProperty).toBe('intakeChannel')
		await control(wrapper, 'Intake channel', 'input').setValue('portal')
		expect(lastEmitted(wrapper).channel).toBe('portal')
		expect(wrapper.find('.form-editor__hint').text()).toContain('Name the property above')
	})

	it('with known properties and channels both are picked from a list, without the hint', async () => {
		const wrapper = factory({}, { properties: ['title', 'intakeChannel'], channels: ['portal', 'counter'] })
		const propertySelect = control(wrapper, 'Which property carries the channel', 'select')
		const channelSelect = control(wrapper, 'Intake channel', 'select')

		expect(propertySelect.findAll('option').map((o) => o.text())).toEqual(['None', 'title', 'intakeChannel'])
		expect(channelSelect.findAll('option').map((o) => o.text())).toEqual(['Every channel', 'portal', 'counter'])
		expect(wrapper.text()).not.toContain('Name the property above')

		await propertySelect.setValue('intakeChannel')
		expect(lastEmitted(wrapper).channelProperty).toBe('intakeChannel')
		await channelSelect.setValue('counter')
		expect(lastEmitted(wrapper).channel).toBe('counter')
	})

	it('making the form public removes a required sign-in; making it private keeps the rest', async () => {
		const wrapper = factory({ minTrust: 'substantial', isPublic: false })
		const checkbox = control(wrapper, 'Anyone may fill this in', 'input')

		await checkbox.setValue(true)
		expect(lastEmitted(wrapper)).toEqual({ name: 'intake', isPublic: true })

		await checkbox.setValue(false)
		expect(lastEmitted(wrapper)).toEqual({ name: 'intake', minTrust: 'substantial', isPublic: false })
	})

	it('passes layout and preset edits through as form keys', () => {
		const wrapper = factory()

		wrapper.findComponent({ name: 'FormLayoutBuilder' }).vm.$emit('update:fields', [{ name: 'title' }])
		expect(lastEmitted(wrapper).fields).toEqual([{ name: 'title' }])
		wrapper.findComponent({ name: 'FormLayoutBuilder' }).vm.$emit('update:sections', [{ id: 's1' }])
		expect(lastEmitted(wrapper).sections).toEqual([{ id: 's1' }])
		wrapper.findComponent({ name: 'FormPresetsBuilder' }).vm.$emit('update:presets', [{ field: 'channel' }])
		expect(lastEmitted(wrapper).presets).toEqual([{ field: 'channel' }])
	})

	it('lists only findings that sit on no field or preset, and says what a draft save means', () => {
		const findings = [
			{ field: 'title', message: 'On a field' },
			{ field: 'channel', message: 'On a preset' },
			{ code: 'required-unmapped', property: 'bsn', message: 'Nothing maps to bsn' },
		]
		const wrapper = factory({ fields: [{ name: 'title' }], presets: [{ field: 'channel' }] }, { findings })
		const box = wrapper.find('[data-testid="registration-form-findings"]')

		expect(box.find('.form-editor__warn').text()).toContain('Saved as a draft.')
		expect(box.findAll('li').map((li) => li.text())).toEqual(['Nothing maps to bsn'])
		expect(wrapper.findComponent({ name: 'FormLayoutBuilder' }).props('findings').title).toEqual([findings[0]])
	})

	it('a published form with findings says it cannot be published yet', () => {
		const wrapper = factory({ status: 'published' }, { findings: [{ field: 'x', message: 'm' }] })

		expect(wrapper.find('[data-testid="registration-form-findings"]').text()).toContain('cannot be published yet')
	})

	it('without findings there is no findings box; a note is shown when given', () => {
		const wrapper = factory({}, { note: 'The schema could not be read.' })

		expect(wrapper.find('[data-testid="registration-form-findings"]').exists()).toBe(false)
		expect(wrapper.find('p[role="alert"]').text()).toBe('The schema could not be read.')
	})

	it('save, save to library and close emit their events; save is disabled while saving', async () => {
		const wrapper = factory()
		const button = (text) => wrapper.findAll('.form-editor__actions button').find((b) => b.text() === text)

		await button('Save form').trigger('click')
		await button('Save to form library').trigger('click')
		await button('Close').trigger('click')
		expect(wrapper.emitted('save')).toHaveLength(1)
		expect(wrapper.emitted('saveToLibrary')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)

		await wrapper.setProps({ saving: true })
		expect(button('Saving').attributes('disabled')).toBeDefined()
	})
})
