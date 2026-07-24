// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — Agent Workspace (spec: agent-workspace).
 *
 * Implements tasks.md 5.2: create an agent scoped to two tools, chat with
 * it, approve a proposal, confirm the run appears in run-history with
 * tool-call detail; confirm a disallowed tool request is rejected.
 *
 * Same skip-on-503 / route-stub approach as tests/e2e/copilot-panel.spec.ts
 * and tests/e2e/copilot-wizard-generate.spec.ts, exercised against the
 * seeded `hello-world` virtual app (see tests/e2e/global-setup.ts).
 *
 * CI-run only: written but not executed against the shared dev instance as
 * part of this change (per task instructions — no deploy to the shared dev
 * instance). Run `npm run test:e2e:install` once, then `npm run test:e2e`.
 */
import { test, expect } from '@playwright/test'

const APP_SLUG = process.env.NC_OPENBUILD_TEST_SLUG ?? 'hello-world'
const HEALTH_URL = '/index.php/apps/openbuild/api/copilot/health'
const PLAN_URL = '**/apps/openbuild/api/copilot/plan'
const EXECUTE_URL = '**/apps/openbuild/api/copilot/execute'
const RUNS_URL_PATTERN = /\/apps\/openbuild\/api\/agents\/.+\/runs/

const SCOPED_PLAN = {
	summary: 'Adds a contact-details step to the intake form.',
	steps: [
		{ tool: 'openbuild.upsertPage', arguments: { appSlug: APP_SLUG, pageId: 'e2e-agent-contact', title: 'Contact details', type: 'form', route: '/e2e-agent-contact' } },
	],
	manifests: {
		[`${APP_SLUG}@development`]: {
			current: { version: '1.0.0', menu: [], pages: [] },
			predicted: {
				version: '1.0.0',
				menu: [],
				pages: [{ id: 'e2e-agent-contact', route: '/e2e-agent-contact', type: 'form', title: 'Contact details', config: {} }],
			},
		},
	},
}

test.describe('agent-workspace — Agents page', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/openbuild/agents')
		await page.waitForSelector('.agents-page', { timeout: 20_000 })

		await page.getByLabel(/application/i).click()
		await page.getByRole('option', { name: new RegExp(APP_SLUG, 'i') }).first().click()
	})

	test('create an agent scoped to two tools', async ({ page }) => {
		await page.getByRole('button', { name: /new agent/i }).click()
		await page.waitForSelector('.agent-edit')

		await page.getByLabel(/^name$/i).fill('E2E page builder assistant')
		await page.getByLabel(/instructions/i).fill('Only add form pages for this test.')

		await page.getByLabel(/enabled tools/i).click()
		await page.getByRole('option', { name: /create or update page/i }).click()
		await page.getByRole('option', { name: /add widget/i }).click()
		// Close the multi-select dropdown before saving.
		await page.keyboard.press('Escape')

		await page.getByRole('button', { name: /^save$/i }).click()
		await expect(page.locator('.agent-edit')).toHaveCount(0, { timeout: 10_000 })

		const row = page.locator('[data-testid="agent-row"]', { hasText: 'E2E page builder assistant' })
		await expect(row).toBeVisible()
		await expect(row).toContainText('2 tool(s) enabled')
	})

	test('chat with an agent, approve a proposal, confirm the run appears in run-history with tool-call detail', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured — copilot chat intentionally hidden')

		const row = page.locator('[data-testid="agent-row"]', { hasText: 'E2E page builder assistant' })
		// If the seeded agent from the previous test isn't present (fresh run
		// order — Playwright test files run independently), create it inline
		// so this test is independently runnable.
		if (await row.count() === 0) {
			await page.getByRole('button', { name: /new agent/i }).click()
			await page.waitForSelector('.agent-edit')
			await page.getByLabel(/^name$/i).fill('E2E page builder assistant')
			await page.getByLabel(/enabled tools/i).click()
			await page.getByRole('option', { name: /create or update page/i }).click()
			await page.keyboard.press('Escape')
			await page.getByRole('button', { name: /^save$/i }).click()
			await expect(page.locator('.agent-edit')).toHaveCount(0, { timeout: 10_000 })
		}

		await row.click()
		const chatPanel = page.locator('[data-testid="copilot-panel"]')
		await expect(chatPanel).toBeVisible({ timeout: 5_000 })
		await expect(page.locator('[data-testid="copilot-acting-as"]')).toContainText('E2E page builder assistant')

		await page.route(PLAN_URL, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(SCOPED_PLAN) }))

		await chatPanel.locator('[data-testid="copilot-message-input"]').fill('add a contact-details step to the intake form')
		await chatPanel.locator('[data-testid="copilot-message-input"]').press('Enter')

		const proposal = chatPanel.locator('[data-testid="copilot-proposal"]')
		await expect(proposal, 'proposal card must render').toBeVisible({ timeout: 10_000 })

		const executeRequest = page.waitForRequest(EXECUTE_URL)
		await proposal.locator('[data-testid="copilot-approve"]').click()
		const request2 = await executeRequest
		expect(request2.postDataJSON().agentId, 'execute request must carry the agent id').toBeTruthy()

		// Switch to the run-history tab and confirm the applied run with tool-call detail.
		await page.getByRole('button', { name: /run history/i }).click()
		const runsResponse = page.waitForResponse(RUNS_URL_PATTERN)
		await runsResponse

		const runRow = page.locator('[data-testid="agent-run-row"]').first()
		await expect(runRow).toBeVisible({ timeout: 10_000 })
		await expect(runRow.locator('[data-testid="agent-run-outcome"]')).toHaveText(/applied/i)
		await expect(runRow.locator('[data-testid="agent-run-tool-call"]').first()).toContainText('openbuild.upsertPage')
	})

	test('a disallowed tool request is rejected', async ({ page, request }) => {
		const health = await request.get(HEALTH_URL)
		test.skip(health.status() === 503, 'No AI provider configured')

		const row = page.locator('[data-testid="agent-row"]', { hasText: 'E2E page builder assistant' })
		await expect(row).toBeVisible({ timeout: 10_000 })
		await row.click()

		const chatPanel = page.locator('[data-testid="copilot-panel"]')
		await expect(chatPanel).toBeVisible({ timeout: 5_000 })

		// The scoped agent only has upsertPage/addWidget — createApp is in the
		// base eight-tool catalogue but NOT this agent's enabledTools, so the
		// server-side allow-list intersection must reject it (422 plan_invalid).
		// The stubbed response mirrors CopilotService::planWithinContext()'s
		// real rejection envelope for a step outside the narrowed allow-list.
		await page.route(PLAN_URL, (route) => route.fulfill({
			status: 422,
			contentType: 'application/json',
			body: JSON.stringify({ error: 'plan_invalid', message: 'Step outside the agent allow-list.' }),
		}))

		await chatPanel.locator('[data-testid="copilot-message-input"]').fill('create a brand new app for me')
		await chatPanel.locator('[data-testid="copilot-message-input"]').press('Enter')

		await expect(chatPanel.locator('.copilot-panel__bubble--error')).toBeVisible({ timeout: 10_000 })
		await expect(chatPanel.locator('[data-testid="copilot-proposal"]')).toHaveCount(0)
	})
})
