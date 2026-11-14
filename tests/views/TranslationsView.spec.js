/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/views/TranslationsView.vue`
 * (experience-multilingual-apps, REQ-BQML-002, task T04): editing, the
 * missing count, the missing-only filter, schema labels and undo through the
 * designer's history.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TranslationsView from '../../src/views/TranslationsView.vue'
import { useSessionHistory } from '../../src/composables/useSessionHistory.js'

const manifest = {
	menu: [{ label: 'Vergunningen', route: 'permits' }],
	pages: [
		{
			id: 'permits',
			type: 'index',
			title: 'Vergunningen',
			config: { columns: ['status', { key: 'parcel', label: 'Kadastraal perceel' }] },
		},
		{ id: 'new', type: 'form', title: 'Nieuw', config: { fields: [{ key: 'n', label: 'Naam' }] } },
	],
	i18n: {
		sourceLanguage: 'nl',
		languages: ['en'],
		labels: { en: { Vergunningen: 'Permits', Nieuw: 'New' } },
	},
}

/**
 * Mount the view on a manifest.
 *
 * @param {object} m The manifest.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountView(m = manifest) {
	return mount(TranslationsView, { props: { manifest: m } })
}

describe('TranslationsView (REQ-BQML-002)', () => {
	it('lists each text once with its places and a column per language', () => {
		const wrapper = mountView()
		const rows = wrapper.findAll('[data-test="translations-row"]')

		expect(rows.map((r) => r.find('th').text())).toEqual(['Vergunningen', 'Kadastraal perceel', 'Nieuw', 'Naam', 'status'])
		expect(rows[0].find('.translations-view__places').text()).toBe('Menu, Vergunningen')
		expect(rows[0].find('[data-test="translation-en"]').element.value).toBe('Permits')
	})

	it('shows how many labels each language is missing', () => {
		expect(mountView().find('[data-test="translations-missing-counts"]').text()).toBe('English (en): 2 missing')
	})

	it('filters to missing labels only, leaving schema labels out', async () => {
		const wrapper = mountView()

		await wrapper.find('[data-test="translations-missing-only"]').setValue(true)

		expect(wrapper.findAll('[data-test="translations-row"]').map((r) => r.find('th').text())).toEqual(['Kadastraal perceel', 'Naam'])
	})

	it('filters to one page', async () => {
		const wrapper = mountView()

		await wrapper.find('[data-test="translations-page-filter"]').setValue('/pages/1')

		expect(wrapper.findAll('[data-test="translations-row"]').map((r) => r.find('th').text())).toEqual(['Nieuw', 'Naam'])
	})

	it('shows a schema label as not translatable here', () => {
		const row = mountView().findAll('[data-test="translations-row"]').at(-1)

		expect(row.find('[data-test="translations-from-schema"]').exists()).toBe(true)
		expect(row.find('input').exists()).toBe(false)
	})

	it('emits the whole manifest with the edited translation', async () => {
		const wrapper = mountView()
		const input = wrapper.findAll('[data-test="translations-row"]')[1].find('[data-test="translation-en"]')

		await input.setValue('Cadastral parcel')

		const [next] = wrapper.emitted('update:manifest').at(-1)
		expect(next.i18n.labels.en).toEqual({ Vergunningen: 'Permits', Nieuw: 'New', 'Kadastraal perceel': 'Cadastral parcel' })
		expect(next.pages).toEqual(manifest.pages)
	})

	it('does not emit when a value is committed unchanged', async () => {
		const wrapper = mountView()

		await wrapper.findAll('[data-test="translations-row"]')[0].find('[data-test="translation-en"]').setValue('Permits')

		expect(wrapper.emitted('update:manifest')).toBeUndefined()
	})

	it('an edit is undone through the designer\'s history', async () => {
		const history = useSessionHistory(manifest, { limit: 100 })
		const wrapper = mountView()
		await wrapper.findAll('[data-test="translations-row"]')[3].find('[data-test="translation-en"]').setValue('Name')
		const [next] = wrapper.emitted('update:manifest').at(-1)
		history.push(next)

		expect(history.canUndo.value).toBe(true)
		expect(history.undo().i18n.labels.en).toEqual({ Vergunningen: 'Permits', Nieuw: 'New' })
	})

	it('points at the app settings when the app has no extra languages', () => {
		const wrapper = mountView({ pages: [], menu: [] })

		expect(wrapper.find('[data-test="translations-no-languages"]').exists()).toBe(true)
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('closes', async () => {
		const wrapper = mountView()
		await wrapper.find('[data-test="translations-close"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
