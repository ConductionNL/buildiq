/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the fee on submit in RegistrationFormEditor.vue
 * (forms-fee-on-submit, REQ-OBRF-010): a maker sets an amount, a currency and
 * the line the filer sees, and is told when the sign-in level is too low for it.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RegistrationFormEditor from '../../src/components/page-editor/fields/RegistrationFormEditor.vue'

const stubs = { FormLayoutBuilder: true, FormPresetsBuilder: true }

function factory(modelValue = {}) {
	return mount(RegistrationFormEditor, {
		props: { modelValue: { name: 'intake', ...modelValue } },
		global: { stubs },
	})
}

const last = (wrapper) => wrapper.emitted()['update:modelValue'].at(-1)[0]

describe('RegistrationFormEditor fee on submit', () => {
	it('shows only the switch while the form has no fee', () => {
		const wrapper = factory()
		expect(wrapper.find('[data-testid="registration-form-fee-switch"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="registration-form-fee-amount"]').exists()).toBe(false)
	})

	it('switching it on starts a fee in euros and keeps the rest of the form', async () => {
		const wrapper = factory({ audience: 'client', minTrust: 'substantial' })
		await wrapper.find('[data-testid="registration-form-fee-switch"]').setValue(true)
		expect(last(wrapper)).toEqual({
			name: 'intake',
			audience: 'client',
			minTrust: 'substantial',
			fee: { currency: 'EUR', description: '' },
		})
	})

	it('writes the amount as a number, the currency in capitals and the line', async () => {
		const wrapper = factory({ minTrust: 'substantial', fee: { currency: 'EUR', description: '' } })
		await wrapper.find('[data-testid="registration-form-fee-amount"]').setValue('25')
		expect(last(wrapper).fee).toEqual({ currency: 'EUR', description: '', amount: 25 })

		await wrapper.find('[data-testid="registration-form-fee-currency"]').setValue(' usd ')
		expect(last(wrapper).fee.currency).toBe('USD')

		await wrapper.find('[data-testid="registration-form-fee-description"]').setValue('Leges evenementenvergunning')
		expect(last(wrapper).fee.description).toBe('Leges evenementenvergunning')
	})

	it('an emptied amount is removed rather than stored as zero', async () => {
		const wrapper = factory({ minTrust: 'high', fee: { amount: 25, currency: 'EUR' } })
		const amount = wrapper.find('[data-testid="registration-form-fee-amount"]')
		expect(amount.element.value).toBe('25')
		await amount.setValue('')
		expect(last(wrapper).fee).toEqual({ currency: 'EUR' })
	})

	it('switching it off removes the fee', async () => {
		const wrapper = factory({ minTrust: 'substantial', fee: { amount: 25, currency: 'EUR' } })
		await wrapper.find('[data-testid="registration-form-fee-switch"]').setValue(false)
		expect(last(wrapper)).not.toHaveProperty('fee')
		expect(last(wrapper).minTrust).toBe('substantial')
	})

	it('says why a fee below sign-in level substantial cannot be saved', () => {
		for (const minTrust of [undefined, 'low']) {
			const alert = factory({ minTrust, fee: { amount: 25 } }).find('[data-testid="registration-form-fee-sign-in"]')
			expect(alert.exists()).toBe(true)
			expect(alert.attributes('role')).toBe('alert')
			expect(alert.text()).toContain('needs sign-in at level substantial or high')
		}
		for (const minTrust of ['substantial', 'high']) {
			expect(factory({ minTrust, fee: { amount: 25 } }).find('[data-testid="registration-form-fee-sign-in"]').exists()).toBe(false)
		}
	})
})
