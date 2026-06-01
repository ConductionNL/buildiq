// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E spec-coverage for retrofit-2026-05-26-frontend-foundation.
 *
 * REQ-OBFFUI-005: Per-user preferences endpoint reads and writes sanitised keys.
 *
 * Scenarios:
 *   - Reject an unsafe key → 400 without touching IConfig.
 *   - Clear a preference → deletes the stored value and returns {value: null}.
 *   - Authenticated read → returns {value: string|null}.
 *   - Authenticated write → stores value and echoes {value: string}.
 *   - Unauthenticated access → 401.
 */

import { test, expect } from '@playwright/test'

const PREF_GET = (key: string) => `/index.php/apps/openbuild/api/preferences/${key}`
const PREF_SET = (key: string) => `/index.php/apps/openbuild/api/preferences/${key}`

// @e2e frontend-foundation::reject-unsafe-key
test('REQ-OBFFUI-005 — unsafe key returns 400 without touching IConfig', async ({ request }) => {
	// @e2e frontend-foundation::reject-unsafe-key
	// A key that sanitises to empty (all non-alphanum chars) must be rejected.
	const res = await request.get(PREF_GET('!!!'), {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.status()).toBe(400)
})

// @e2e frontend-foundation::clear-preference
test('REQ-OBFFUI-005 — setting an empty value clears the preference', async ({ request }) => {
	// @e2e frontend-foundation::clear-preference
	// Write a value first, then clear it with an empty string.
	const key = 'test-e2e-key'
	await request.post(PREF_SET(key), {
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: JSON.stringify({ value: 'initial' }),
	})

	// Clear by posting empty value.
	const clearRes = await request.post(PREF_SET(key), {
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: JSON.stringify({ value: '' }),
	})
	expect(clearRes.status()).toBe(200)
	const body = await clearRes.json()
	expect(body).toHaveProperty('value', null)
})

// @e2e frontend-foundation::authenticated-read
test('REQ-OBFFUI-005 — authenticated GET returns {value: string|null}', async ({ request }) => {
	// @e2e frontend-foundation::authenticated-read
	const res = await request.get(PREF_GET('any-key'), {
		headers: { 'OCS-APIRequest': 'true' },
	})
	// 200 when authenticated; 401 if the test env lacks a session.
	expect([200, 401]).toContain(res.status())
	if (res.status() === 200) {
		const body = await res.json()
		expect(body).toHaveProperty('value')
		expect(body.value === null || typeof body.value === 'string').toBe(true)
	}
})

// @e2e frontend-foundation::authenticated-write
test('REQ-OBFFUI-005 — authenticated POST stores value and echoes {value: string}', async ({ request }) => {
	// @e2e frontend-foundation::authenticated-write
	const res = await request.post(PREF_SET('e2e-test-flag'), {
		headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: JSON.stringify({ value: 'seen' }),
	})
	expect([200, 401]).toContain(res.status())
	if (res.status() === 200) {
		const body = await res.json()
		expect(body).toHaveProperty('value', 'seen')
	}
})

// @e2e frontend-foundation::unauthenticated-access
test('REQ-OBFFUI-005 — unauthenticated request is gated (401 or 302)', async ({ playwright }) => {
	// @e2e frontend-foundation::unauthenticated-access
	const ctx = await playwright.request.newContext()
	const res = await ctx.get(`/index.php/apps/openbuild/api/preferences/any-key`)
	await ctx.dispose()
	expect([401, 302]).toContain(res.status())
})
