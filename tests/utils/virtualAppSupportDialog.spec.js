// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A virtual app shows the first-open support note only when its author
 * switched it on. Before, every virtual app and the page designer preview
 * opened Buildiq's own donation note as "Support Openbuild-{slug}".
 */

import { describe, expect, it } from 'vitest'
import { virtualAppSupportDialog } from '../../src/utils/virtualAppSupportDialog.js'

describe('virtualAppSupportDialog', () => {
	it('is off for a manifest without a support block', () => {
		expect(virtualAppSupportDialog({ pages: [] })).toBe(false)
	})

	it('is off for a missing manifest', () => {
		expect(virtualAppSupportDialog(null)).toBe(false)
		expect(virtualAppSupportDialog(undefined)).toBe(false)
	})

	it('is off when the author switched it off', () => {
		expect(virtualAppSupportDialog({ support: { enabled: false } })).toBe(false)
	})

	it('is off for a support block that does not say enabled', () => {
		expect(virtualAppSupportDialog({ support: { title: 'Help us' } })).toBe(
			false,
		)
		expect(virtualAppSupportDialog({ support: true })).toBe(false)
	})

	it('is on only when the author switched it on', () => {
		expect(virtualAppSupportDialog({ support: { enabled: true } })).toBe(true)
	})
})
