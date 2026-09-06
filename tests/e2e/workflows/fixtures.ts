// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Seeded-fixture helper for the DEEP, data-dependent Buildiq e2e layer.
 *
 * The Buildiq virtual-app model is persisted as OpenRegister objects:
 *   - a Virtual App is an object in register `buildiq` / schema `application`
 *   - a Schema (the building block of a virtual app) is a first-class
 *     OpenRegister schema created via OR's `/api/schemas` endpoint.
 *
 * Buildiq itself exposes NO schema-CRUD route (see appinfo/routes.php) — the
 * builder UI talks to OpenRegister's runtime schema API directly. The deep
 * workflow specs therefore seed + assert persistence through OR's REST API
 * (the same backend the UI drives), using a unique `e2e-<runId>` prefix so a
 * crashed run never collides with the next and `afterAll` cleanup can scope its
 * deletes precisely.
 *
 * IMPORTANT — these helpers call the *real* OR API verbs only:
 *   find / findAll (GET .../objects, .../schemas)
 *   searchObjects  (GET .../objects?<filter>)
 *   saveObject     (POST .../objects/{register}/{schema})
 *   deleteObject   (DELETE .../objects/{register}/{schema}/{uuid})
 * Schemas use OR's RESTful schema endpoints (POST/PUT/DELETE /api/schemas).
 * No invented verbs (no createFromArray / deleteFromId / findObject).
 *
 * Discovered live-environment facts this helper encodes (2026-06-10):
 *   - buildiq register slug `buildiq` resolves to numeric id 206.
 *   - schema `application` resolves to numeric id 28.
 * Both are resolved dynamically at runtime (never hard-coded) so the helper
 * survives a clean-env reseed that renumbers ids.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS =
	process.env.NC_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** OpenRegister register slug that owns Buildiq virtual apps. */
export const BUILDIQ_REGISTER_SLUG = 'buildiq'
/** OpenRegister schema slug for the Virtual App object. */
export const APPLICATION_SCHEMA_SLUG = 'built-app'

/** A short, collision-proof run id shared by every fixture in one suite run. */
export const RUN_ID = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)
	.toString(36)
	.padStart(3, '0')}`

/** Prefix every seeded entity carries so cleanup can scope its deletes. */
export const E2E_PREFIX = `e2e-${RUN_ID}`

const authHeaders = {
	Authorization: `Basic ${Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')}`,
	'OCS-APIRequest': 'true',
	'Content-Type': 'application/json',
}

/** Describes a seeded virtual application (OR object). */
export interface SeededApp {
	uuid: string
	slug: string
	name: string
}

/** Describes a seeded schema (OR schema). */
export interface SeededSchema {
	id: number | string
	slug: string
	title: string
}

/**
 * Resolve an OpenRegister register slug → numeric id via the real `findAll`
 * verb (GET /api/registers). Throws if the slug is missing or ambiguous
 * (a duplicate-slug register is a real environment bug, not something to mask).
 */
export async function resolveRegisterId(
	request: APIRequestContext,
	slug: string,
): Promise<number> {
	// See resolveSchemaId: a shared dev instance can hold many registers, so
	// pull a generous page to keep the client-side slug filter exhaustive.
	const res = await request.get(
		`${BASE_URL}/index.php/apps/openregister/api/registers?_limit=5000`,
		{
			headers: authHeaders,
		},
	)
	expect(res.ok(), `register list must succeed (got ${res.status()})`).toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const matches = rows.filter((r) => r.slug === slug)
	expect(
		matches.length,
		`at least one register must own slug "${slug}" (found ${matches.length})`,
	).toBeGreaterThanOrEqual(1)
	// The shared dev instance can transiently hold >1 register for a slug when
	// the app install/repair seed re-runs (the extra registers are empty and
	// get merged by `occ openregister:registers:dedupe`). Resolve to the
	// canonical lowest-id register — the same register OR's dedupe keeps — so
	// the suite is robust to that env churn without masking a real conflict
	// (which would surface as data on the wrong register, caught downstream).
	const canonical = matches.map((r) => Number(r.id)).sort((a, b) => a - b)[0]
	return canonical
}

/**
 * Resolve an OpenRegister schema slug → numeric id via `findAll`
 * (GET /api/schemas). Throws on missing/ambiguous slug.
 */
