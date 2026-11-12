/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RollbackConfirmModal — confirming a rollback to a published version.
 *
 * Covers:
 *  - the title names the version.
 *  - the published date is formatted, and empty without one.
 *  - confirm forwards the version and closes; cancel and an outside close cancel.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RollbackConfirmModal from '../../src/modals/RollbackConfirmModal.vue'

const VERSION = { version: '1.4.0', publishedAt: '2026-03-01T10:00:00Z' }

function mountModal(version = VERSION) {
	return shallowMount(RollbackConfirmModal, { props: { open: true, version } })
}

describe('RollbackConfirmModal', () => {
	it('titles the dialog with the version string', () => {
		expect(mountModal().vm.title).toBe('Roll back to version {version}?')
	})

	it('formats the published date for the reader', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.formattedPublishedAt).toBe(new Date(VERSION.publishedAt).toLocaleString())
	})

	it('shows no date for an unpublished or missing version', () => {
		expect(mountModal({ version: '1.0.0' }).vm.formattedPublishedAt).toBe('')
		expect(mountModal(null).vm.formattedPublishedAt).toBe('')
	})

	it('confirm forwards the version and closes', () => {
		const wrapper = mountModal()
		wrapper.vm.confirm()
		expect(wrapper.emitted('confirm')).toEqual([[VERSION]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('cancel')).toBeFalsy()
	})

	it('cancel cancels and closes', () => {
		const wrapper = mountModal()
		wrapper.vm.cancel()
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('an outside close cancels; an open update does not', () => {
		const wrapper = mountModal()
		wrapper.vm.onUpdateOpen(true)
		expect(wrapper.emitted('cancel')).toBeFalsy()
		wrapper.vm.onUpdateOpen(false)
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toEqual([[true], [false]])
	})
})
