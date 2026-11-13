/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for navigateTo — a full-page navigation.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { navigateTo } from '../../src/utils/navigate.js'

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('navigateTo', () => {
	it('assigns the URL to the window location', () => {
		const assign = vi.fn()
		vi.stubGlobal('location', { assign })
		navigateTo('/apps/buildiq/#/apps')
		expect(assign).toHaveBeenCalledWith('/apps/buildiq/#/apps')
	})
})
