/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The other half of `tests/Unit/Support/ManifestWidgetShapeTest.php`. That
 * test pins what PHP builds against `tests/Fixtures/copilot-widget-shape.json`;
 * this one runs the same fixture through the canonical `validateManifest()`
 * the wizard gates on.
 *
 * Neither test alone would have caught the defect. The PHP side had no idea
 * what the validator wanted, and the JS side had no idea what PHP wrote, so a
 * builder tool appended `{type, config}` for months and the only symptom was a
 * disabled button telling the user to rephrase their brief.
 */
import { validateManifest } from '@conduction/nextcloud-vue'
import { describe, expect, it } from 'vitest'
import fixture from '../Fixtures/copilot-widget-shape.json'

/**
 * Wrap the fixture page in the smallest manifest that can carry it.
 *
 * @param {object} page - the page under test.
 * @return {object} A complete v1 manifest.
 */
function manifestWith(page) {
	return { version: '1.0.0', menu: [], pages: [page] }
}

describe('the widget shape the builder tools write', () => {
	it('passes the canonical validator', () => {
		const result = validateManifest(manifestWith(fixture.page))

		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})

	it('is rejected once a widget loses its id or title', () => {
		for (const key of ['id', 'title']) {
			const page = JSON.parse(JSON.stringify(fixture.page))
			delete page.config.widgets[0][key]

			const result = validateManifest(manifestWith(page))

			expect(result.valid).toBe(false)
			expect(result.errors.join('\n')).toContain(
				`/pages/0/config/widgets/0/${key}`,
			)
		}
	})

	it('places every widget it stores', () => {
		const widgetIds = fixture.page.config.widgets.map((w) => w.id)
		const placed = fixture.page.config.layout.map((l) => l.widgetId)

		expect(placed).toEqual(widgetIds)
		expect(new Set(widgetIds).size).toBe(widgetIds.length)
	})
})
