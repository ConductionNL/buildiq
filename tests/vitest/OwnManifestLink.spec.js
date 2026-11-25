// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// pages-installable-offline-and-mobile REQ-BQOM-001: the installed app takes
// its own name and colour, so the run page keeps only its own manifest link.

import { describe, expect, it } from 'vitest'
import { keepOwnManifestLink } from '../../src/utils/ownManifestLink.js'

describe('keepOwnManifestLink', () => {
	it('drops Nextcloud\'s manifest link and keeps the app\'s', () => {
		document.head.innerHTML = '<link rel="manifest" href="/apps/theming/manifest/buildiq?v=1"><link rel="stylesheet" href="a.css"><link rel="manifest" href="manifest.webmanifest" crossorigin="use-credentials">'

		expect(keepOwnManifestLink(document)).toBe(1)

		const links = document.querySelectorAll('link[rel="manifest"]')
		expect(links).toHaveLength(1)
		expect(links[0].getAttribute('href')).toBe('manifest.webmanifest')
		expect(document.querySelectorAll('link[rel="stylesheet"]')).toHaveLength(1)
	})

	it('leaves a page without the app\'s own manifest as it is', () => {
		document.head.innerHTML = '<link rel="manifest" href="/apps/theming/manifest/buildiq?v=1">'

		expect(keepOwnManifestLink(document)).toBe(0)
		expect(document.querySelectorAll('link[rel="manifest"]')).toHaveLength(1)
	})
})
