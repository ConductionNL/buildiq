// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — Ask AI in the condition-action rule editor
 * (ai-copilot-documents-and-code-help REQ-BQCH-001/002/004).
 *
 * The language model is out of scope (its output is not deterministic), so
 * `/api/copilot/health` and `/api/copilot/assist` are stubbed, the same way
 * copilot-wizard-generate.spec.ts stubs the plan. The rule set list and the
 * two save calls are stubbed too, so the spec asserts exactly what the editor
 * sends on Save: the accepted condition, and nothing before Save.
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL, buildiq enabled.
 *   - Authenticated browser context from global-setup.
 */
import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from './support/overlays.ts'

const RULE_SET = {
	id: 'e2e-assist-rule-set',
	slug: 'e2e-assist-minors',
	name: 'Minors',
	ruleType: 'condition-action',
	ownerApp: 'e2e-assist',
	status: 'draft',
	condition: '',
}

test.describe('Ask AI in the rule editor', () => {
	test('asks for a rule condition, accepts it, and saves the rule', async ({ page }) => {
		const saved: Array<{ url: string, body: Record<string, unknown> }> = []
		let assistBody: Record<string, unknown> | null = null

		await page.route('**/apps/buildiq/api/copilot/health', (route) => route.fulfill({ status: 200, json: { status: 'ok' } }))
		await page.route('**/apps/buildiq/api/copilot/assist', async (route) => {
			assistBody = route.request().postDataJSON()
			await route.fulfill({ status: 200, json: { mode: 'write', value: 'applicant.age < 18', explanation: 'Applicants younger than 18.' } })
		})
		await page.route('**/apps/openregister/api/objects/buildiq/rule-set**', async (route) => {
			if (route.request().method() === 'GET') {
				await route.fulfill({ status: 200, json: { results: [RULE_SET] } })
				return
			}
			saved.push({ url: route.request().url(), body: route.request().postDataJSON() })
			await route.fulfill({ status: 201, json: { id: RULE_SET.id } })
		})
		await page.route('**/apps/openregister/api/objects/buildiq/condition-action-rule**', async (route) => {
			saved.push({ url: route.request().url(), body: route.request().postDataJSON() })
			await route.fulfill({ status: 201, json: { id: 'rule-1' } })
		})

		await page.goto('/apps/buildiq/business-rules')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		await page.getByRole('button', { name: 'Edit' }).first().click()
		await page.getByRole('button', { name: 'Ask AI' }).click()
		await page.getByLabel('What should it do?').fill('applicants younger than 18')
		await page.getByRole('button', { name: 'Write it' }).click()

		await expect(page.getByTestId('copilot-assist-value')).toHaveText('applicant.age < 18')
		await expect(page.getByTestId('copilot-assist-explanation')).toHaveText('Applicants younger than 18.')
		expect(assistBody).toMatchObject({ target: 'feel-condition', mode: 'write', appSlug: 'e2e-assist' })
		expect(saved).toHaveLength(0)

		await page.getByRole('button', { name: 'Accept' }).click()
		await expect(page.getByTestId('rule-condition').locator('textarea')).toHaveValue('applicant.age < 18')
		expect(saved).toHaveLength(0)

		await page.getByRole('button', { name: 'Save' }).click()
		await expect.poll(() => saved.length).toBe(2)
		expect(saved[1].body).toMatchObject({ ruleSetId: RULE_SET.slug, condition: 'applicant.age < 18' })
	})

	test('shows no Ask AI button without a provider', async ({ page }) => {
		await page.route('**/apps/buildiq/api/copilot/health', (route) => route.fulfill({ status: 503, json: { status: 'unavailable', reason: 'no_provider' } }))
		await page.route('**/apps/openregister/api/objects/buildiq/rule-set**', (route) => route.fulfill({ status: 200, json: { results: [RULE_SET] } }))

		await page.goto('/apps/buildiq/business-rules')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		await page.getByRole('button', { name: 'Edit' }).first().click()

		await expect(page.getByTestId('rule-condition')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Ask AI' })).toHaveCount(0)
	})
})
