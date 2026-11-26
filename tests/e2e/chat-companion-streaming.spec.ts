// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { expect, test } from '@playwright/test'

/**
 * Playwright e2e — AI Chat Companion + streaming (spec: ai-chat-companion-streaming).
 *
 * Validates the user-visible flow:
 *   1. FAB renders on /apps/buildiq/ (gated on /api/chat/health 200)
 *   2. Clicking the FAB opens the chat panel with the input ready
 *   3. Submitting a message renders the user bubble immediately
 *   4. While the response is in flight the Thinking indicator
 *      (data-testid=cn-ai-thinking) is visible with 3 animated dots
 *   5. When the response arrives the Thinking indicator disappears
 *      and an assistant bubble takes its place
 *   6. Streaming (openspec/specs/ai-chat-companion/spec.md):
 *        - Partial text shows BEFORE the call completes, and only grows
 *        - At least one heartbeat frame reaches the frontend before the
 *          stream's final frame
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL (default localhost:8080)
 *   - buildiq enabled, openregister enabled
 *   - /api/chat/health returns 200 (LLM provider configured —
 *     Ollama on the dev box) OR 503 (test skipped per spec)
 *   - Authenticated browser context from global-setup
 */
/**
 * The endpoint the widget ACTUALLY probes.
 *
 * These specs used to gate on `/apps/openregister/api/chat/health`, but the
 * `CnAiCompanion` widget in `@conduction/nextcloud-vue` resolves its backend
 * through `aiChatConfig.js`, whose `DEFAULT_CHAT_APP_ID` was flipped from
 * `openregister` to `hermiq` (ADR-034 Amendment 2026-07-05 "Default flip").
 * Buildiq does not pass `:chat-app-id`, so it gets the default.
 *
 * Probing OpenRegister therefore asked a question about a DIFFERENT app than
 * the one whose answer decides whether the FAB renders. On this container both
 * happen to be unavailable (OR 503 `no_provider`, hermiq 404 not installed) so
 * the skip fires either way — but on any deployment still inside OpenRegister's
 * `/api/chat/*` compat window, OR would answer 200 while hermiq is absent, the
 * skip would NOT fire, and every one of these specs would fail on a FAB that is
 * correctly hidden. Gate on the app the widget really calls.
 */
const CHAT_HEALTH_URL = '/index.php/apps/hermiq/api/chat/health'

/** Absent app → 404, not 503; treat any non-200 as "no chat backend". */
const chatUnavailable = (status: number) => status !== 200

