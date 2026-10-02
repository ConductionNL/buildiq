// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: copying an app (spec: copy-app-page-and-form).
 *
 * API level, as the admin the CI stack signs in with. The seeded Hello World
 * app is copied; the copy must answer its own manifest and a second copy
 * under the same slug must be refused. Copy page and Copy form change only
 * the page list and the form list and are covered by Vitest
 * (tests/services/pageCopy.spec.js, tests/services/formCopy.spec.js).
 *
 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md
 * @e2e copy-app-page-and-form/requirement-a-maker-copies-an-app-req-bqcp-001/a-maker-starts-a-variant-of-the-permit-tracker
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/buildiq/api/applications'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const SOURCE = 'hello-world'

test.describe('copy an app', () => {
	const slug = `copy-e2e-${Date.now().toString(36)}`.slice(0, 32)

	test('the copy answers its own manifest, and the slug cannot be taken twice', async ({
		request,
	}) => {
		const source = await request.get(`${API}/${SOURCE}/manifest`, {
			headers: HEADERS,
		})
		test.skip(!source.ok(), 'the Hello World app is not seeded here')

		const created = await request.post(`${API}/${SOURCE}/copy`, {
			headers: HEADERS,
			data: { name: 'Copy e2e', slug },
		})
		expect(created.status()).toBe(201)
		expect((await created.json()).slug).toBe(slug)

		const manifest = await request.get(`${API}/${slug}/manifest`, {
			headers: HEADERS,
		})
		expect(manifest.ok()).toBeTruthy()
		const pages = ((await manifest.json()).pages ?? []) as Array<{ id: string }>
		const sourcePages = ((await source.json()).pages ?? []) as Array<{
			id: string
		}>
		expect(pages.map((p) => p.id)).toEqual(sourcePages.map((p) => p.id))

		const again = await request.post(`${API}/${SOURCE}/copy`, {
			headers: HEADERS,
			data: { name: 'Copy e2e', slug },
		})
		expect(again.status()).toBe(409)
	})

	test('a copy without a name or with a bad slug is refused before anything is made', async ({
		request,
	}) => {
		const response = await request.post(`${API}/${SOURCE}/copy`, {
			headers: HEADERS,
			data: { name: '', slug: 'Not A Slug' },
		})
		test.skip(
			response.status() === 404,
			'the Hello World app is not seeded here',
		)
		expect(response.status()).toBe(400)
	})
})
