/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/PromoteVersionDialog.vue`.
 *
 * Covers spec D task 4.9:
 *   - mounts with production target → default strategy is migrate-existing-data
 *   - mounts with mid-chain target → default strategy is start-with-source-data
 *   - selecting empty-start disables Confirm until slug is typed
 *   - typing the wrong slug keeps Confirm disabled
 *   - typing the exact app slug enables Confirm
 *   - clicking Confirm emits { strategy: <selected> }
 *   - clicking Cancel emits cancel
 *   - changing targetVersion prop resets selectedStrategy
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PromoteVersionDialog from '../../src/dialogs/PromoteVersionDialog.vue'

// Minimal stubs for @nextcloud/vue components used by the dialog.
const baseStubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'canClose'],
		template: '<div class="nc-dialog-stub"><slot /><div class="nc-dialog-actions"><slot name="actions" /></div></div>',
		emits: ['closing'],
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled || false" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'value', 'name', 'type'],
		template: `
			<label class="radio-stub">
				<input
					type="radio"
					:name="name"
					:value="value"
					:checked="checked === value"
					@change="$emit('update:checked', value)"
				/>
				<slot />
			</label>
		`,
		emits: ['update:checked'],
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'placeholder', 'helperText', 'autocomplete'],
		// Use both :value and v-model-like binding to support .sync / v-model
		template: '<input class="nc-textfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
		emits: ['update:value'],
	},
}

/** Test data */
const APP_PRODUCTION = { slug: 'hello-world', productionVersion: 'prod-uuid-001' }
const APP_NO_PRODUCTION = { slug: 'hello-world', productionVersion: null }

const SOURCE_VERSION = {
	id: 'src-uuid-001',
	uuid: 'src-uuid-001',
	name: 'Development',
	slug: 'development',
	register: 'openbuilt-hello-world-development',
}

const TARGET_PRODUCTION = {
	id: 'prod-uuid-001',
	uuid: 'prod-uuid-001',
	name: 'Production',
	slug: 'production',
	register: 'openbuilt-hello-world-production',
}

const TARGET_MIDCHAIN = {
	id: 'staging-uuid-001',
	uuid: 'staging-uuid-001',
	name: 'Staging',
	slug: 'staging',
	register: 'openbuilt-hello-world-staging',
}

/**
 * Mount helper.
 *
 * @param {object} props overrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountDialog(props = {}) {
	return mount(PromoteVersionDialog, {
		propsData: {
			sourceVersion: SOURCE_VERSION,
			targetVersion: TARGET_PRODUCTION,
			application: APP_PRODUCTION,
			...props,
		},
		stubs: baseStubs,
	})
}

describe('PromoteVersionDialog.vue (spec D task 4.9)', () => {

	// -----------------------------------------------------------------------
	// Default strategy rule (REQ-OBVP-011)
	// -----------------------------------------------------------------------
	it('defaults to migrate-existing-data when target IS the production version', () => {
		const wrapper = mountDialog({
			targetVersion: TARGET_PRODUCTION,
			application: APP_PRODUCTION,
		})
		expect(wrapper.vm.selectedStrategy).toBe('migrate-existing-data')
	})

	it('defaults to start-with-source-data when target is a mid-chain version', () => {
		const wrapper = mountDialog({
			targetVersion: TARGET_MIDCHAIN,
			application: APP_PRODUCTION,
		})
		expect(wrapper.vm.selectedStrategy).toBe('start-with-source-data')
	})

	it('defaults to start-with-source-data when application has no productionVersion', () => {
		const wrapper = mountDialog({
			targetVersion: TARGET_MIDCHAIN,
			application: APP_NO_PRODUCTION,
		})
		expect(wrapper.vm.selectedStrategy).toBe('start-with-source-data')
	})

	it('never defaults to empty-start (spec REQ-OBVP-011 — empty-start is always user-initiated)', () => {
		const strategies = [TARGET_PRODUCTION, TARGET_MIDCHAIN].map(target => {
			const w = mountDialog({ targetVersion: target })
			return w.vm.selectedStrategy
		})
		expect(strategies).not.toContain('empty-start')
	})

	// -----------------------------------------------------------------------
	// Destructive-confirmation gate (REQ-OBVP-010)
	// -----------------------------------------------------------------------
	it('isDestructiveGateMet is true for start-with-source-data (no gate)', () => {
		const wrapper = mountDialog({ targetVersion: TARGET_MIDCHAIN })
		wrapper.vm.selectedStrategy = 'start-with-source-data'
		expect(wrapper.vm.isDestructiveGateMet).toBe(true)
	})

	it('isDestructiveGateMet is true for migrate-existing-data (no gate)', () => {
		const wrapper = mountDialog({ targetVersion: TARGET_PRODUCTION })
		wrapper.vm.selectedStrategy = 'migrate-existing-data'
		expect(wrapper.vm.isDestructiveGateMet).toBe(true)
	})

	it('Confirm button is enabled by default for non-destructive strategies', () => {
		const wrapper = mountDialog({ targetVersion: TARGET_MIDCHAIN })
		// selectedStrategy defaults to start-with-source-data
		const confirmBtn = wrapper.find('button[data-type="primary"]')
		expect(confirmBtn.exists()).toBe(true)
		expect(confirmBtn.attributes('disabled')).toBeFalsy()
	})

	it('isDestructiveGateMet is false for empty-start with empty typedSlug', () => {
		const wrapper = mountDialog()
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = ''
		expect(wrapper.vm.isDestructiveGateMet).toBe(false)
	})

	it('isDestructiveGateMet is false for empty-start with wrong typedSlug', () => {
		const wrapper = mountDialog()
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = 'wrong-slug'
		expect(wrapper.vm.isDestructiveGateMet).toBe(false)
	})

	it('isDestructiveGateMet is true for empty-start when typedSlug matches app.slug exactly', () => {
		const wrapper = mountDialog({ application: { slug: 'hello-world', productionVersion: 'prod-uuid-001' } })
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = 'hello-world'
		expect(wrapper.vm.isDestructiveGateMet).toBe(true)
	})

	it('isDestructiveGateMet is false for empty-start with wrong-case slug (case-sensitive check)', () => {
		const wrapper = mountDialog({ application: { slug: 'hello-world', productionVersion: 'prod-uuid-001' } })
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = 'Hello-World'
		expect(wrapper.vm.isDestructiveGateMet).toBe(false)
	})

	it('Confirm button is disabled when empty-start selected and typedSlug is empty', async () => {
		const wrapper = mountDialog()
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = ''
		await wrapper.vm.$nextTick()
		const confirmBtn = wrapper.find('button[data-type="primary"]')
		expect(confirmBtn.attributes('disabled')).toBeTruthy()
	})

	it('Confirm button enables when empty-start selected and exact slug typed', async () => {
		const wrapper = mountDialog({ application: { slug: 'hello-world', productionVersion: 'prod-uuid-001' } })
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = 'hello-world'
		await wrapper.vm.$nextTick()
		const confirmBtn = wrapper.find('button[data-type="primary"]')
		expect(confirmBtn.attributes('disabled')).toBeFalsy()
	})

	// -----------------------------------------------------------------------
	// Event emission
	// -----------------------------------------------------------------------
	it('emits confirm with chosen strategy on Confirm click when gate is met', async () => {
		const wrapper = mountDialog({ targetVersion: TARGET_MIDCHAIN, application: APP_PRODUCTION })
		wrapper.vm.selectedStrategy = 'start-with-source-data'
		await wrapper.vm.$nextTick()

		const confirmBtn = wrapper.find('button[data-type="primary"]')
		await confirmBtn.trigger('click')

		const emitted = wrapper.emitted('confirm')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0]).toEqual({ strategy: 'start-with-source-data' })
	})

	it('does not emit confirm when isDestructiveGateMet is false', async () => {
		const wrapper = mountDialog()
		wrapper.vm.selectedStrategy = 'empty-start'
		wrapper.vm.typedSlug = 'wrong-slug'
		await wrapper.vm.$nextTick()

		// Call onConfirm directly to simulate the click path.
		wrapper.vm.onConfirm()
		expect(wrapper.emitted('confirm')).toBeFalsy()
	})

	it('emits cancel when Cancel button is clicked', async () => {
		const wrapper = mountDialog()
		const cancelBtn = wrapper.find('button[data-type="tertiary"]')
		await cancelBtn.trigger('click')

		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	// -----------------------------------------------------------------------
	// State reset on prop change
	// -----------------------------------------------------------------------
	it('resets selectedStrategy when targetVersion prop changes', async () => {
		const wrapper = mountDialog({
			targetVersion: TARGET_PRODUCTION,
			application: APP_PRODUCTION,
		})
		// Initial: production target → migrate-existing-data
		expect(wrapper.vm.selectedStrategy).toBe('migrate-existing-data')

		// Change target to mid-chain
		await wrapper.setProps({ targetVersion: TARGET_MIDCHAIN })
		expect(wrapper.vm.selectedStrategy).toBe('start-with-source-data')
	})

	it('resets typedSlug when targetVersion prop changes', async () => {
		const wrapper = mountDialog()
		wrapper.vm.typedSlug = 'old-slug'

		await wrapper.setProps({ targetVersion: TARGET_MIDCHAIN })
		expect(wrapper.vm.typedSlug).toBe('')
	})

	// -----------------------------------------------------------------------
	// No-target state (REQ-OBVP-010)
	// -----------------------------------------------------------------------
	it('renders no-target body and no Confirm button when targetVersion is null', () => {
		const wrapper = mountDialog({ targetVersion: null })
		// The form / strategy fieldset must NOT be rendered.
		expect(wrapper.find('form.promote-dialog').exists()).toBe(false)
		// The Confirm button is only rendered when targetVersion is truthy.
		const confirmBtn = wrapper.find('button[data-type="primary"]')
		expect(confirmBtn.exists()).toBe(false)
	})

	it('renders Cancel-only footer when targetVersion is null', () => {
		const wrapper = mountDialog({ targetVersion: null })
		const cancelBtn = wrapper.find('button[data-type="tertiary"]')
		expect(cancelBtn.exists()).toBe(true)
	})
})
