// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The registers OpenRegister serves from a connected database.
 *
 * A database is connected in OpenRegister, not here: an administrator adds a
 * `Source` of type `database` and introspects it into a register whose
 * `source` names that source. This reads both lists and answers the database
 * registers, each with whether its source takes writes (`authConfig.writable`).
 *
 * Read on every call and never stored on the Application (design D2): an
 * administrator who turns writes off is followed the next time a picker or an
 * editor opens, and a maker cannot mark a read-only database writable.
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The entries of an OpenRegister list answer (`{results: [...]}` or a bare array).
 *
 * @param {object} http An axios-like client.
 * @param {string} url The list URL.
 * @return {Promise<Array<object>>}
 */
async function listOf(http, url) {
	const response = await http.get(url)
	const data = response?.data
	const list = Array.isArray(data) ? data : data?.results
	return Array.isArray(list) ? list : []
}

/**
 * The database sources by every key a register may name them by (id, uuid, slug).
 *
 * @param {Array<object>} sources The sources.
 * @return {Map<string, object>}
 */
function databaseSourcesByKey(sources) {
	const byKey = new Map()
	sources
		.filter((source) => source && source.type === 'database')
		.forEach((source) => {
			[source.id, source.uuid, source.slug]
				.filter((key) => key !== undefined && key !== null && key !== '')
				.forEach((key) => byKey.set(String(key), source))
		})
	return byKey
}

/**
 * The registers served from a connected database, each with its write setting.
 *
 * An OpenRegister error answers an empty list: the picker then offers nothing
 * rather than a database it cannot vouch for.
 *
 * @param {object} [http] An axios-like client (defaults to @nextcloud/axios).
 * @return {Promise<Array<{register: string, label: string, source: string, writable: boolean}>>}
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
 */
export async function fetchConnectedDatabases(http = axios) {
	try {
		const [sources, registers] = await Promise.all([
			listOf(http, generateUrl('/apps/openregister/api/sources')),
			listOf(http, generateUrl('/apps/openregister/api/registers')),
		])
		const databases = databaseSourcesByKey(sources)

		return registers
			.filter((register) => register && register.source !== undefined && register.source !== null && databases.has(String(register.source)))
			.map((register) => {
				const key = String(register.slug || register.id)
				return {
					register: key,
					label: register.title || key,
					source: String(register.source),
					writable: databases.get(String(register.source))?.authConfig?.writable === true,
				}
			})
	} catch {
		return []
	}
}

/**
 * Whether a register is a connected database, and whether it takes writes.
 *
 * @param {Array<{register: string, writable: boolean}>} databases The connected databases.
 * @param {string} register A register slug or id.
 * @return {{database: boolean, writable: boolean}} `writable` is true for a register that is not a database.
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
 */
export function databaseWriteSetting(databases, register) {
	const match = (Array.isArray(databases) ? databases : []).find((entry) => entry.register === String(register))
	if (!match) {
		return { database: false, writable: true }
	}
	return { database: true, writable: match.writable === true }
}

/**
 * The composable.
 *
 * @return {{fetchConnectedDatabases: typeof fetchConnectedDatabases, databaseWriteSetting: typeof databaseWriteSetting}}
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
 */
export function useConnectedDatabases() {
	return { fetchConnectedDatabases, databaseWriteSetting }
}
