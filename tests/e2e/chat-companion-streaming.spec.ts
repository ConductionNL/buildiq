// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — AI Chat Companion + streaming (spec: ai-chat-companion-streaming).
 *
 * Validates the user-visible flow:
 *   1. FAB renders on /apps/openbuild/ (gated on /api/chat/health 200)
 *   2. Clicking the FAB opens the chat panel with the input ready
 *   3. Submitting a message renders the user bubble immediately
 *   4. While the response is in flight the Thinking indicator
 *      (data-testid=cn-ai-thinking) is visible with 3 animated dots
 *   5. When the response arrives the Thinking indicator disappears
 *      and an assistant bubble takes its place
 *   6. Streaming-aware specs (skipped today, enabled once the
 *      ai-chat-companion-streaming change lands):
 *        - First token arrives BEFORE the LLM call completes
 *          (assistant bubble has partial text mid-flight)
 *        - A long-running call surfaces ≥2 heartbeat events to the
 *          frontend (network requests panel shows them)
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL (default localhost:8080)
 *   - openbuild enabled, openregister enabled
 *   - /api/chat/health returns 200 (LLM provider configured —
 *     Ollama on the dev box) OR 503 (test skipped per spec)
 *   - Authenticated browser context from global-setup
 */
test.describe('AI Chat Companion — FAB + thinking + response (spec: ai-chat-companion-streaming)', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/openbuild/')
		// The OpenBuild SPA hydrates async; wait for the FAB or for the
		// health probe to surface a no_provider deployment.
		await page.waitForLoadState('networkidle')
	})

	test('FAB renders on app pages when chat health is 200', async ({ page, request }) => {
		const health = await request.get('/index.php/apps/openregister/api/chat/health')
		test.skip(health.status() === 503, 'No LLM provider configured — chat companion intentionally hidden')

		const fab = page.locator('[data-testid="cn-ai-fab"]')
		await expect(fab, 'FAB must be visible on /apps/openbuild/').toBeVisible({ timeout: 10_000 })
		await expect(fab).toHaveAttribute('aria-label', /chat/i)
	})

	test('Clicking the FAB opens the chat panel with the input ready', async ({ page, request }) => {
		const health = await request.get('/index.php/apps/openregister/api/chat/health')
		test.skip(health.status() === 503, 'No LLM provider configured')

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel, 'panel must mount within 5s').toBeVisible({ timeout: 5_000 })

		const input = panel.locator('textarea')
		await expect(input, 'message input must be focusable').toBeVisible()
		await expect(input, 'message input must be enabled').not.toBeDisabled()
	})

	test('Submitting a message shows the user bubble + Thinking indicator', async ({ page, request }) => {
		const health = await request.get('/index.php/apps/openregister/api/chat/health')
		test.skip(health.status() === 503, 'No LLM provider configured')

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		const prompt = 'reply with exactly: ok'
		await panel.locator('textarea').fill(prompt)
		await panel.locator('textarea').press('Enter')

		// User bubble appears immediately (synchronous local render).
		await expect(
			page.locator('.cn-ai-message-list__bubble--user'),
			'user message bubble must render synchronously',
		).toContainText(prompt, { timeout: 2_000 })

		// Thinking indicator with 3 animated dots is visible while the
		// LLM call is in flight (between submit and first token/final).
		const thinking = page.locator('[data-testid="cn-ai-thinking"]')
		await expect(thinking, 'Thinking indicator must appear while waiting').toBeVisible({ timeout: 2_000 })

		const dots = thinking.locator('.cn-ai-message-list__thinking-dot')
		await expect(dots, 'three animated dots').toHaveCount(3)
		await expect(thinking).toContainText(/thinking/i)
	})

	test('Thinking indicator clears once the response arrives', async ({ page, request }) => {
		const health = await request.get('/index.php/apps/openregister/api/chat/health')
		test.skip(health.status() === 503, 'No LLM provider configured')

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		await panel.locator('textarea').fill('reply with exactly: ok')
		await panel.locator('textarea').press('Enter')

		// Once the response lands the thinking bubble must go away and
		// the response bubble must appear. Allow up to 90s for Ollama
		// cold-load on a fresh stack.
		await expect(
			page.locator('[data-testid="cn-ai-thinking"]'),
			'Thinking indicator must disappear after final',
		).toBeHidden({ timeout: 90_000 })

		await expect(
			page.locator('.cn-ai-message-list__bubble--assistant').last(),
			'assistant bubble must contain non-empty text',
		).not.toBeEmpty({ timeout: 5_000 })
	})
})

/**
 * Streaming-only assertions — gated on the orchestrator's §5.3
 * (token events) and §6 (15s heartbeat) landing. Skipped today;
 * enabled by the ai-chat-companion-streaming change.
 */
test.describe('AI Chat Companion — true streaming (gated on ai-chat-companion-streaming)', () => {

	test.skip(({}, testInfo) => {
		// Toggle this off once the streaming change is applied + the
		// configured provider exposes generateStreamOfText.
		return true
	}, 'Streaming surface not yet wired — see openspec/changes/ai-chat-companion-streaming/')

	test('partial response text appears before the call completes', async ({ page }) => {
		// Long-prompt test: ask for a multi-paragraph answer, assert the
		// assistant bubble's text grows over time rather than appearing
		// all at once.
	})

	test('long-running call surfaces at least one heartbeat to the frontend', async ({ page }) => {
		// 35s prompt + watch network panel for `event: heartbeat` frames.
	})
})
