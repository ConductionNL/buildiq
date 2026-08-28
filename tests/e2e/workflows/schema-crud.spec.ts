// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * DEEP, data-dependent e2e — full CRUD-WITH-PERSISTENCE for a Schema.
 *
 * In Buildiq a Schema is the building block a virtual app composes (its data
 * model). Schemas are first-class OpenRegister schemas; Buildiq itself
 * exposes NO schema-CRUD route (verified against appinfo/routes.php) — the
 * builder UI drives OpenRegister's runtime schema API directly. This spec
 * therefore exercises CRUD-with-persistence against that same backend the UI
 * uses, asserting each mutation survives an independent read-back (not just an
 * optimistic in-memory toggle).
 *
 * What this proves:
 *   CREATE  — POST a schema with TWO properties → 201 → read it back; both
 *             properties are present and typed.
 *   READ    — the persisted schema lists in the OR schema index by slug.
 *   UPDATE  — PUT a renamed title + a third property → 200 → read back; the
 *             rename AND the new property persisted.
 *   DELETE  — DELETE → read back returns 404 (truly gone).
 *
 * The in-app schema *designer* UI (`/builder/{slug}/schemas`, "Add schema" +
 * field editor) is Conduction/buildiq#41-quarantined (the builder/page-
 * designer nested routes do not render in this build), so the UI-driven
 * variant is `test.fixme`'d with that reason rather than faked.
 *
 * Pre-conditions: Docker stack up; OpenRegister enabled; admin/admin.
 */

import { test, expect } from '@playwright/test'
import {
	seedSchema,
	findSchema,
	deleteSchema,
	cleanupByPrefix,
	resolveSchemaId,
	BASE_URL,
	authHeaders,
	E2E_PREFIX,
} from './fixtures'

test.describe('Schema — full CRUD with persistence (OR runtime schema API)', () => {
	test.afterAll(async ({ request }) => {
		await cleanupByPrefix(request)
	})

	test('CREATE → schema with 2 properties persists and reads back', async ({
		request,
	}) => {
		const slug = `${E2E_PREFIX}-schema-c-${Math.floor(Math.random() * 1e4)}`
		const schema = await seedSchema(request, {
			slug,
			title: 'E2E Schema Create',
			properties: {
				label: { type: 'string', title: 'Label' },
				count: { type: 'integer', title: 'Count' },
			},
		})

		const persisted = await findSchema(request, schema.id)
		expect(persisted, 'schema must be readable back').not.toBeNull()
		const props = (persisted?.properties ?? {}) as Record<
			string,
			{ type?: string }
		>
		expect(
			Object.keys(props).sort(),
			'both seeded properties must persist',
		).toEqual(['count', 'label'])
		expect(props.label.type).toBe('string')
		expect(props.count.type).toBe('integer')

		await deleteSchema(request, schema.id)
	})

	test('READ → persisted schema is listed in the OR schema index by slug', async ({
		request,
	}) => {
		const slug = `${E2E_PREFIX}-schema-r-${Math.floor(Math.random() * 1e4)}`
		const schema = await seedSchema(request, { slug, title: 'E2E Schema Read' })

		// resolveSchemaId asserts there is exactly one schema with this slug —
		// i.e. it is indexed and findable.
		const id = await resolveSchemaId(request, slug)
		expect(Number(id)).toBe(Number(schema.id))

		await deleteSchema(request, schema.id)
	})

	test('UPDATE → renamed title + added property persist', async ({ request }) => {
		const slug = `${E2E_PREFIX}-schema-u-${Math.floor(Math.random() * 1e4)}`
		const schema = await seedSchema(request, {
			slug,
			title: 'E2E Schema Before',
			properties: {
				label: { type: 'string', title: 'Label' },
				count: { type: 'integer', title: 'Count' },
			},
		})

		const putRes = await request.put(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${schema.id}`,
			{
				headers: authHeaders,
				data: {
					slug,
					title: 'E2E Schema After',
					properties: {
						label: { type: 'string', title: 'Label' },
						count: { type: 'integer', title: 'Count' },
						active: { type: 'boolean', title: 'Active' },
					},
				},
			},
		)
		expect(putRes.status(), 'update PUT must return 200').toBe(200)

		const persisted = await findSchema(request, schema.id)
		expect(persisted?.title, 'renamed title must persist').toBe(
			'E2E Schema After',
		)
		const props = (persisted?.properties ?? {}) as Record<
			string,
			{ type?: string }
		>
		expect(
			Object.keys(props).sort(),
			'added property must persist alongside originals',
		).toEqual(['active', 'count', 'label'])
		expect(props.active.type).toBe('boolean')

		await deleteSchema(request, schema.id)
	})

	test('DELETE → schema is gone (read-back 404)', async ({ request }) => {
		const slug = `${E2E_PREFIX}-schema-d-${Math.floor(Math.random() * 1e4)}`
		const schema = await seedSchema(request, {
			slug,
			title: 'E2E Schema Delete',
		})

		const delRes = await request.delete(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${schema.id}`,
			{
				headers: authHeaders,
			},
		)
		expect([200, 204]).toContain(delRes.status())

		const gone = await findSchema(request, schema.id)
		expect(gone, 'deleted schema must read back as 404/null').toBeNull()
	})

	// ---- #41: schema designer UI quarantined -------------------------------
	test('CRUD via the in-app schema designer UI (Conduction/buildiq#41: builder routes do not render)', async ({
		page,
	}) => {
		test.fixme(
			true,
			'Conduction/buildiq#41: builder routes do not render #41: schema designer UI quarantined -------------------------------',
		)
		// The /builder/{slug}/schemas designer ("Add schema" → field editor →
		// Save) is part of the #41-quarantined nested builder routes. When #41
		// is fixed this should drive: Add schema → add 2 fields → Save →
		// reload → assert both fields persist → rename → Save → assert → delete.
		await page.goto('/apps/buildiq/builder/hello-world/schemas')
		await expect(page.getByRole('button', { name: /add schema/i })).toBeVisible({
			timeout: 15_000,
		})
	})
})
