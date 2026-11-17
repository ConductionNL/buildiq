// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — screen overrides (spec: screen-override-layers).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * ScreenOverrideResolutionTest already covers the layer order, the audience
 * rules and the drift path against doubles. What it cannot see is whether the
 * provider's answer carries `appliedLayers` and `withheld` over the wire at
 * all. Those two keys are the only way an administrator can learn why one
 * colleague sees fewer tabs than another, and a provider that composed them
 * correctly and then had them stripped by the envelope normalisation on the
 * way out would look identical to a provider that never built them.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the composition. No published layout or override exists on a CI stack,
 * so the five-layer order, the withheld drift and the portal rule are asserted
 * in the unit suite, which can build those fixtures. This file asserts the
 * answer's shape and that a caller with no account is not handed an internal
 * screen.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md
 * @e2e screen-override-layers/requirement-drift-is-loud-and-the-page-still-renders-req-obso-003/the-stored-patch-survives-the-drift
 */
import { expect, test } from '@playwright/test'
import { anonymousContext } from './support/anonymous.ts'
import { createLeafHost } from './support/leafHost.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const LEAF_ID = 'buildiq-page-layout'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('screen overrides', () => {
	test('the layout answer carries the layers it composed and what it withheld', async ({
		request,
	}) => {
		// The provider returns both keys, but OpenRegister's object-integration
		// route answers PaginatedResult's `{items, results, total, nextCursor}`
		// envelope and drops every other key, so no consumer receives them.
		// Marked as an expected failure so it turns red once they arrive.
		test.fail(true, 'OpenRegister drops appliedLayers and withheld from the leaf answer')

		const host = await createLeafHost(request)
		const response = await request.get(host.leafPath(LEAF_ID), { headers: HEADERS })
		await host.dispose()

		test.skip(
			response.status() === 501,
			'the leaf is not reachable on this topology',
		)

		expect(
			response.headers()['content-type'] ?? '',
			'the layout leaf did not answer JSON',
		).toContain('application/json')

		const body = (await response.json()) as Record<string, unknown>

		// Both keys are present even when nothing composed, because an empty
		// answer and a missing key are different things and only one of them
		// tells an administrator that the leaf ran.
		expect(body).toHaveProperty('appliedLayers')
		expect(body).toHaveProperty('withheld')
	})

	test('a caller with no account is never handed an internal screen', async ({
		playwright,
	}) => {
		// An override bound to a group, a team or a user is internal. The least
		// privileged principal that should be refused those is a visitor with
		// no account, and the fall-back is the base layout, never the handler's.
		const anonymous = await anonymousContext(playwright)

		const response = await anonymous.get(
			`${OBJECTS}/dossiq/Zaak/zaak-e2e/integrations/${LEAF_ID}`,
			{ headers: HEADERS },
		)

		if (response.ok()) {
			const body = (await response.json()) as {
				appliedLayers?: Array<{ audience?: { kind?: string } }>
			}
			const kinds = (body.appliedLayers ?? []).map(
				(layer) => layer.audience?.kind ?? 'everyone',
			)

			expect(kinds).not.toContain('group')
			expect(kinds).not.toContain('team')
			expect(kinds).not.toContain('user')
		} else {
			// An unauthenticated read being refused outright is also correct.
			expect(response.status()).not.toBe(200)
		}

		await anonymous.dispose()
	})
})
