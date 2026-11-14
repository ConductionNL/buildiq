/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationsPage.vue row actions and failure paths: recompile,
 * delete, enable/disable failures, approval badges, the edit/test dialogs, and
 * the deep-link and URL-sync edges.
 *
 * Spec: automation-designer (REQ-AUTD-001, REQ-AUTD-006).
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationsPage from '../../src/views/AutomationsPage.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'loading', 'inputLabel', 'disabled'], template: '<div class="ncselect-stub" />' },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<label class="ncswitch-stub" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div class="ncloading-stub" />' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="ncempty-stub">{{ name }}</div>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="ncnotecard-stub"><slot /></div>' },
	AutomationEditDialog: { name: 'AutomationEditDialog', props: ['open', 'automation', 'register'], emits: ['saved', 'update:open'], template: '<div class="edit-dialog-stub" />' },
	AutomationTestPanelModal: { name: 'AutomationTestPanelModal', props: ['automation'], emits: ['close'], template: '<div class="test-panel-stub" />' },
	FlowPickerDialog: { name: 'FlowPickerDialog', props: ['app'], template: '<div class="flow-picker-stub" />' },
	ConfirmActionDialog: { name: 'ConfirmActionDialog', props: ['open', 'busy', 'name', 'message', 'confirmLabel', 'destructive'], emits: ['confirm', 'update:open'], template: '<div class="confirm-stub" />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const application = { slug: 'permit-tracker', name: 'Permit Tracker' }
const version = { id: 'version-1', slug: 'development', name: 'Development', register: 'openbuild-permit-tracker-development' }

/**
 * One automation of the selected version.
 *
 * @param {object} overrides Field overrides.
 * @return {object}
 */
function automation(overrides = {}) {
	return {
		id: 'aut-1',
		slug: 'notify-caseworkers',
		name: 'Notify case workers',
		applicationSlug: 'permit-tracker',
		versionUuid: 'version-1',
		enabled: true,
		trigger: { type: 'object-created' },
		actions: [{ type: 'send-notification' }],
		...overrides,
	}
}

/**
 * Route every GET the page makes; any entry may be a rejection.
 *
 * @param {object} answers Overrides keyed by applications/versions/automations/status.
 * @return {void}
 */
function serve(answers = {}) {
	const reply = (key, fallback) => (key in answers ? answers[key] : fallback)
	axios.get.mockImplementation((url) => {
		let value
		if (url.endsWith('/status')) {
			value = typeof answers.status === 'function' ? answers.status(url) : reply('status', { drift: false })
		} else if (url.includes('/versions')) {
			value = reply('versions', { results: [version] })
		} else if (url.includes('/objects/buildiq/automation')) {
			value = reply('automations', { results: [automation()] })
		} else {
			value = reply('applications', { results: [application] })
		}
		return value instanceof Error ? Promise.reject(value) : Promise.resolve({ data: value })
	})
}

/**
 * Mount the page deep-linked onto the permit-tracker development version.
 *
 * @param {object} [query] The route query.
 * @return {Promise<object>} The wrapper, settled.
 */
async function mountOnVersion(query = { app: 'permit-tracker', version: 'development' }) {
	const $router = { replace: vi.fn().mockResolvedValue(undefined), push: vi.fn() }
	const wrapper = mount(AutomationsPage, { global: { stubs, mocks: { $router, $route: { query } } } })
	for (let i = 0; i < 4; i++) {
		await flush()
	}
	return wrapper
}

/**
 * The row button with the given label.
 *
 * @param {object} wrapper The mounted page.
 * @param {string} label The button text.
 * @return {object}
 */
function rowButton(wrapper, label) {
	return wrapper.find('[data-testid="automation-row"]').findAll('button').find((b) => b.text() === label)
}

describe('AutomationsPage — actions and failures', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
		serve()
	})

	it('falls back to the first version when the link names an unknown one', async () => {
		const wrapper = await mountOnVersion({ app: 'permit-tracker', version: 'nope' })

		expect(wrapper.vm.selectedVersionId).toBe('version-1')
		expect(wrapper.findAll('[data-testid="automation-row"]')).toHaveLength(1)
	})

	it('stays on an empty page when the link names an unknown app, or the app has no versions', async () => {
		const unknown = await mountOnVersion({ app: 'nope' })
		expect(unknown.vm.selectedApp).toBeNull()

		serve({ versions: { results: [] } })
		const noVersions = await mountOnVersion()
		expect(noVersions.vm.selectedApp).toEqual(application)
		expect(noVersions.vm.selectedVersion).toBeNull()
		expect(noVersions.find('.automations-page__hint').exists()).toBe(true)
	})

	it('shows an error when the versions cannot be loaded', async () => {
		serve({ versions: new Error('500') })
		const wrapper = await mountOnVersion()

		expect(wrapper.vm.loadingVersions).toBe(false)
		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Could not load versions.')
	})

	it('shows an error when the automations cannot be loaded', async () => {
		serve({ automations: new Error('500') })
		const wrapper = await mountOnVersion()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Could not load automations.')
	})

	it('treats a status that cannot be read as no drift and no approval state', async () => {
		serve({ status: new Error('404') })
		const wrapper = await mountOnVersion()

		expect(wrapper.vm.statusByUuid).toEqual({ 'aut-1': null })
		expect(wrapper.find('[data-testid="drift-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="approval-state-badge"]').exists()).toBe(false)
	})

	it.each([
		['pending', 'Approval pending'],
		['approved', 'Approved'],
		['rejected', 'Rejected'],
		['escalated', 'escalated'],
	])('labels the %s approval state', async (state, label) => {
		serve({ status: { drift: false, approvalState: state } })
		const wrapper = await mountOnVersion()

		const badge = wrapper.find('[data-testid="approval-state-badge"]')
		expect(badge.text()).toBe(label)
		expect(badge.classes()).toContain(`automations-page__approval-badge--${state}`)
	})

	it('shows no approval badge for the none state', async () => {
		serve({ status: { approvalState: 'none' } })
		const wrapper = await mountOnVersion()

		expect(wrapper.find('[data-testid="approval-state-badge"]').exists()).toBe(false)
	})

	it.each([
		[{}, 'No trigger · No actions'],
		[{ trigger: { type: 'schedule' }, actions: 'not-a-list' }, 'On a schedule · No actions'],
		[{ trigger: { type: 'lifecycle-transition' }, actions: [{ type: 'a' }, { type: 'b' }] }, 'On lifecycle transition · a, b'],
		[{ trigger: { type: 'unknown' }, actions: [] }, 'No trigger · No actions'],
	])('summarises trigger and actions of %j', async (fields, summary) => {
		serve({ automations: { results: [{ ...automation(), trigger: undefined, actions: undefined, ...fields }] } })
		const wrapper = await mountOnVersion()

		expect(wrapper.find('.automations-page__item-meta').text().replace(/\s+/g, ' ')).toBe(summary)
	})

	it('recompiles a drifted automation and reloads the list', async () => {
		serve({ status: { drift: true } })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountOnVersion()
		const loads = axios.get.mock.calls.length

		await rowButton(wrapper, 'Recompile (overwrite)').trigger('click')
		await flush()
		await flush()

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/automations/aut-1/compile', {})
		expect(axios.get.mock.calls.length).toBeGreaterThan(loads)
		expect(wrapper.find('.ncnotecard-stub').exists()).toBe(false)
	})

	it('reports a failed recompile', async () => {
		serve({ status: { drift: true } })
		axios.post.mockRejectedValue(new Error('500'))
		const wrapper = await mountOnVersion()

		await rowButton(wrapper, 'Recompile (overwrite)').trigger('click')
		await flush()

		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Recompile failed.')
	})

	it.each([
		[true, 'Could not disable the automation.', 'disable'],
		[false, 'Could not enable the automation.', 'enable'],
	])('reports a failed toggle of an automation with enabled=%s', async (enabled, message, action) => {
		serve({ automations: { results: [automation({ enabled })] } })
		axios.post.mockRejectedValue(new Error('403'))
		const wrapper = await mountOnVersion()

		await wrapper.find('.ncswitch-stub').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledWith(`/apps/buildiq/api/automations/aut-1/${action}`, {})
		expect(wrapper.find('.ncnotecard-stub').text()).toBe(message)
	})

	it('deletes after confirmation through the buildiq endpoint and resets the dialog', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = await mountOnVersion()

		await rowButton(wrapper, 'Delete').trigger('click')
		const confirm = wrapper.findComponent({ name: 'ConfirmActionDialog' })
		expect(confirm.props('open')).toBe(true)
		expect(axios.delete).not.toHaveBeenCalled()

		confirm.vm.$emit('confirm')
		await flush()
		await flush()

		expect(axios.delete).toHaveBeenCalledWith('/apps/buildiq/api/automations/aut-1')
		expect(wrapper.vm.confirmDeleteOpen).toBe(false)
		expect(wrapper.vm.pendingDelete).toBeNull()
		expect(wrapper.vm.deleting).toBe(false)
	})

	it('reports a failed delete and still closes the dialog', async () => {
		axios.delete.mockRejectedValue(new Error('403'))
		const wrapper = await mountOnVersion()

		await rowButton(wrapper, 'Delete').trigger('click')
		wrapper.findComponent({ name: 'ConfirmActionDialog' }).vm.$emit('confirm')
		await flush()
		await flush()

		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Could not delete the automation.')
		expect(wrapper.vm.confirmDeleteOpen).toBe(false)
		expect(wrapper.vm.pendingDelete).toBeNull()
	})

	it('a confirm with nothing pending only closes the dialog', async () => {
		const wrapper = await mountOnVersion()
		wrapper.vm.confirmDeleteOpen = true

		await wrapper.vm.onConfirmDelete()

		expect(axios.delete).not.toHaveBeenCalled()
		expect(wrapper.vm.confirmDeleteOpen).toBe(false)
	})

	it('opens a blank automation scoped to the selected app and version', async () => {
		const wrapper = await mountOnVersion()

		await wrapper.findAll('button').find((b) => b.text() === 'New automation').trigger('click')

		const dialog = wrapper.findComponent({ name: 'AutomationEditDialog' })
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('register')).toBe('openbuild-permit-tracker-development')
		expect(dialog.props('automation')).toEqual({
			slug: '',
			name: '',
			description: '',
			applicationSlug: 'permit-tracker',
			versionUuid: 'version-1',
			enabled: true,
			trigger: { type: 'manual' },
			condition: null,
			actions: [],
		})
	})

	it('edits an existing automation and reloads after it is saved', async () => {
		const wrapper = await mountOnVersion()
		await rowButton(wrapper, 'Edit').trigger('click')
		const dialog = wrapper.findComponent({ name: 'AutomationEditDialog' })
		expect(dialog.props('automation')).toEqual(automation())
		const loads = axios.get.mock.calls.length

		dialog.vm.$emit('saved')
		await flush()

		expect(wrapper.vm.editDialogOpen).toBe(false)
		expect(axios.get.mock.calls.length).toBeGreaterThan(loads)
	})

	it('opens the test panel for a row and closes it on close', async () => {
		const wrapper = await mountOnVersion()

		await rowButton(wrapper, 'Test').trigger('click')
		const panel = wrapper.findComponent({ name: 'AutomationTestPanelModal' })
		expect(panel.props('automation')).toEqual(automation())

		panel.vm.$emit('close')
		await flush()
		expect(wrapper.find('.test-panel-stub').exists()).toBe(false)
	})

	it('does not rewrite the URL when it already names the selection', async () => {
		const wrapper = await mountOnVersion({ app: 'permit-tracker', version: 'development' })

		wrapper.vm.syncQuery()

		expect(wrapper.vm.$router.replace).not.toHaveBeenCalled()
	})

	it('writes the app alone into the URL after the version is cleared, and tolerates a duplicate navigation', async () => {
		const wrapper = await mountOnVersion()
		wrapper.vm.$router.replace.mockRejectedValueOnce(new Error('NavigationDuplicated'))

		wrapper.vm.selectedVersion = null
		wrapper.vm.onVersionChange()
		await flush()

		expect(wrapper.vm.$router.replace).toHaveBeenCalledWith({ query: { app: 'permit-tracker' } })
		expect(wrapper.vm.automations).toEqual([])
	})

	it('skips URL sync without a router, and clearing the app loads no versions', async () => {
		const wrapper = mount(AutomationsPage, { global: { stubs } })
		await flush()
		const loads = axios.get.mock.calls.length

		wrapper.vm.selectedApp = null
		wrapper.vm.onAppChange()
		await flush()

		expect(axios.get.mock.calls.length).toBe(loads)
		expect(wrapper.vm.versions).toEqual([])
	})
})