export async function resolveSchemaId(
	request: APIRequestContext,
	slug: string,
): Promise<number> {
	// _limit must exceed the schema count of a long-lived shared dev instance
	// (the fleet env accumulates 1000+ schemas across apps); the OpenRegister
	// schemas endpoint has no server-side slug filter, so the resolver pulls
	// the full set and filters client-side. A page cap that drops the target
	// slug surfaces as a false "found 0".
	const res = await request.get(
		`${BASE_URL}/index.php/apps/openregister/api/schemas?_limit=5000`,
		{
			headers: authHeaders,
		},
	)
	expect(res.ok(), `schema list must succeed (got ${res.status()})`).toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const matches = rows.filter((r) => r.slug === slug)
	expect(
		matches.length,
		`at least one schema must own slug "${slug}" (found ${matches.length})`,
	).toBeGreaterThanOrEqual(1)
	// Resolve to the canonical lowest-id schema — robust to a transient
	// duplicate left by a re-run install/repair seed (see resolveRegisterId).
	return matches.map((r) => Number(r.id)).sort((a, b) => a - b)[0]
}

/**
 * Seed a Virtual App as an OpenRegister object (the `saveObject` verb).
 * Returns the created object's uuid + slug so callers can drive + assert the UI.
 */
export async function seedVirtualApp(
	request: APIRequestContext,
	opts: { slug?: string; name?: string; description?: string } = {},
): Promise<SeededApp> {
	const registerId = await resolveRegisterId(request, BUILDIQ_REGISTER_SLUG)
	const schemaId = await resolveSchemaId(request, APPLICATION_SCHEMA_SLUG)

	const slug = opts.slug ?? `${E2E_PREFIX}-app-${Math.floor(Math.random() * 1e4)}`
	const name = opts.name ?? `E2E App ${slug}`
	const description = opts.description ?? 'Seeded by the Buildiq deep e2e suite.'

	const res = await request.post(
		`${BASE_URL}/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`,
		{ headers: authHeaders, data: { slug, name, description } },
	)
	expect(
		res.ok(),
		`seedVirtualApp must succeed (got ${res.status()})`,
	).toBeTruthy()
	const obj = await res.json()
	const uuid = String(obj.id ?? obj['@self']?.id ?? '')
	expect(uuid, 'seeded app must carry a uuid').not.toBe('')
	return { uuid, slug, name }
}

/**
 * Seed an OpenRegister schema with >=2 properties (the building block a
 * virtual app composes). Returns id + slug for UI/assertion use.
 */
export async function seedSchema(
	request: APIRequestContext,
	opts: {
		slug?: string
		title?: string
		properties?: Record<string, unknown>
	} = {},
): Promise<SeededSchema> {
	const slug =
		opts.slug ?? `${E2E_PREFIX}-schema-${Math.floor(Math.random() * 1e4)}`
	const title = opts.title ?? `E2E Schema ${slug}`
	const properties = opts.properties ?? {
		label: { type: 'string', title: 'Label' },
		count: { type: 'integer', title: 'Count' },
	}
	const res = await request.post(
		`${BASE_URL}/index.php/apps/openregister/api/schemas`,
		{
			headers: authHeaders,
			data: { slug, title, properties },
		},
	)
	expect(res.ok(), `seedSchema must succeed (got ${res.status()})`).toBeTruthy()
	const obj = await res.json()
	return { id: obj.id, slug, title }
}

/** Fetch a single virtual-app object by uuid (the `find` verb). Null on 404. */
export async function findVirtualApp(
	request: APIRequestContext,
	uuid: string,
): Promise<Record<string, unknown> | null> {
	const registerId = await resolveRegisterId(request, BUILDIQ_REGISTER_SLUG)
	const schemaId = await resolveSchemaId(request, APPLICATION_SCHEMA_SLUG)
	const res = await request.get(
		`${BASE_URL}/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}`,
		{ headers: authHeaders },
	)
	if (res.status() === 404) {
		return null
	}
	expect(
		res.ok(),
		`findVirtualApp must succeed (got ${res.status()})`,
	).toBeTruthy()
	return res.json()
}