test.describe('AI Chat Companion — FAB + thinking + response (spec: ai-chat-companion-streaming)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		// The Buildiq SPA hydrates async, so every test below needs the shell
		// mounted before it looks for the FAB. `waitForLoadState('networkidle')`
		// cannot provide that: it never settles on Nextcloud (ADR-074 rule 4),
		// so it burned its whole budget in EVERY test of this file — including
		// the ones that then immediately skip on an unreachable chat backend.
		//
		// `templates/index.php` ships an empty `<div id="content">`, so the app
		// content region only acquires a box once CnAppRoot has rendered into
		// it. The Dashboard's "Create app" entry point counts too — that is the
		// signal copilot-wizard-generate.spec.ts live-verified on this exact
		// route when it removed its own networkidle wait. The FAB itself is
		// deliberately NOT waited for here: whether it renders is what the
		// tests below assert.
		const appShell = page
			.locator('main, #app-content, .app-content, #content-vue')
			.first()
		const createApp = page
			.getByRole('button', { name: /create app|add application/i })
			.first()
		await expect(
			appShell.or(createApp).first(),
			'the Buildiq app shell must mount',
		).toBeVisible({ timeout: 30_000 })
	})

	test('FAB renders on app pages when chat health is 200', async ({
		page,
		request,
	}) => {
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(
			chatUnavailable(health.status()),
			'No chat backend reachable — chat companion intentionally hidden',
		)

		const fab = page.locator('[data-testid="cn-ai-fab"]')
		await expect(fab, 'FAB must be visible on /apps/buildiq/').toBeVisible({
			timeout: 10_000,
		})
		await expect(fab).toHaveAttribute('aria-label', /chat/i)
	})

	test('Clicking the FAB opens the chat panel with the input ready', async ({
		page,
		request,
	}) => {
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(chatUnavailable(health.status()), 'No chat backend reachable')

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel, 'panel must mount within 5s').toBeVisible({
			timeout: 5_000,
		})

		const input = panel.locator('textarea')
		await expect(input, 'message input must be focusable').toBeVisible()
		await expect(input, 'message input must be enabled').not.toBeDisabled()
	})

	test('Submitting a message shows the user bubble + Thinking indicator', async ({
		page,
		request,
	}) => {
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(chatUnavailable(health.status()), 'No chat backend reachable')

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
		await expect(
			thinking,
			'Thinking indicator must appear while waiting',
		).toBeVisible({ timeout: 2_000 })

		const dots = thinking.locator('.cn-ai-message-list__thinking-dot')
		await expect(dots, 'three animated dots').toHaveCount(3)
		await expect(thinking).toContainText(/thinking/i)
	})

	// QUARANTINED: requires a live AI chat backend not available in this environment.
	test('Thinking indicator clears once the response arrives', async ({
		page,
		request,
	}) => {
		test.skip(
			true,
			'QUARANTINED: requires a live AI chat backend not available in this environment.',
		)
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(chatUnavailable(health.status()), 'No chat backend reachable')

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
 * Streaming assertions (ai-chat-companion-streaming tasks 4.1-4.3).
 *
 * The surface is built: hermiq's `ChatStreamController` streams the
 * provider's deltas as `token` frames (`LlphantChatCall::streamChat()`, LLPhant
 * `generateChatStream()` on OpenAI and Ollama), emits a `heartbeat` frame
 * right after the headers and again before any frame that follows a 15 s
 * silence, and ends with `final`. `useAiChatStream` in nextcloud-vue appends
 * each token to the assistant bubble. These tests skip, like the ones above,
 * only when no chat backend answers its health check.
 */
test.describe('AI Chat Companion — true streaming (spec: ai-chat-companion-streaming)', () => {
	test.beforeEach(async ({ page }) => {
		// The same app-shell wait as the block above (it has no networkidle wait for the same reason).
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		const appShell = page.locator('main, #app-content, .app-content, #content-vue').first()
		const createApp = page.getByRole('button', { name: /create app|add application/i }).first()
		await expect(appShell.or(createApp).first(), 'the Buildiq app shell must mount').toBeVisible({ timeout: 30_000 })
	})

	test('partial response text appears before the call completes', async ({
		page,
		request,
	}) => {
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(chatUnavailable(health.status()), 'No chat backend reachable')
		test.setTimeout(150_000)

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// Record every text the streaming bubble shows, from the first token on.
		await page.evaluate(() => {
			const w = window as unknown as { __cnBubbleTexts: string[] }
			w.__cnBubbleTexts = []
			const record = () => {
				const bubbles = document.querySelectorAll('[data-testid="cn-ai-panel"] .cn-ai-message-list__bubble--assistant:not(.cn-ai-message-list__bubble--thinking)')
				const last = bubbles[bubbles.length - 1]
				const text = last ? (last.textContent || '').trim() : ''
				const seen = w.__cnBubbleTexts
				if (text !== '' && seen[seen.length - 1] !== text) {
					seen.push(text)
				}
			}
			new MutationObserver(record).observe(document.body, { childList: true, subtree: true, characterData: true })
		})

		const streamDone = page.waitForResponse((r) => r.url().includes('/api/chat/stream'), { timeout: 140_000 })
		await panel.locator('textarea').fill('Write three paragraphs about Dutch windmills, each of at least four sentences.')
		await panel.locator('textarea').press('Enter')
		const stream = await streamDone
		test.skip(!(stream.headers()['content-type'] || '').includes('text/event-stream'), 'The backend answered without streaming (the non-streaming fallback)')
		await stream.finished()
		await expect(page.locator('[data-testid="cn-ai-thinking"]')).toBeHidden({ timeout: 30_000 })

		const texts = await page.evaluate(() => (window as unknown as { __cnBubbleTexts: string[] }).__cnBubbleTexts)
		const final = texts[texts.length - 1] || ''
		expect(final, 'the assistant answered').not.toBe('')
		expect(
			texts.some((text) => text.length > 0 && text.length < final.length),
			'a strictly shorter, non-empty prefix showed before the answer was complete',
		).toBe(true)
		for (let i = 1; i < texts.length; i++) {
			expect(texts[i].startsWith(texts[i - 1]), `text only grows: step ${i} keeps step ${i - 1} as its start`).toBe(true)
		}
	})

	test('long-running call surfaces at least one heartbeat to the frontend', async ({
		page,
		request,
	}) => {
		const health = await request.get(CHAT_HEALTH_URL)
		test.skip(chatUnavailable(health.status()), 'No chat backend reachable')
		test.setTimeout(150_000)

		await page.locator('[data-testid="cn-ai-fab"]').click()
		const panel = page.locator('[data-testid="cn-ai-panel"]')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		const streamDone = page.waitForResponse((r) => r.url().includes('/api/chat/stream'), { timeout: 140_000 })
		await panel.locator('textarea').fill('Explain in two paragraphs how a register and a schema relate.')
		await panel.locator('textarea').press('Enter')
		const stream = await streamDone
		test.skip(!(stream.headers()['content-type'] || '').includes('text/event-stream'), 'The backend answered without streaming (the non-streaming fallback)')
		const body = await stream.text()

		const heartbeat = body.indexOf('event: heartbeat')
		const final = body.indexOf('event: final')
		expect(heartbeat, 'at least one heartbeat frame reached the frontend').toBeGreaterThanOrEqual(0)
		expect(final, 'the stream completed with a final frame').toBeGreaterThan(heartbeat)
		// The Thinking indicator covers the wait until the first token.
		await expect(page.locator('[data-testid="cn-ai-thinking"]')).toBeHidden({ timeout: 30_000 })
	})
})
