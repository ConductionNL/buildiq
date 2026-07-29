// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — builder copilot side panel (spec: ai-copilot).
 *
 * Exercised against the seeded `hello-world` virtual app (see
 * tests/e2e/global-setup.ts).
 *
 * WHY THESE NO LONGER SKIP ON 503
 * -------------------------------
 * These specs used to probe `/api/copilot/health` and skip the whole test
 * when it returned 503 ("no AI provider configured"). That gate was wrong,
 * and it hid the only part of this flow worth asserting.
 *
 * OpenBuild is the MCP **provider**, not an AI consumer: the deterministic
 * authoring surface is `lib/Mcp/OpenBuildToolProvider` and its handlers
 * (CreateApp, UpsertPage, AddWidget, …). `CopilotService::execute()` is a
 * pure dispatcher over those handlers — it never calls `assertAvailable()`
 * and needs no AI at all. Only `plan()` talks to an LLM.
 *
 * So the split is:
 *   - `/api/copilot/health` is stubbed 200. It is an *environment probe*
 *     that decides whether the UI renders, not an assertion target. The
 *     dedicated "hidden without a provider" scenario in
 *     copilot-wizard-generate.spec.ts stubs it 503 and covers the other side.
 *   - `/api/copilot/plan` is stubbed with a fixed plan. LLM output is
 *     non-deterministic and explicitly out of scope (see the spec's own
 *     `@e2e exclude` on REQ-OBAIC-002/004).
 *   - `/api/copilot/execute` is NOT stubbed. It hits the real backend and
 *     drives the real MCP handlers, so the manifest genuinely changes.
 *
 * The result is a fully deterministic run with no AI provider installed.
 */
import { test, expect } from '@playwright/test'
// nc-vue's first-visit overlays (CnWalkthrough tour + CnSupportDialog) each
// render a full-viewport backdrop that intercepts pointer events — live-verified
// as the actual cause of "Approving a proposal..."'s click on
// `[data-testid="copilot-panel-toggle"]` retrying for the full 90s timeout.
// Neither persists its "seen" state on this instance, so both can reopen on
// every run. Helpers shared with the other specs that hit the same overlays.
import { dismissWalkthrough, dismissSupportDialog } from './support/overlays'

const HEALTH_URL = '**/apps/openbuild/api/copilot/health'
const PLAN_URL = '**/apps/openbuild/api/copilot/plan'
const EXECUTE_URL = '**/apps/openbuild/api/copilot/execute'

/** Page id the stubbed plan creates. Upsert semantics make re-runs idempotent. */
const SUPPLIERS_PAGE_ID = 'e2e-suppliers'

const STUBBED_PLAN = {
	summary: 'Adds a suppliers page with a table widget.',
	steps: [
		// versionSlug: 'production' — UpsertPageHandler/AddWidgetHandler default
		// to 'development' when omitted, but the seeded hello-world fixture
		// (SeedHelloWorldFixture::VERSION_SLUG) only ever creates a single
		// 'production' version. Without this the real execute call 422s with
		// "No version 'development' found for app 'hello-world'." — live-verified.
		{ tool: 'openbuild.upsertPage', arguments: { appSlug: 'hello-world', versionSlug: 'production', pageId: SUPPLIERS_PAGE_ID, title: 'Suppliers', type: 'index', route: '/e2e-suppliers' } },
		{ tool: 'openbuild.addWidget', arguments: { appSlug: 'hello-world', versionSlug: 'production', pageId: SUPPLIERS_PAGE_ID, widgetType: 'table', widgetConfig: {} } },
	],
	manifests: {
		'hello-world@production': {
			current: { version: '1.0.0', menu: [], pages: [] },
			predicted: {
				version: '1.0.0',
				menu: [],
				pages: [{ id: SUPPLIERS_PAGE_ID, route: '/e2e-suppliers', type: 'index', title: 'Suppliers', config: { widgets: [{ type: 'table', config: {} }] } }],
			},
		},
	},
}

