// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — builder copilot side panel (spec: ai-copilot).
 *
 * Same skip-on-503 / route-stub approach as
 * tests/e2e/copilot-wizard-generate.spec.ts, exercised against the seeded
 * `hello-world` virtual app (see tests/e2e/global-setup.ts).
 *
 * Scenarios (spec: ai-copilot REQ-OBAIC-007):
 *   1. Approving a proposal applies it to the open app — the plan call is
 *      stubbed; the execute call hits the real backend and the designer's
 *      manifest gains the new page.
 *   2. Discarding a proposal changes nothing.
 *   3. No write happens before approval — zero requests to
 *      `/api/copilot/execute` (and zero manifest PUTs) between the
 *      proposal rendering and the user acting on it.
 *
 * CI-run only: written but not executed against the shared dev instance as
 * part of this change (per task instructions).
 */
import { test, expect } from '@playwright/test'

const HEALTH_URL = '/index.php/apps/openbuild/api/copilot/health'
const PLAN_URL = '**/apps/openbuild/api/copilot/plan'
const EXECUTE_URL = '**/apps/openbuild/api/copilot/execute'

const STUBBED_PLAN = {
	summary: 'Adds a suppliers page with a table widget.',
	steps: [
		{ tool: 'openbuild.upsertPage', arguments: { appSlug: 'hello-world', pageId: 'e2e-suppliers', title: 'Suppliers', type: 'index', route: '/e2e-suppliers' } },
		{ tool: 'openbuild.addWidget', arguments: { appSlug: 'hello-world', pageId: 'e2e-suppliers', widgetType: 'table', widgetConfig: {} } },
	],
	manifests: {
		'hello-world@development': {
			current: { version: '1.0.0', menu: [], pages: [] },
			predicted: {
				version: '1.0.0',
				menu: [],
				pages: [{ id: 'e2e-suppliers', route: '/e2e-suppliers', type: 'index', title: 'Suppliers', config: { widgets: [{ type: 'table', config: {} }] } }],
			},
		},
	},
}

test.describe('Builder copilot panel (spec: ai-copilot)', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/openbuild/builder/hello-world/pages')
		await page.waitForLoadState('networkidle')
	})

	// @e2e ai-copilot::approving-a-proposal-applies-it-to-the-open-app
	test('Approving a proposal applies it to the open app (spec: ai-copilot)', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured — copilot panel intentionally hidden')

		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(STUBBED_PLAN) }))

		await page.locator('[data-testid="copilot-panel-toggle"]').click()
		const panel = page.locator('[data-testid="copilot-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		await panel.locator('[data-testid="copilot-message-input"]').fill('add a suppliers page with a table widget')
		await panel.locator('[data-testid="copilot-message-input"]').press('Enter')

		const proposal = panel.locator('[data-testid="copilot-proposal"]')
		await expect(proposal, 'proposal card must render').toBeVisible({ timeout: 10_000 })
		await expect(proposal).toContainText('Adds a suppliers page with a table widget.')

		await proposal.locator('[data-testid="copilot-approve"]').click()

		// The designer's manifest now contains the new page — reload and confirm.
		await page.waitForLoadState('networkidle')
		await expect(page.locator('text=Suppliers').first()).toBeVisible({ timeout: 15_000 })
	})

	// @e2e ai-copilot::discarding-a-proposal-changes-nothing
	test('Discarding a proposal changes nothing (spec: ai-copilot)', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured')

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
		await expect(page.locator('text=Suppliers')).toHaveCount(0)
	})

	// @e2e ai-copilot::no-write-happens-before-approval
	test('No write happens before approval (spec: ai-copilot)', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured')

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
