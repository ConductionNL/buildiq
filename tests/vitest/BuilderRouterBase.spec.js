// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// openbuild-connector-widget-runtime 5.3: a built app's deep link opens the
// page it names whether Nextcloud served it with or without /index.php.

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

const { builderRouterBase } = await import('../../src/utils/builderRouterBase.js')

describe('builderRouterBase', () => {
	it('takes the base from a pretty URL', () => {
		expect(builderRouterBase('/apps/buildiq/builder/pw-app/cwr-dashboard', 'pw-app')).toBe('/apps/buildiq/builder/pw-app')
	})

	it('takes the base from an /index.php URL', () => {
		expect(builderRouterBase('/index.php/apps/buildiq/builder/pw-app/cwr-dashboard', 'pw-app')).toBe('/index.php/apps/buildiq/builder/pw-app')
	})

	it('keeps a Nextcloud installed under a sub-path', () => {
		expect(builderRouterBase('/cloud/apps/buildiq/builder/pw-app/', 'pw-app')).toBe('/cloud/apps/buildiq/builder/pw-app')
		expect(builderRouterBase('/cloud/index.php/apps/buildiq/builder/pw-app', 'pw-app')).toBe('/cloud/index.php/apps/buildiq/builder/pw-app')
	})

	it('does not take a longer slug for the app\'s own', () => {
		expect(builderRouterBase('/apps/buildiq/builder/pw-app-two/page', 'pw-app')).toBe('/index.php/apps/buildiq/builder/pw-app')
	})

	it('reads a slug with characters a pattern would treat as special', () => {
		expect(builderRouterBase('/apps/buildiq/builder/a.b/page', 'a.b')).toBe('/apps/buildiq/builder/a.b')
		expect(builderRouterBase('/apps/buildiq/builder/axb/page', 'a.b')).toBe('/index.php/apps/buildiq/builder/a.b')
	})

	it('falls back to generateUrl when the path does not carry the app', () => {
		expect(builderRouterBase('/apps/files/', 'pw-app')).toBe('/index.php/apps/buildiq/builder/pw-app')
		expect(builderRouterBase(undefined, 'pw-app')).toBe('/index.php/apps/buildiq/builder/pw-app')
		expect(builderRouterBase('/apps/buildiq/builder/', '')).toBe('/index.php/apps/buildiq/builder/')
	})
})