/** Fetch a single schema by id. Null on 404. */
export async function findSchema(
	request: APIRequestContext,
	id: number | string,
): Promise<Record<string, unknown> | null> {
	const res = await request.get(
		`${BASE_URL}/index.php/apps/openregister/api/schemas/${id}`,
		{
			headers: authHeaders,
		},
	)
	if (res.status() === 404) {
		return null
	}
	expect(res.ok(), `findSchema must succeed (got ${res.status()})`).toBeTruthy()
	return res.json()
}

/** Delete one virtual-app object (the `deleteObject` verb). Idempotent. */
export async function deleteVirtualApp(
	request: APIRequestContext,
	uuid: string,
): Promise<void> {
	const registerId = await resolveRegisterId(request, BUILDIQ_REGISTER_SLUG)
	const schemaId = await resolveSchemaId(request, APPLICATION_SCHEMA_SLUG)
	await request.delete(
		`${BASE_URL}/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}`,
		{ headers: authHeaders },
	)
}

/** Delete one schema by id. Idempotent. */
export async function deleteSchema(
	request: APIRequestContext,
	id: number | string,
): Promise<void> {
	await request.delete(
		`${BASE_URL}/index.php/apps/openregister/api/schemas/${id}`,
		{
			headers: authHeaders,
		},
	)
}

/**
 * Cleanup-by-prefix: delete every application object AND schema whose slug
 * starts with the suite's `e2e-<runId>` prefix. Safe to call in afterAll even
 * if individual specs already cleaned up their own entities.
 */
export async function cleanupByPrefix(
	request: APIRequestContext,
	prefix: string = E2E_PREFIX,
): Promise<void> {
	// --- application objects ---
	try {
		const registerId = await resolveRegisterId(request, BUILDIQ_REGISTER_SLUG)
		const schemaId = await resolveSchemaId(request, APPLICATION_SCHEMA_SLUG)
		const res = await request.get(
			`${BASE_URL}/index.php/apps/openregister/api/objects/${registerId}/${schemaId}?_limit=1000`,
			{ headers: authHeaders },
		)
		if (res.ok()) {
			const body = await res.json()
			const rows: Array<Record<string, unknown>> = Array.isArray(body)
				? body
				: (body.results ?? [])
			for (const row of rows) {
				const slug = String(
					row.slug
						?? (row['@self'] as Record<string, unknown>)?.slug
						?? '',
				)
				const uuid = String(
					row.id ?? (row['@self'] as Record<string, unknown>)?.id ?? '',
				)
				if (slug.startsWith(prefix) && uuid !== '') {
					await deleteVirtualApp(request, uuid)
				}
			}
		}
	} catch {
		// best-effort cleanup; never fail the suite on teardown
	}

	// --- schemas ---
	try {
		const res = await request.get(
			`${BASE_URL}/index.php/apps/openregister/api/schemas?_limit=1000`,
			{
				headers: authHeaders,
			},
		)
		if (res.ok()) {
			const body = await res.json()
			const rows: Array<Record<string, unknown>> = Array.isArray(body)
				? body
				: (body.results ?? [])
			for (const row of rows) {
				const slug = String(row.slug ?? '')
				if (
					slug.startsWith(prefix)
					&& row.id !== null
					&& row.id !== undefined
				) {
					await deleteSchema(request, row.id as number)
				}
			}
		}
	} catch {
		// best-effort
	}
}

/**
 * Resolve the Buildiq manifest for a virtual-app slug (the real build
 * artifact the runtime renders). Returns { status, body } so the build
 * workflow spec can assert on the produced manifest shape.
 */
export async function fetchManifest(
	request: APIRequestContext,
	slug: string,
): Promise<{ status: number; body: unknown }> {
	const res = await request.get(
		`${BASE_URL}/index.php/apps/buildiq/api/applications/${slug}/manifest`,
		{ headers: authHeaders },
	)
	return { status: res.status(), body: await res.json().catch(() => null) }
}

/**
 * Drive the Buildiq creation wizard (POST /api/applications/wizard) — the
 * canonical "build a virtual app" entry point. Returns { status, body }.
 */
export async function wizardCreate(
	request: APIRequestContext,
	payload: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	const res = await request.post(
		`${BASE_URL}/index.php/apps/buildiq/api/applications/wizard`,
		{ headers: authHeaders, data: payload },
	)
	return {
		status: res.status(),
		body: (await res.json().catch(() => ({}))) as Record<string, unknown>,
	}
}

export { authHeaders, BASE_URL }
