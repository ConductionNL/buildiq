/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for resolveAppIcon's named-glyph path and buildIconCatalogues.
 *
 * Covers:
 *  - an MDI name renders on a 24-unit box, an OpenGemeenten key on its own box.
 *  - the white fill is dropped on a dark background.
 *  - surrounding whitespace is ignored; an unknown name is null.
 *  - quotes in a path cannot break out of the d attribute.
 *  - both catalogues are built.
 */

import * as mdiJs from '@mdi/js'
import { describe, expect, it } from 'vitest'
import { buildIconCatalogues, resolveAppIcon } from '../../src/utils/iconCatalogues.js'
import openGemeentenIcons from '../../src/utils/openGemeentenIcons.json'

describe('resolveAppIcon named glyphs', () => {
	it('renders an MDI name with a white fill on the 24-unit box', () => {
		expect(resolveAppIcon('mdiAccount')).toBe(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			+ `<path d="${mdiJs.mdiAccount}" fill="#ffffff"/></svg>`,
		)
	})

	it('drops the fill on a dark background and ignores surrounding space', () => {
		expect(resolveAppIcon('  mdiAccount ', { dark: true })).toBe(
			`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiJs.mdiAccount}"/></svg>`,
		)
	})

	it('renders an OpenGemeenten key on its own box', () => {
		const icon = openGemeentenIcons[0]
		const out = resolveAppIcon(icon.key)
		expect(out).toContain(`viewBox="${icon.viewBox || '0 0 48 48'}"`)
		expect(out).toContain(icon.path.slice(0, 20))
		expect(out).toContain('fill="#ffffff"')
	})

	it('returns null for a name in neither catalogue', () => {
		expect(resolveAppIcon('noSuchIcon')).toBeNull()
		expect(resolveAppIcon('   ')).toBeNull()
	})

	it('builds both catalogues', () => {
		const catalogues = buildIconCatalogues()
		expect(Object.keys(catalogues)).toEqual(['mdi', 'opengemeenten'])
		expect(catalogues.mdi).toBeTruthy()
		expect(catalogues.opengemeenten).toBeTruthy()
	})
})
