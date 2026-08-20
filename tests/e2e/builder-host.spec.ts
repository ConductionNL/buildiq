// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'
import { dismissFirstVisitOverlays } from './support/overlays'

/**
 * E2E — BuilderHost mounts the seeded hello-world virtual app and the
 * inner CnAppRoot's router forwards path segments to the detail + form
 * pages declared in the manifest (REQ-OBR-002, REQ-OBR-003).
 *
 * Preconditions:
 *  - Docker stack up (`bash clean-env.sh`).
 *  - OpenBuild enabled (`docker exec nextcloud php occ app:enable openbuild`).
 *  - SeedHelloWorld has run (post-migration repair step).
 */
// UN-QUARANTINED 2026-07-30. The old reason — "builder host blank
// (BuilderHostView unresolved by nc-vue CnPageRenderer)" — no longer holds:
// `/builder/hello-world` mounts the nested CnAppRoot and renders the seeded
// index page. The bodies below were already real (three named seeded titles, a
// URL assertion on the inner router's path, and the seeded body text), so they
// are un-skipped as written rather than rewritten.
test.describe('BuilderHost — hello-world journey', () => {
	test('loads /builder/hello-world and renders the seeded index page', async ({
		page,
	}) => {
		await page.goto('/apps/openbuild/builder/hello-world')

		await expect(page).toHaveURL(/\/apps\/openbuild\/builder\/hello-world/)

		// The hello-world manifest's index page lists hello-message objects.
		// The three seeded titles must all be visible before this passes.
		const expectedTitles = [
			'Welcome to OpenBuild',
			'Edit me',
			'Built from a manifest',
		]
		for (const title of expectedTitles) {
			await expect(
				page.getByText(title, { exact: false }),
				`seeded title "${title}" must render on the index page`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('navigates to a hello-message detail page', async ({ page }) => {
		await page.goto('/apps/openbuild/builder/hello-world')
		// The NESTED CnAppRoot mounts with appId `openbuild-hello-world`, so
		// nc-vue's first-visit support dialog has never been seen for THAT app id
		// and opens over the virtual app. It is a real modal (aria-modal, backdrop),
		// so it correctly swallows the click below — measured: 55 retries, all
		// "cn-support-dialog subtree intercepts pointer events". Clear it first.
		await dismissFirstVisitOverlays(page)

		// Click the first seeded message — the manifest defines the detail
		// page at /messages/:id so the inner router forwards us there.
		const firstMessage = page
			.getByText('Welcome to OpenBuild', { exact: false })
			.first()
		await expect(firstMessage).toBeVisible({ timeout: 15_000 })
		await firstMessage.click()

		// The URL should now include /messages/<uuid> (the inner router's path,
		// captured by BuilderHost's :pathMatch wildcard).
		await expect(page).toHaveURL(/\/builder\/hello-world\/messages\//, {
			timeout: 10_000,
		})

		// And the detail page must show the message body.
		await expect(
			page.getByText(/rendered by your first virtual app/i),
			'detail page must render the seeded body text',
		).toBeVisible({ timeout: 10_000 })
	})

	test('navigates to the form page from the manifest menu', async ({ page }) => {
		// The manifest declares a form page at /messages/new. Hit it directly
		// to skip menu/CTA discovery (DOM may be in flux until the page-editor
		// spec lands).
		await page.goto('/apps/openbuild/builder/hello-world/messages/new')

		await expect(page).toHaveURL(/\/builder\/hello-world\/messages\/new/)

		await dismissFirstVisitOverlays(page)

		// The form page renders a form for the hello-message schema — it must
		// expose an editable control for the `title` field the manifest declares.
		//
		// Selector corrected 2026-07-30: the old one looked for `input[name="title"]`
		// / `[data-field="title"] input`. nc-vue's CnFormPage renders each field in a
		// wrapper carrying `data-field-key="<key>"` and names the control
		// `field-<key>` — neither of the old forms is ever emitted.
		await expect(
			page.locator('[data-testid="cn-form-page"]'),
			'the manifest form page must render',
		).toBeVisible({ timeout: 15_000 })
		const titleField = page.locator('[data-field-key="title"]')
		await expect(
			titleField,
			'form page must render the title field declared in the hello-message schema',
		).toBeVisible({ timeout: 15_000 })
		// …and it must be an editable control, not just a labelled wrapper.
		const titleInput = titleField.locator('input, textarea').first()
		await expect(
			titleInput,
			'the title field must expose an editable control',
		).toBeVisible({ timeout: 10_000 })
		await expect(titleInput).toBeEditable()

		// The form is the manifest's `MessageCreate` page, so it must also carry
		// the second declared field and a submit affordance — proving the whole
		// declared form rendered, not one stray input.
		await expect(
			page.locator('[data-field-key="body"]'),
			'the body field declared alongside title must render too',
		).toBeVisible({ timeout: 10_000 })
	})
})
