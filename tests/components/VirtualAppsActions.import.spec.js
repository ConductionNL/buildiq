/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * VirtualAppsActions' "Import app" button: it opens ImportAppDialog, and an
 * import reports each schema's records and opens the new app.
 *
 * spec: openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const showSuccess = vi.fn()
const showWarning = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...a) => showSuccess(...a), showWarning: (...a) => showWarning(...a) }))

function nFill (_app, one, many, count, vars = {}) {
  return Object.entries(vars)
	.reduce((out, [key, value]) => out.replaceAll('{' + key + '}', String(value)), (count === 1 ? one : many).replace('%n', String(count)))
}
globalThis.n = nFill

const { default: VirtualAppsActions } = await import('../../src/components/VirtualAppsActions.vue')

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant'],
	emits: ['click'],
	template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')"><slot /></button>',
}
const DialogStub = {
	name: 'ImportAppDialog',
	props: ['open'],
	emits: ['update:open', 'imported'],
	template: '<div class="import-stub" :data-open="open ? \'yes\' : \'no\'" />',
}

/**
 * Mount the actions bar with a router mock.
 *
 * @param {object} router The router mock.
 * @return {object} The wrapper.
 */
function mountWith(router) {
	return mount(VirtualAppsActions, {
		global: {
			mocks: { $route: { query: {} }, $router: router, n: nFill },
			stubs: { NcButton: NcButtonStub, CreateApplicationWizard: true, ImportAppDialog: DialogStub },
		},
	})
}

beforeEach(() => {
	showSuccess.mockReset()
	showWarning.mockReset()
})

describe('VirtualAppsActions import', () => {
	it('opens the import dialog from the "Import app" button', async () => {
		const wrapper = mountWith({ push: vi.fn() })
		expect(wrapper.find('.import-stub').attributes('data-open')).toBe('no')

		const button = wrapper.find('[data-testid="import-app"]')
		expect(button.text()).toBe('Import app')
		await button.trigger('click')

		expect(wrapper.find('.import-stub').attributes('data-open')).toBe('yes')
	})

	it('reports each schema\'s records and opens the imported app', async () => {
		const router = { push: vi.fn(() => Promise.resolve()) }
		const wrapper = mountWith(router)

		wrapper.findComponent(DialogStub).vm.$emit('imported', {
			uuid: 'app-1',
			records: {
				schemas: [
					{ schema: 'permit', imported: 40, failed: 0 },
					{ schema: 'zaak', imported: 0, failed: 3, error: 'import_failed' },
				],
			},
		})

		expect(showSuccess.mock.calls).toEqual([['40 permit records imported']])
		expect(showWarning.mock.calls).toEqual([['3 zaak records failed to import']])
		expect(router.push).toHaveBeenCalledWith({ name: 'VirtualAppDetail', params: { objectId: 'app-1' } })
	})

	it('opens the imported app without a word about records when none were asked', () => {
		const router = { push: vi.fn(() => Promise.resolve()) }
		const wrapper = mountWith(router)

		wrapper.findComponent(DialogStub).vm.$emit('imported', { uuid: 'app-2' })

		expect(showSuccess).not.toHaveBeenCalled()
		expect(showWarning).not.toHaveBeenCalled()
		expect(router.push).toHaveBeenCalledWith({ name: 'VirtualAppDetail', params: { objectId: 'app-2' } })
	})
})
