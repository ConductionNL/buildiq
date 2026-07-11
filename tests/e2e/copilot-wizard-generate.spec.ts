// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — wizard "Generate with AI" flow (spec: ai-copilot).
 *
 * Follows the skip-on-503 pattern from tests/e2e/chat-companion-streaming.spec.ts:
 * every test probes `/api/copilot/health` first and skips cleanly when no
 * TaskProcessing provider is configured (or the server predates NC 30).
 *
 * Scenarios (spec: ai-copilot REQ-OBAIC-001/006):
 *   1. Generate with AI creates the described app after confirmation — the
 *      plan call is stubbed via `page.route` with a fixed, deterministic
 *      plan (LLM output is inherently non-deterministic and out of scope
 *      for e2e — see spec.md's `@e2e exclude` on REQ-OBAIC-002/004); the
 *      execute call hits the REAL backend so the app is genuinely created.
 *   2. Cancelling the review applies nothing — no execute request is sent.
 *   3. The button is absent without a provider — health routed to 503.
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL (default localhost:8080).
 *   - openbuild enabled, openregister enabled.
 *   - Authenticated browser context from global-setup.
 *
 * CI-run only: this spec is written but not executed against the shared
 * dev instance as part of this change (per task instructions) — it runs
 * under the project's normal `npm run test:e2e` CI job.
 */
import { test, expect } from '@playwright/test'

const HEALTH_URL = '/index.php/apps/openbuild/api/copilot/health'
const PLAN_URL = '**/apps/openbuild/api/copilot/plan'

/** A fixed, deterministic plan the stubbed `/api/copilot/plan` route returns. */
const STUBBED_PLAN = {
	summary: 'A tool library where members can borrow and return tools.',
	steps: [
		{ tool: 'openbuild.createApp', arguments: { slug: 'e2e-copilot-tool-library', name: 'E2E Copilot Tool Library', preset: 'dev-prod' } },
		{ tool: 'openbuild.upsertPage', arguments: { appSlug: 'e2e-copilot-tool-library', pageId: 'home', title: 'Home', type: 'index', route: '/' } },
	],
	manifests: {
		'e2e-copilot-tool-library@development': {
			current: { version: '1.0.0', menu: [], pages: [] },
			predicted: { version: '1.0.0', menu: [], pages: [{ id: 'home', route: '/', type: 'index', title: 'Home', config: {} }] },
		},
	},
}

test.describe('Wizard "Generate with AI" (spec: ai-copilot)', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/openbuild/')
		await page.waitForLoadState('networkidle')
	})

	test('Generate with AI creates the described app after confirmation (spec: ai-copilot)', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured — copilot intentionally hidden')

		// Stub the plan call so the review step is deterministic; the execute
		// call is NOT stubbed — it hits the real backend and creates a real app.
		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		await page.getByRole('button', { name: /create app|add application/i }).first().click()
		await page.waitForSelector('[data-testid="copilot-generate-button"]', { timeout: 10_000 })
		await page.locator('[data-testid="copilot-generate-button"]').click()

		await page.locator('[data-testid="copilot-brief-input"] textarea, [data-testid="copilot-brief-input"]').fill(
			'A tool library where members can borrow and return tools',
		)
		await page.getByRole('button', { name: /^generate$/i }).click()

		const review = page.locator('[data-testid="copilot-plan-review"]')
		await expect(review, 'plan review must render').toBeVisible({ timeout: 10_000 })
		await expect(review).toContainText('A tool library where members can borrow and return tools.')

		await page.locator('[data-testid="copilot-confirm"]').click()

		// On success the wizard closes and the browser navigates to the new app.
		await expect(page).toHaveURL(/e2e-copilot-tool-library/, { timeout: 15_000 })
	})

	test('Cancelling the review applies nothing (spec: ai-copilot)', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured')

		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		let executeCalled = false
		await page.route('**/apps/openbuild/api/copilot/execute', (route) => {
			executeCalled = true
			return route.continue()
		})

		await page.getByRole('button', { name: /create app|add application/i }).first().click()
		await page.waitForSelector('[data-testid="copilot-generate-button"]', { timeout: 10_000 })
		await page.locator('[data-testid="copilot-generate-button"]').click()
		await page.locator('[data-testid="copilot-brief-input"] textarea, [data-testid="copilot-brief-input"]').fill('A tool library')
		await page.getByRole('button', { name: /^generate$/i }).click()
		await expect(page.locator('[data-testid="copilot-plan-review"]')).toBeVisible({ timeout: 10_000 })

		await page.locator('[data-testid="copilot-cancel"]').click()

		expect(executeCalled, 'execute must never be called after Cancel').toBe(false)
	})

	test('The button is absent without a provider (spec: ai-copilot)', async ({ page }) => {
		// Force the 503 path regardless of the real server's configuration so
		// this scenario is deterministic in CI.
		await page.route('**/apps/openbuild/api/copilot/health', (route) => route.fulfill({
			status: 503,
			contentType: 'application/json',
			body: JSON.stringify({ status: 'unavailable', reason: 'no_provider' }),
		}))
		await page.reload()
		await page.waitForLoadState('networkidle')

		await page.getByRole('button', { name: /create app|add application/i }).first().click()
		await page.waitForSelector('.wizard-step1, [data-testid="copilot-brief-input"], body', { timeout: 10_000 })

		await expect(page.locator('[data-testid="copilot-generate-button"]')).toHaveCount(0)
	})
})