test.describe('Builder copilot panel (spec: ai-copilot)', () => {

	// These flows chain several deliberately generous waits (panel mount →
	// proposal render → real execute → reload → manifest reload) that add up
	// past the 30s project default on a loaded dev box. Raising the wall clock
	// does not weaken any assertion; it stops a slow machine from reading as a
	// product failure.
	test.describe.configure({ timeout: 90_000 })

	test.beforeEach(async ({ page }) => {
		// Health drives `copilotToggleVisible`, and the composable probes it
		// from `mounted()` and caches the result for the page's lifetime — so
		// the route MUST be installed before the first navigation.
		await page.route(HEALTH_URL, (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ available: true }),
		}))
		await page.goto('/apps/openbuild/builder/hello-world/pages')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		// NOT waitForLoadState('networkidle') — this NC instance's own
		// background chatter (notifications poll, user-status heartbeat) means
		// the network is never idle for 500ms on an authenticated page, so
		// networkidle never resolves and eats the whole 90s describe timeout
		// (live-verified: still hung with the walkthrough dismissed). Wait for
		// the page's real content instead — PageDesignerHost.vue's root.
		await expect(page.locator('.page-designer-host'), 'page designer must load').toBeVisible({ timeout: 20_000 })
	})

	// @e2e ai-copilot::approving-a-proposal-applies-it-to-the-open-app
	test('Approving a proposal applies it to the open app (spec: ai-copilot)', async ({ page }) => {
		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		// Capture the real execute response so a backend rejection surfaces as
		// a named failure instead of a mute "Suppliers never appeared".
		const executeResponse = page.waitForResponse((r) => r.url().includes('/api/copilot/execute'))

		await page.locator('[data-testid="copilot-panel-toggle"]').click()
		const panel = page.locator('[data-testid="copilot-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		await panel.locator('[data-testid="copilot-message-input"]').fill('add a suppliers page with a table widget')
		await panel.locator('[data-testid="copilot-message-input"]').press('Enter')

		const proposal = panel.locator('[data-testid="copilot-proposal"]')
		await expect(proposal, 'proposal card must render').toBeVisible({ timeout: 10_000 })
		await expect(proposal).toContainText('Adds a suppliers page with a table widget.')

		await proposal.locator('[data-testid="copilot-approve"]').click()

		const res = await executeResponse
		expect(res.status(), `execute must succeed — body: ${await res.text()}`).toBe(200)

		// The write must be PERSISTED, not merely painted: reload the designer
		// from the server and confirm the manifest carries the new page.
		await page.reload()
		await expect(page.locator('.page-designer-host'), 'page designer must reload').toBeVisible({ timeout: 20_000 })
		await expect(page.locator(`text=${SUPPLIERS_PAGE_ID}`).first()).toBeVisible({ timeout: 15_000 })
	})

	// @e2e ai-copilot::discarding-a-proposal-changes-nothing
	test('Discarding a proposal changes nothing (spec: ai-copilot)', async ({ page }) => {
		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		let executeCalled = false
		await page.route(EXECUTE_URL, (route) => {
			executeCalled = true
			return route.continue()
		})

		await page.locator('[data-testid="copilot-panel-toggle"]').click()
		const panel = page.locator('[data-testid="copilot-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })
		await panel.locator('[data-testid="copilot-message-input"]').fill('add a suppliers page')
		await panel.locator('[data-testid="copilot-message-input"]').press('Enter')

		const proposal = panel.locator('[data-testid="copilot-proposal"]')
		await expect(proposal).toBeVisible({ timeout: 10_000 })

		await proposal.locator('[data-testid="copilot-discard"]').click()

		expect(executeCalled, 'execute must never be called after Discard').toBe(false)
		await expect(proposal, 'the proposal must be dismissed').toHaveCount(0)
	})

	// @e2e ai-copilot::no-write-happens-before-approval
	test('No write happens before approval (spec: ai-copilot)', async ({ page }) => {
		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		const writeRequests: string[] = []
		page.on('request', (req) => {
			const url = req.url()
			if (url.includes('/api/copilot/execute') || (req.method() === 'PUT' && url.includes('/manifest'))) {
				writeRequests.push(`${req.method()} ${url}`)
			}
		})

		await page.locator('[data-testid="copilot-panel-toggle"]').click()
		const panel = page.locator('[data-testid="copilot-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })
		await panel.locator('[data-testid="copilot-message-input"]').fill('add a suppliers page')
		await panel.locator('[data-testid="copilot-message-input"]').press('Enter')

		const proposal = panel.locator('[data-testid="copilot-proposal"]')
		await expect(proposal).toBeVisible({ timeout: 10_000 })

		// The proposal is rendered; the user has not acted on it yet.
		expect(writeRequests, 'no write must happen before an explicit approval').toHaveLength(0)
	})
})
