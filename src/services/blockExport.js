// SPDX-License-Identifier: EUPL-1.2
/**
 * blockExport — export/import a `ComponentBlock` as a standalone JSON file
 * (design.md D4).
 *
 * A block is a single small OR object, not a whole-Application export job
 * (unlike `ExportJobService`/`ExportsController`, which submit an async job
 * and poll/download a produced artifact for a full app zip/GitHub push).
 * This module reuses the SAME download shape the app's other "export as
 * JSON" flow already uses client-side (`RuleSetsPage.vue#exportRuleSet` —
 * `Blob` + `URL.createObjectURL` + a synthetic anchor click) rather than
 * routing a single-object download through the async job pipeline built
 * for multi-file app exports.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */

/** The export envelope's `kind` discriminator (design.md D4). */
export const BLOCK_EXPORT_KIND = 'component-block'

/** The export envelope's schema version. */
export const BLOCK_EXPORT_SCHEMA_VERSION = '1.0'

/**
 * Error thrown when an imported file is not a recognisable block export.
 */
export class BlockImportError extends Error {

	/**
	 * @param {string} code - `invalid-json` | `invalid-shape`.
	 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
	 */
	constructor(code) {
		super(`openbuild.blocks.import.error.${code}`)
		this.name = 'BlockImportError'
		this.code = code
	}

}

/**
 * Build the standalone export envelope for a `ComponentBlock` record —
 * `{ schemaVersion, kind: "component-block", block }` with `uuid` /
 * `createdBy` stripped (they are per-instance identity, not part of the
 * portable definition).
 *
 * @param {object} block - the `ComponentBlock` record (as returned by OR REST).
 * @return {{schemaVersion: string, kind: string, block: object}} the export envelope.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function exportBlockPayload(block) {
	const source = block || {}
	// eslint-disable-next-line no-unused-vars
	const { uuid, createdBy, '@self': self, ...rest } = source
	return {
		schemaVersion: BLOCK_EXPORT_SCHEMA_VERSION,
		kind: BLOCK_EXPORT_KIND,
		block: rest,
	}
}

/**
 * Trigger a browser download of a block's export JSON. Pure side-effecting
 * wrapper around `exportBlockPayload` — kept separate so the payload shape
 * itself stays unit-testable without a DOM.
 *
 * @param {object} block - the `ComponentBlock` record to export.
 * @return {void}
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function downloadBlockExport(block) {
	const payload = exportBlockPayload(block)
	const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
	const link = document.createElement('a')
	link.href = URL.createObjectURL(blob)
	link.download = `${(block && block.slug) || 'component-block'}.json`
	link.click()
	URL.revokeObjectURL(link.href)
}

/**
 * Validate and unwrap an imported block export file's parsed content into a
 * new `ComponentBlock` record ready to POST (no `uuid`/`createdBy` —
 * a fresh identity every import, never a re-used one).
 *
 * @param {string|object} input - the raw file text, or already-parsed JSON.
 * @return {object} the block record with `uuid`/`createdBy` stripped.
 * @throws {BlockImportError} `invalid-json` on unparsable input,
 *   `invalid-shape` when the envelope isn't a recognised block export.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function parseBlockImport(input) {
	let data = input
	if (typeof input === 'string') {
		try {
			data = JSON.parse(input)
		} catch (e) {
			throw new BlockImportError('invalid-json')
		}
	}
	if (!data || typeof data !== 'object' || data.kind !== BLOCK_EXPORT_KIND || !data.block || typeof data.block !== 'object') {
		throw new BlockImportError('invalid-shape')
	}
	const block = { ...data.block }
	delete block.uuid
	delete block.createdBy
	return block
}
