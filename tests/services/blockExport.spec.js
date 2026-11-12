/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component-block export and import: the envelope drops instance identity on
 * the way out and on the way in, and a malformed file is refused by code.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	BLOCK_EXPORT_KIND,
	BLOCK_EXPORT_SCHEMA_VERSION,
	BlockImportError,
	downloadBlockExport,
	exportBlockPayload,
	parseBlockImport,
} from '../../src/services/blockExport.js'

const BLOCK = { uuid: 'u1', createdBy: 'alice', '@self': { id: 'u1' }, slug: 'hero', name: 'Hero', tree: [{ type: 'h1' }] }

describe('exportBlockPayload', () => {
	it('wraps the block without uuid, creator or @self', () => {
		expect(exportBlockPayload(BLOCK)).toEqual({
			schemaVersion: BLOCK_EXPORT_SCHEMA_VERSION,
			kind: BLOCK_EXPORT_KIND,
			block: { slug: 'hero', name: 'Hero', tree: [{ type: 'h1' }] },
		})
	})

	it('exports an empty block for nothing', () => {
		expect(exportBlockPayload(null).block).toEqual({})
	})
})

describe('downloadBlockExport', () => {
	afterEach(() => vi.restoreAllMocks())

	it.each([
		[BLOCK, 'hero.json'],
		[{ name: 'No slug' }, 'component-block.json'],
	])('downloads the envelope as JSON', async (block, filename) => {
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		let blob
		URL.createObjectURL = vi.fn((b) => {
			blob = b
			return 'blob:1'
		})
		URL.revokeObjectURL = vi.fn()

		downloadBlockExport(block)

		const link = click.mock.contexts[0]
		expect(link.download).toBe(filename)
		expect(link.href).toBe('blob:1')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1')
		expect(blob.type).toBe('application/json')
		expect(JSON.parse(await blob.text())).toEqual(exportBlockPayload(block))
	})
})

describe('parseBlockImport', () => {
	it('returns the block of a valid envelope without identity', () => {
		const envelope = { schemaVersion: '1.0', kind: BLOCK_EXPORT_KIND, block: { uuid: 'x', createdBy: 'bob', slug: 'hero' } }
		expect(parseBlockImport(JSON.stringify(envelope))).toEqual({ slug: 'hero' })
	})

	it('round-trips an export', () => {
		expect(parseBlockImport(JSON.stringify(exportBlockPayload(BLOCK)))).toEqual({ slug: 'hero', name: 'Hero', tree: [{ type: 'h1' }] })
	})

	it('refuses unparsable JSON as invalid-json', () => {
		expect(() => parseBlockImport('{nope')).toThrow(expect.objectContaining({ name: 'BlockImportError', code: 'invalid-json' }))
	})

	it('refuses another kind of envelope as invalid-shape', () => {
		const other = { schemaVersion: '1.0', kind: 'form-template', form: {} }
		let caught
		try {
			parseBlockImport(JSON.stringify(other))
		} catch (e) {
			caught = e
		}
		expect(caught).toBeInstanceOf(BlockImportError)
		expect(caught.code).toBe('invalid-shape')
		expect(caught.message).toBe('buildiq.blocks.import.error.invalid-shape')
	})
})
