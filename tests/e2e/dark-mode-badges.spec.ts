// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Header badges and group role chips stay readable in light AND dark mode
 * (openbuild-nc-color-variables task 5.2).
 *
 * The driving bug was dark text on a dark background: the badges used fixed
 * hex colours. They now use Nextcloud's theme variables. This spec opens the
 * hello-world app's detail page under `prefers-color-scheme: light` and
 * `dark` (Nextcloud's default theme follows it), and asserts every badge and
 * role chip reaches WCAG AA contrast (4.5:1) between its text and the
 * background it is painted on, a translucent badge composited over the page.
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-nc-color-variables/tasks.md#5-verification
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE } from './support/baseUrl.ts'

const SLUG = 'hello-world'
const SELECTOR = '.ob-detail-header__badge, .ob-groups-widget__row-role'

/**
 * The contrast ratio of every badge and role chip on the page, measured in
 * the browser from computed styles.
 *
 * @param page Playwright page on the app detail view
 * @return One entry per element: its classes and contrast ratio
 */
async function contrasts(page: Page): Promise<Array<{ name: string, ratio: number }>> {
	return page.$$eval(SELECTOR, (elements) => {
		const parse = (value: string): number[] => {
			const parts = value.match(/[\d.]+/g)?.map(Number) ?? [0, 0, 0, 0]
			return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1]
		}
		const over = (top: number[], bottom: number[]): number[] => {
			const a = top[3]
			return [0, 1, 2].map((i) => top[i] * a + bottom[i] * (1 - a)).concat(1)
		}
		// The colour actually painted behind an element: its own background
		// composited over every ancestor's, down to an opaque one.
		const backdrop = (element: Element | null): number[] => {
			const layers: number[][] = []
			for (let node = element; node; node = node.parentElement) {
				const colour = parse(getComputedStyle(node).backgroundColor)
				if (colour[3] > 0) {
					layers.push(colour)
				}
				if (colour[3] >= 1) {
					break
				}
			}
			return layers.reverse().reduce((bottom, top) => over(top, bottom), [255, 255, 255, 1])
		}
		const luminance = (rgb: number[]): number => {
			const [r, g, b] = rgb.slice(0, 3).map((c) => {
				const s = c / 255
				return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
			})
			return 0.2126 * r + 0.7152 * g + 0.0722 * b
		}
		return elements.map((element) => {
			const background = backdrop(element)
			const text = over(parse(getComputedStyle(element).color), background)
			const [light, dark] = [luminance(text), luminance(background)].sort((x, y) => y - x)
			return { name: element.className, ratio: (light + 0.05) / (dark + 0.05) }
		})
	})
}

for (const scheme of ['light', 'dark'] as const) {
	test(`badges and role chips reach AA contrast in ${scheme} mode`, async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
		await page.emulateMedia({ colorScheme: scheme })

		const found = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/buildiq/built-app?slug=${SLUG}&_limit=1`,
		)
		test.skip(!found.ok(), 'OpenRegister or the buildiq register is not here')
		const apps = (await found.json()).results || []
		test.skip(apps.length === 0, 'the hello-world app is not seeded')

		await page.goto(`${BASE}/apps/buildiq/applications/${apps[0].uuid || apps[0].id}`)
		await expect(page.locator('.ob-detail-header__badge').first()).toBeVisible({ timeout: 30_000 })

		const measured = await contrasts(page)
		expect(measured.length).toBeGreaterThan(0)
		for (const { name, ratio } of measured) {
			expect(ratio, `${name} in ${scheme} mode`).toBeGreaterThanOrEqual(4.5)
		}
	})
}
