import { mergeManifestDelta } from '@conduction/nextcloud-vue/src/utils/mergeManifestDelta.js'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recorded manifest-delta fixtures still describe the JS util buildiq
 * consumes. The PHP ManifestDeltaMerger is tested against the same file, so
 * a library change that alters the merge shows up here instead of silently
 * leaving the server-side port behind (app-delta-override 0.2, 2.3).
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const cases = JSON.parse(readFileSync(join(__dirname, '../fixtures/manifest-delta-merge.json'), 'utf8'))

describe('manifest delta merge fixtures', () => {
	it('hold enough cases to mean something', () => {
		expect(cases.length).toBeGreaterThanOrEqual(15)
	})

	it.each(cases.map((c) => [c.name, c]))('%s', (name, c) => {
		expect(mergeManifestDelta(c.base, c.delta)).toEqual(c.expected)
	})
})
