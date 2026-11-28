/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The offline block buildiq writes is the block the library reads
 * (pages-installable-offline-and-mobile T07). @conduction/nextcloud-vue carries
 * `offline` in its manifest schema since 2.233.0; these cases hold buildiq's
 * normaliser, caps and validator to that schema, so a later pin move that
 * changes the schema fails here first.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */

import Ajv2020 from 'ajv/dist/2020.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { validateOffline } from '../../src/services/manifestValidation/offline.js'
import {
	normaliseOffline,
	OFFLINE_LIMIT_MAX,
	OFFLINE_TAKE_ALONG_MAX,
	OFFLINE_TTL_MAX,
} from '../../src/services/offlineManifest.js'

const SCHEMA_PATH = resolve(process.cwd(), 'node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json')
const offlineSchema = JSON.parse(readFileSync(SCHEMA_PATH, 'utf8')).properties.offline
const validateWithLibrary = new Ajv2020({ strict: false, allErrors: true }).compile(offlineSchema)

const pages = [{ id: 'report', type: 'form', route: '/report' }]

function takeAlongs(count) {
	return Array.from({ length: count }, (unused, index) => ({ schema: `schema-${index}`, limit: 10, ttlHours: 1 }))
}

describe('the offline block against the library manifest schema', () => {
	it('the library schema declares the offline block', () => {
		expect(offlineSchema).toBeTruthy()
		expect(offlineSchema.properties.takeAlong).toBeTruthy()
		expect(offlineSchema.properties.forms).toBeTruthy()
	})

	it('a block buildiq normalises is valid for the library, defaults and caps applied', () => {
		const block = normaliseOffline({
			takeAlong: [
				{ schema: ' inspection ', filter: { assignee: '@me' } },
				{ schema: 'permit', limit: 9999, ttlHours: 9999 },
				{ schema: '' },
			],
			forms: ['report', 'report', ' '],
		})
		expect(validateWithLibrary(block)).toBe(true)
		expect(block.takeAlong[1]).toMatchObject({ limit: OFFLINE_LIMIT_MAX, ttlHours: OFFLINE_TTL_MAX })
	})

	it('buildiq caps each take-along the way the library does', () => {
		const entry = offlineSchema.properties.takeAlong.items.properties
		expect(OFFLINE_LIMIT_MAX).toBe(entry.limit.maximum)
		expect(OFFLINE_TTL_MAX).toBe(entry.ttlHours.maximum)
		expect(OFFLINE_TAKE_ALONG_MAX).toBe(offlineSchema.properties.takeAlong.maxItems)
	})

	it('accepts as many take-alongs as the library allows', () => {
		const offline = { takeAlong: takeAlongs(OFFLINE_TAKE_ALONG_MAX), forms: ['report'] }
		expect(validateWithLibrary(offline)).toBe(true)
		expect(validateOffline({ pages, offline })).toEqual([])
	})

	it('marks one take-along more than the library allows, as the library does', () => {
		const offline = { takeAlong: takeAlongs(OFFLINE_TAKE_ALONG_MAX + 1) }
		expect(validateWithLibrary(offline)).toBe(false)
		expect(validateOffline({ pages, offline })).toEqual(['/offline/takeAlong: buildiq.offline.error.too-many'])
	})

	it('a block buildiq marks for an out-of-range entry is refused by the library too', () => {
		const offline = { takeAlong: [{ schema: 'inspection', limit: OFFLINE_LIMIT_MAX + 1, ttlHours: OFFLINE_TTL_MAX + 1 }] }
		expect(validateWithLibrary(offline)).toBe(false)
		expect(validateOffline({ pages, offline })).toEqual([
			'/offline/takeAlong/0/limit: buildiq.offline.error.limit-range',
			'/offline/takeAlong/0/ttlHours: buildiq.offline.error.ttl-range',
		])
	})
})
