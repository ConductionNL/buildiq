/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for filling a new app with demo records from the create-app wizard.
 *
 * Spec: capability-requirements-backfill (REQ-BQCV-030).
 */
import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../src/utils/iconCatalogues.js', () => ({ resolveAppIcon: vi.fn(() => '') }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))

const CnWizardDialogStub = {
	name: 'CnWizardDialog',
	props: ['steps', 'defaults', 'validate'],
	methods: { setError() {} },
	template: '<div class="wizard-stub" />',
}

/**
 * Mount the wizard with its steps stubbed.
 *
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountWizard() {
	return mount(CreateApplicationWizard, {
		propsData: { show: true },
		stubs: { CnWizardDialog: CnWizardDialogStub, Step1Basics: true, Step2Preset: true, Step3Custom: true, Step4Review: true },
	})
}

const BASICS = { name: 'Meldingen', slug: 'meldingen', description: '', preset: 'single', versions: [] }

describe('CreateApplicationWizard — demo records', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('starts with demo records off', () => {
		expect(mountWizard().vm.defaults.demoRecords).toBe(false)
	})

	it('fills the new app with demo records when the maker asked, before it closes', async () => {
		axios.post.mockImplementation((url) => Promise.resolve(url === '/apps/buildiq/api/applications/wizard'
			? { status: 201, data: { applicationUuid: 'uuid-1' } }
			: { data: { created: 3 } }))
		const wrapper = mountWizard()

		await wrapper.vm.onSubmit({ ...BASICS, demoRecords: true })

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/uuid-1/demo-records')
		expect(axios.post.mock.calls[0][1]).not.toHaveProperty('demoRecords')
		expect(wrapper.emitted('created')).toEqual([['uuid-1']])
	})

	it('creates no demo records unless asked', async () => {
		axios.post.mockResolvedValue({ status: 201, data: { applicationUuid: 'uuid-1' } })

		await mountWizard().vm.onSubmit({ ...BASICS })

		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	it('still opens the new app when the demo records fail', async () => {
		axios.post.mockImplementation((url) => (url === '/apps/buildiq/api/applications/wizard'
			? Promise.resolve({ status: 201, data: { applicationUuid: 'uuid-1' } })
			: Promise.reject(new Error('refused'))))
		const wrapper = mountWizard()

		await wrapper.vm.onSubmit({ ...BASICS, demoRecords: true })

		expect(wrapper.emitted('created')).toEqual([['uuid-1']])
	})
})
