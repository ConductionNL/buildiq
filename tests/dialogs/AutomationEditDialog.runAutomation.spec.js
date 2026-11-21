import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the "Run another automation" step of AutomationEditDialog.
 *
 * Spec: logic-shared-flows-and-automations (REQ-BQSF-004).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationEditDialog from '../../src/dialogs/AutomationEditDialog.vue'
import { toPersistedAction } from '../../src/dialogs/automationPersistedActions.js'
import { clearAppStatusCache } from '../../src/composables/useAppStatus.js'

const stub = (name, template = '<div><slot /></div>') => ({ name, props: ['modelValue', 'options', 'inputLabel', 'label', 'loading'], template })
const stubs = {
	NcModal: stub('NcModal'),
	NcSelect: stub('NcSelect', '<div class="ncselect-stub" :data-label="inputLabel" />'),
	NcTextField: stub('NcTextField', '<input />'),
	NcTextArea: stub('NcTextArea', '<textarea />'),
	NcButton: stub('NcButton', '<button><slot /></button>'),
	NcNoteCard: stub('NcNoteCard'),
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const AUTOMATIONS = [
	{ id: 'own', name: 'Intake', applicationSlug: 'permits' },
	{ id: 'same', name: 'Bevestig', applicationSlug: 'permits' },
	{ id: 'shared', name: 'Controleer adres', applicationSlug: 'subsidies', shared: true },
	{ id: 'private', name: 'Private', applicationSlug: 'subsidies' },
]

async function openWith(actions) {
	const wrapper = mount(AutomationEditDialog, {
		propsData: {
			open: false,
			automation: { id: 'own', slug: 'intake', name: 'Intake', applicationSlug: 'permits', versionUuid: 'v-1', trigger: { type: 'manual' }, actions },
			register: '',
		},
		stubs,
	})
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
	return wrapper
}

describe('AutomationEditDialog — Run another automation', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockImplementation((url) => Promise.resolve({
			data: { results: url === '/apps/openregister/api/objects/buildiq/automation' ? AUTOMATIONS : [] },
		}))
		clearAppStatusCache()
	})

	it('offers the kind and lists the app\'s own and shared automations, not itself', async () => {
		const wrapper = await openWith([{ type: 'run-automation', automation: 'shared' }])

		expect(wrapper.vm.actionTypeOptions.map((o) => o.value)).toContain('run-automation')
		expect(wrapper.vm.runAutomationChoices.map((o) => o.id)).toEqual(['same', 'shared'])
		expect(wrapper.vm.runAutomationOption('shared')).toEqual({ id: 'shared', label: 'Controleer adres (subsidies)', shared: true })
		expect(wrapper.find('[data-label="Automation to run"]').exists()).toBe(true)
	})

	it('shows a stored automation it cannot list by its uuid', async () => {
		const wrapper = await openWith([{ type: 'run-automation', automation: 'gone' }])

		expect(wrapper.vm.runAutomationOption('gone')).toEqual({ id: 'gone', label: 'gone' })
		expect(wrapper.vm.runAutomationOption('')).toBeNull()
	})

	it('refuses to save a step that names no automation', async () => {
		const wrapper = await openWith([{ type: 'run-automation' }])

		expect(wrapper.vm.incompleteStepMessage).toBe('Pick the automation this step runs.')
	})

	it('offers nothing when the automations cannot be read', async () => {
		axios.get.mockImplementation((url) => (url === '/apps/openregister/api/objects/buildiq/automation'
			? Promise.reject(new Error('down'))
			: Promise.resolve({ data: { results: [] } })))
		const wrapper = await openWith([])

		expect(wrapper.vm.runAutomationChoices).toEqual([])
		expect(wrapper.vm.runAutomationsLoading).toBe(false)
	})

	it('stores the step with only the automation it names', () => {
		expect(toPersistedAction({ type: 'run-automation', automation: ' shared ', schema: 'x' })).toEqual({
			type: 'run-automation',
			automation: 'shared',
		})
	})
})
