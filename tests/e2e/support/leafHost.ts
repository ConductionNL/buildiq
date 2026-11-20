/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Shared e2e helper: a host object for buildiq's leaves to answer on.
 *
 * OpenRegister serves a leaf at
 * `/api/objects/{register}/{schema}/{id}/integrations/{leafId}` and answers 404
 * when that object does not exist, so a leaf can only be read live against a
 * real host. This makes a throwaway register, a `case` schema with a type field,
 * and one case of type `bouwvergunning`, and removes all three again.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

const OR_API = '/index.php/apps/openregister/api'
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

export interface LeafHost {
	register: string
	schema: string
	objectId: string
	/** The leaf's path on this host object. */
	leafPath(leafId: string): string
	/** Remove the object, the schema and the register. */
	dispose(): Promise<void>
}

/**
 * Create a register, a schema and one `bouwvergunning` case to host a leaf.
 *
 * @param request An admin request context.
 * @return The host.
 */
export async function createLeafHost(request: APIRequestContext): Promise<LeafHost> {
	const suffix = Date.now().toString(36)
	const register = `e2e-leaf-host-${suffix}`
	const schema = `e2e-case-${suffix}`

	const madeRegister = await request.post(`${OR_API}/registers`, {
		headers: HEADERS,
		data: { title: 'E2E leaf host', slug: register },
	})
	expect(madeRegister.ok(), `create register -> ${madeRegister.status()}`).toBeTruthy()
	const registerId = (await madeRegister.json()).id

	const madeSchema = await request.post(`${OR_API}/schemas`, {
		headers: HEADERS,
		data: {
			title: 'Case',
			slug: schema,
			properties: { title: { type: 'string' }, caseType: { type: 'string' } },
		},
	})
	expect(madeSchema.ok(), `create schema -> ${madeSchema.status()}`).toBeTruthy()
	const schemaId = (await madeSchema.json()).id

	const attached = await request.put(`${OR_API}/registers/${registerId}`, {
		headers: HEADERS,
		data: { title: 'E2E leaf host', slug: register, schemas: [schemaId] },
	})
	expect(attached.ok(), `attach schema -> ${attached.status()}`).toBeTruthy()

	const madeObject = await request.post(`${OR_API}/objects/${register}/${schema}`, {
		headers: HEADERS,
		data: { title: 'Dakkapel', caseType: 'bouwvergunning' },
	})
	expect(madeObject.ok(), `create case -> ${madeObject.status()}`).toBeTruthy()
	const objectId = (await madeObject.json()).id

	return {
		register,
		schema,
		objectId,
		leafPath: (leafId: string) =>
			`${OR_API}/objects/${register}/${schema}/${objectId}/integrations/${leafId}`,
		dispose: async () => {
			await request.delete(`${OR_API}/objects/${register}/${schema}/${objectId}`, { headers: HEADERS })
			await request.delete(`${OR_API}/schemas/${schemaId}`, { headers: HEADERS })
			await request.delete(`${OR_API}/registers/${registerId}`, { headers: HEADERS })
		},
	}
}
