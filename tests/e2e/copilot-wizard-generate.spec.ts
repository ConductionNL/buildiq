// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — wizard "Generate with AI" flow (spec: ai-copilot).
 *
 * WHY THESE NO LONGER SKIP ON 503
 * -------------------------------
 * These specs used to probe `/api/copilot/health` and skip when it returned
 * 503 ("no AI provider configured"). That gate hid the part of the flow that
 * is actually deterministic and worth asserting — see the long note in
 * tests/e2e/copilot-panel.spec.ts.
 *
 * OpenBuild is the MCP **provider**: `CopilotService::execute()` is a pure
 * dispatcher over `lib/Mcp/OpenBuildToolProvider`'s handlers and never calls
 * `assertAvailable()`, so it needs no AI. Only `plan()` talks to an LLM.
 *
 *   - `/api/copilot/health` is stubbed 200 — an environment probe that gates
 *     whether the UI renders, not an assertion target. Scenario 3 below is
 *     the deliberate other side of that gate and stubs it 503.
 *   - `/api/copilot/plan` is stubbed — LLM output is non-deterministic and
 *     explicitly out of scope (spec.md `@e2e exclude` on REQ-OBAIC-002/004).
 *   - `/api/copilot/execute` is NOT stubbed — it hits the real backend and
 *     drives the real `openbuild.createApp` / `openbuild.upsertPage` MCP
 *     handlers, so a real Application is genuinely created.
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL.
 *   - openbuild enabled, openregister enabled.
 *   - Authenticated browser context from global-setup.
 */
import { test, expect } from '@playwright/test'
// nc-vue's first-visit overlays (CnWalkthrough tour + CnSupportDialog) each
// render a full-viewport backdrop that intercepts pointer events — live-verified
// as the cause behind every failure in this file: `getByRole('button', { name:
// /create app|add application/i }).first().click()` retried against the overlay
// for the full 90s describe timeout. Neither persists its "seen" state on this
// instance, so both can reopen on every run. Helpers shared with the other specs
// that hit the same overlays.
import { dismissWalkthrough, dismissSupportDialog } from './support/overlays'

const HEALTH_URL = '**/apps/openbuild/api/copilot/health'
const PLAN_URL = '**/apps/openbuild/api/copilot/plan'

/**
 * `openbuild.createApp` rejects a slug that already exists, so a fixed slug
 * would pass once and fail on every re-run. Each run gets its own slug.
 * Must satisfy the tool's `^[a-z0-9][a-z0-9-]*[a-z0-9]$` pattern, max 48.
 */
const APP_SLUG = `e2e-copilot-lib-${Date.now().toString(36)}`

const stubbedPlan = (slug: string) => ({
	summary: 'A tool library where members can borrow and return tools.',
	steps: [
		{ tool: 'openbuild.createApp', arguments: { slug, name: 'E2E Copilot Tool Library', preset: 'dev-prod' } },
		{ tool: 'openbuild.upsertPage', arguments: { appSlug: slug, pageId: 'home', title: 'Home', type: 'index', route: '/' } },
	],
	manifests: {
		[`${slug}@development`]: {
			current: { version: '1.0.0', menu: [], pages: [] },
			predicted: { version: '1.0.0', menu: [], pages: [{ id: 'home', route: '/', type: 'index', title: 'Home', config: {} }] },
		},
	},
})

test.describe('Wizard "Generate with AI" (spec: ai-copilot)', () => {

	// The confirm path runs a REAL createApp + upsertPage through the MCP
	// handlers and then waits for the SPA to route to the new app, which
	// exceeds the 30s project default on a loaded dev box. Wall clock only —
	// no assertion is relaxed.
	test.describe.configure({ timeout: 90_000 })

	// @e2e ai-copilot::generate-with-ai-creates-the-described-app-after-confirmation
	test('Generate with AI creates the described app after confirmation (spec: ai-copilot)', async ({ page }) => {
		// Health gates `copilotAvailable`, probed from mounted() — route it
		// before the first navigation.
		await page.route(HEALTH_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ available: true }) }))
		await page.goto('/apps/openbuild/')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		// NOT waitForLoadState('networkidle') — this NC instance's own
		// background chatter (notifications poll, user-status heartbeat) means
		// the network is never idle for 500ms on an authenticated page, so
		// networkidle never resolves and eats the whole 90s describe timeout
		// (live-verified: still hung with the walkthrough dismissed). Wait for
		// the Dashboard's own "Create app" entry point instead.
		await expect(page.getByRole('button', { name: /create app|add application/i }).first(), 'Dashboard must render its create-app entry point').toBeVisible({ timeout: 20_000 })

		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(stubbedPlan(APP_SLUG)) }))

		// Surface a backend rejection by name rather than as a URL timeout.
		const executeResponse = page.waitForResponse((r) => r.url().includes('/api/copilot/execute'))

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

		const res = await executeResponse
		expect(res.status(), `execute must succeed — body: ${await res.text()}`).toBe(200)

		// On success the wizard closes and the browser navigates to the new app.
		await expect(page).toHaveURL(new RegExp(APP_SLUG), { timeout: 15_000 })
	})

	// @e2e ai-copilot::cancelling-the-review-applies-nothing
	test('Cancelling the review applies nothing (spec: ai-copilot)', async ({ page }) => {
		await page.route(HEALTH_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ available: true }) }))
		await page.goto('/apps/openbuild/')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		// NOT waitForLoadState('networkidle') — this NC instance's own
		// background chatter (notifications poll, user-status heartbeat) means
		// the network is never idle for 500ms on an authenticated page, so
		// networkidle never resolves and eats the whole 90s describe timeout
		// (live-verified: still hung with the walkthrough dismissed). Wait for
		// the Dashboard's own "Create app" entry point instead.
		await expect(page.getByRole('button', { name: /create app|add application/i }).first(), 'Dashboard must render its create-app entry point').toBeVisible({ timeout: 20_000 })

		// A distinct slug: this test must never create an app, so if the guard
		// regresses the stray Application is unambiguously traceable here.
		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(stubbedPlan(`${APP_SLUG}-cancel`)) }))

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

	// @e2e ai-copilot::the-button-is-absent-without-a-provider
	test('The button is absent without a provider (spec: ai-copilot)', async ({ page }) => {
		// The deliberate 503 side of the health gate: with no provider the
		// entry point must not render at all.
		await page.route(HEALTH_URL, (route) => route.fulfill({
			status: 503,
			contentType: 'application/json',
			body: JSON.stringify({ status: 'unavailable', reason: 'no_provider' }),
		}))
		await page.goto('/apps/openbuild/')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		// NOT waitForLoadState('networkidle') — this NC instance's own
		// background chatter (notifications poll, user-status heartbeat) means
		// the network is never idle for 500ms on an authenticated page, so
		// networkidle never resolves and eats the whole 90s describe timeout
		// (live-verified: still hung with the walkthrough dismissed). Wait for
		// the Dashboard's own "Create app" entry point instead.
		await expect(page.getByRole('button', { name: /create app|add application/i }).first(), 'Dashboard must render its create-app entry point').toBeVisible({ timeout: 20_000 })

		await page.getByRole('button', { name: /create app|add application/i }).first().click()
		await page.waitForSelector('.wizard-step1, [data-testid="copilot-brief-input"], body', { timeout: 10_000 })

		await expect(page.locator('[data-testid="copilot-generate-button"]')).toHaveCount(0)
	})
})
