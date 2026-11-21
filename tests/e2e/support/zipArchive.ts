/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Shared e2e helper: read the entries of a downloaded ZIP archive.
 *
 * The suite has no ZIP dependency, and an export spec only needs to list an
 * archive's files and read a few small text files from it. This reads the
 * central directory at the end of the archive and inflates an entry with
 * Node's own zlib. It handles stored and deflated entries, which is what PHP's
 * ZipArchive writes; ZIP64 archives (over 4 GB or 65535 entries) are not
 * supported and throw.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import { inflateRawSync } from 'node:zlib'

const END_OF_DIRECTORY = 0x06054b50
const DIRECTORY_ENTRY = 0x02014b50
const LOCAL_HEADER = 0x04034b50

/** One file in the archive. */
export interface ZipEntry {
	/** The path inside the archive. */
	name: string
	/** 0 for stored, 8 for deflated. */
	method: number
	compressedSize: number
	localHeaderOffset: number
}

/**
 * The files of an archive, in central directory order.
 *
 * @param archive The archive's bytes.
 * @return The entries.
 * @throws {Error} When the bytes are not a ZIP archive this helper can read.
 */
export function zipEntries(archive: Buffer): ZipEntry[] {
	let end = -1
	for (let i = archive.length - 22; i >= 0; i--) {
		if (archive.readUInt32LE(i) === END_OF_DIRECTORY) {
			end = i
			break
		}
	}
	if (end < 0) {
		throw new Error('Not a ZIP archive: no end of central directory record.')
	}
	const count = archive.readUInt16LE(end + 10)
	let offset = archive.readUInt32LE(end + 16)
	if (count === 0xffff || offset === 0xffffffff) {
		throw new Error('ZIP64 archives are not supported by this helper.')
	}

	const entries: ZipEntry[] = []
	for (let i = 0; i < count; i++) {
		if (archive.readUInt32LE(offset) !== DIRECTORY_ENTRY) {
			throw new Error(`Broken central directory at byte ${offset}.`)
		}
		const nameLength = archive.readUInt16LE(offset + 28)
		const extraLength = archive.readUInt16LE(offset + 30)
		const commentLength = archive.readUInt16LE(offset + 32)
		entries.push({
			name: archive.toString('utf8', offset + 46, offset + 46 + nameLength),
			method: archive.readUInt16LE(offset + 10),
			compressedSize: archive.readUInt32LE(offset + 20),
			localHeaderOffset: archive.readUInt32LE(offset + 42),
		})
		offset += 46 + nameLength + extraLength + commentLength
	}
	return entries
}

/**
 * The text of one entry.
 *
 * @param archive The archive's bytes.
 * @param entry   An entry from {@link zipEntries}.
 * @return The entry's content as UTF-8 text.
 * @throws {Error} When the entry uses a compression method other than store or deflate.
 */
export function zipText(archive: Buffer, entry: ZipEntry): string {
	const at = entry.localHeaderOffset
	if (archive.readUInt32LE(at) !== LOCAL_HEADER) {
		throw new Error(`No local header for ${entry.name}.`)
	}
	const start = at + 30 + archive.readUInt16LE(at + 26) + archive.readUInt16LE(at + 28)
	const data = archive.subarray(start, start + entry.compressedSize)
	if (entry.method === 0) {
		return data.toString('utf8')
	}
	if (entry.method === 8) {
		return inflateRawSync(data).toString('utf8')
	}
	throw new Error(`${entry.name} uses compression method ${entry.method}, which this helper cannot read.`)
}
