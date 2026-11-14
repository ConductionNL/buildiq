/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/manifestLabels.js`
 * (experience-multilingual-apps, REQ-BQML-002, task T02).
 */

import { describe, expect, it } from 'vitest'
import { collectManifestLabels, translatableTexts } from '../../src/services/manifestLabels.js'

const manifest = {
	menu: [
		{ id: 'permits', label: 'Vergunningen', route: 'permits', children: [{ label: 'Nieuw', route: 'new' }] },
	],
	pages: [
		{
			id: 'permits',
			type: 'index',
			title: 'Vergunningen',
			config: {
				register: 'vth',
				schema: 'permit',
				columns: ['status', { key: 'kadaster', label: 'Kadastraal perceel' }, { key: 'owner' }],
				actions: [{ id: 'approve', label: 'Goedkeuren' }],
				emptyText: 'Geen vergunningen',
				filters: { title: 'not a label' },
			},
			widgets: [{ id: 'w1', type: 'stats', title: 'Overzicht' }],
		},
		{
			id: 'new',
			type: 'form',
			title: 'Nieuw',
			config: {
				fields: [{ key: 'name', label: 'Naam', placeholder: 'Uw naam', help: 'Zoals in het paspoort' }],
			},
		},
	],
}

describe('collectManifestLabels', () => {
	it('collects menu, page titles, columns, actions, form fields, widget titles and empty states', () => {
		const texts = collectManifestLabels(manifest).filter((l) => !l.fromSchema).map((l) => l.text)

		expect(texts).toEqual([
			'Vergunningen',
			'Nieuw',
			'Kadastraal perceel',
			'Goedkeuren',
			'Geen vergunningen',
			'Overzicht',
			'Naam',
			'Uw naam',
			'Zoals in het paspoort',
		])
	})

	it('collects a duplicate text once, with every place it is used at', () => {
		const label = collectManifestLabels(manifest).find((l) => l.text === 'Vergunningen')

		expect(label.places).toEqual(['/menu/0/label', '/pages/0/title'])
		expect(collectManifestLabels(manifest).filter((l) => l.text === 'Nieuw')).toHaveLength(1)
	})

	it('lists columns without an own label as coming from the schema, last', () => {
		const labels = collectManifestLabels(manifest)
		const schema = labels.filter((l) => l.fromSchema)

		expect(schema.map((l) => [l.text, l.places])).toEqual([
			['status', ['/pages/0/config/columns/0']],
			['owner', ['/pages/0/config/columns/2/key']],
		])
		expect(labels.slice(-2)).toEqual(schema)
	})

	it('does not walk data keys such as register, schema and filters', () => {
		const texts = collectManifestLabels(manifest).map((l) => l.text)

		expect(texts).not.toContain('not a label')
		expect(texts).not.toContain('vth')
	})

	it('answers an empty list for a missing or empty manifest', () => {
		expect(collectManifestLabels(null)).toEqual([])
		expect(collectManifestLabels({})).toEqual([])
		expect(collectManifestLabels({ pages: [{ title: '  ' }] })).toEqual([])
	})

	it('translatableTexts holds the app\'s own texts only', () => {
		const texts = translatableTexts(manifest)

		expect(texts.has('Kadastraal perceel')).toBe(true)
		expect(texts.has('status')).toBe(false)
		expect(texts.size).toBe(9)
	})
})
