// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Buildiq's own manifest declares the GitHub credential it uses.
 *
 * CnAppRoot hands `manifest.credentials` to the Credentials panel in the user
 * settings. With nothing declared, the panel said "This app does not use any
 * external credentials" while publishing and the store both use a GitHub
 * token. The declared reason is also the only place Buildiq can tell the user
 * which token permissions publishing needs.
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

describe('src/manifest.json credentials', () => {
	it('declares the GitHub credential with the permissions publishing needs', () => {
		const github = (manifest.credentials || []).find(
			(c) => c.provider === 'github',
		)

		expect(github).toBeDefined()
		expect(github.reason).toMatch(
			/Administration and Contents set to read and write/,
		)
		expect(github.reason).toMatch(/Metadata set to read-only/)
		expect(github.reason).not.toMatch(/—/)
		expect(github.scopes).toEqual([
			'administration:write',
			'contents:write',
			'metadata:read',
		])
	})
})
