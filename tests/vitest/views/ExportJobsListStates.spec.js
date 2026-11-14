/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `src/views/ExportJobsList.vue`: the action per job state, the status labels,
 * polling only while a job is queued or running, and the export dialog.
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => `/index.php${path}`,
}))
vi.mock('../../../src/dialogs/ExportDialog.vue', () => ({
	default: { name: 'ExportDialog', props: ['applicationSlug'], emits: ['close', 'queued'], template: '<div class="export-dialog-stub" />' },
}))

import ExportJobsList from '../../../src/views/ExportJobsList.vue'

const PROPS = { applicationSlug: 'spectr', applicationUuid: 'app-uuid-1' }

/**
 * A fetch answer with these jobs.
 *
 * @param {Array<object>} results The jobs.
 * @return {object} The response double.
 */
function answer(results) {
	return { ok: true, json: async () => ({ results }) }
}

/**
 * Let the pending fetch and the render that follows it settle.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {Promise<void>}
 */
async function settle(wrapper) {
	for (let i = 0; i < 4; i++) {
		await Promise.resolve()
		await wrapper.vm.$nextTick()
	}
}

beforeEach(() => {
	vi.stubGlobal('fetch', vi.fn())
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('ExportJobsList job rows', () => {
	it('offers the download, the pull request or the error for each finished job', async () => {
		fetch.mockResolvedValueOnce(answer([
			{ '@self': { id: 'a' }, applicationVersion: '1.0.0', target: 'zip', status: 'succeeded', downloadUrl: '/dl/a' },
			{ '@self': { id: 'b' }, applicationVersion: '1.1.0', target: 'server', status: 'succeeded', downloadUrl: '/dl/b' },
			{ '@self': { id: 'c' }, applicationVersion: '1.2.0', target: 'github', status: 'succeeded', githubPullRequestUrl: 'https://github.com/x/y/pull/1' },
			{ '@self': { id: 'd' }, applicationVersion: '1.3.0', target: 'zip', status: 'failed', errorMessage: 'Disk full' },
			{ '@self': { id: 'e' }, applicationVersion: '1.4.0', target: 'zip', status: 'succeeded' },
		]))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)

		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(5)
		expect(rows[0].text()).toContain('Download ZIP')
		expect(rows[1].text()).toContain('Download ZIP')
		expect(rows[2].text()).toContain('View pull request')
		expect(rows[2].text()).not.toContain('Download ZIP')
		expect(rows[3].find('.export-jobs__error').text()).toBe('Disk full')
		expect(rows[4].text()).not.toContain('Download ZIP')
		expect(rows.map((row) => row.findAll('td')[2].text())).toEqual(['Succeeded', 'Succeeded', 'Succeeded', 'Failed', 'Succeeded'])
		wrapper.unmount()
	})

	it('labels the known states and shows an unknown state as it came', () => {
		const wrapper = mount(ExportJobsList, { props: { applicationSlug: 'spectr' } })

		expect(wrapper.vm.statusLabel('queued')).toBe('Queued')
		expect(wrapper.vm.statusLabel('running')).toBe('Running')
		expect(wrapper.vm.statusLabel('cancelled')).toBe('cancelled')
		wrapper.unmount()
	})

	it('fetches nothing and says there are no exports without an app uuid', async () => {
		const wrapper = mount(ExportJobsList, { props: { applicationSlug: 'spectr' } })
		await settle(wrapper)

		expect(fetch).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('No exports yet.')
		wrapper.unmount()
	})
})

describe('ExportJobsList polling', () => {
	it('polls every five seconds while a job runs, and stops once it is done', async () => {
		vi.useFakeTimers()
		fetch
			.mockResolvedValueOnce(answer([{ status: 'running', target: 'zip' }]))
			.mockResolvedValueOnce(answer([{ status: 'succeeded', target: 'zip' }]))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.poller).not.toBeNull()

		await vi.advanceTimersByTimeAsync(4999)
		expect(fetch).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1)
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.poller).toBeNull()

		await vi.advanceTimersByTimeAsync(20000)
		expect(fetch).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})

	it('does not poll an idle list', async () => {
		vi.useFakeTimers()
		fetch.mockResolvedValueOnce(answer([{ status: 'failed', target: 'zip' }]))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)

		await vi.advanceTimersByTimeAsync(30000)
		expect(fetch).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('keeps the jobs it has and keeps polling after a refused or failed request', async () => {
		vi.useFakeTimers()
		fetch
			.mockResolvedValueOnce(answer([{ status: 'queued', target: 'zip', applicationVersion: '2.0.0' }]))
			.mockResolvedValueOnce({ ok: false, json: async () => ({}) })
			.mockRejectedValueOnce(new Error('offline'))
			.mockResolvedValueOnce(answer({ not: 'a list' }))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)

		await vi.advanceTimersByTimeAsync(5000)
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(2)
		expect(wrapper.text()).toContain('2.0.0')

		await vi.advanceTimersByTimeAsync(5000)
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(3)
		expect(wrapper.text()).toContain('2.0.0')

		await vi.advanceTimersByTimeAsync(5000)
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(4)
		expect(wrapper.vm.jobs).toEqual([])
		wrapper.unmount()
	})

	it('cancels the pending poll when the page closes', async () => {
		vi.useFakeTimers()
		fetch.mockResolvedValue(answer([{ status: 'running', target: 'zip' }]))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)
		expect(wrapper.vm.poller).not.toBeNull()

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(20000)

		expect(fetch).toHaveBeenCalledTimes(1)
	})
})

describe('ExportJobsList export dialog', () => {
	it('opens the dialog with the slug, refetches when an export is queued, and closes', async () => {
		fetch.mockResolvedValue(answer([]))
		const wrapper = mount(ExportJobsList, { props: PROPS })
		await settle(wrapper)
		expect(wrapper.find('.export-dialog-stub').exists()).toBe(false)

		wrapper.vm.openDialog()
		await wrapper.vm.$nextTick()
		const dialog = wrapper.findComponent({ name: 'ExportDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('applicationSlug')).toBe('spectr')

		dialog.vm.$emit('queued')
		await settle(wrapper)
		expect(fetch).toHaveBeenCalledTimes(2)

		dialog.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.export-dialog-stub').exists()).toBe(false)
		wrapper.unmount()
	})
})
