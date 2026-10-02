/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The JS half of `CopilotServiceTest::testARealPlanPredictsAManifestTheValidatorAccepts`.
 * That test pins what the PHP predictor builds from a real plan; this one runs
 * the same fixture through the canonical validator the wizard gates on, so a
 * change to the predictor cannot quietly start producing a manifest that
 * disables Confirm and create again.
 *
 * The fixture is a trimmed copy of the plan the live copilot returned on
 * 2026-09-18. Against development it predicted a manifest carrying 15 errors.
 */
import { validateManifest } from '@conduction/nextcloud-vue'
import { describe, expect, it } from 'vitest'
import manifest from '../Fixtures/copilot-real-plan-manifest.json'

describe('the manifest a real copilot plan predicts', () => {
	it('passes the canonical validator', () => {
		const result = validateManifest(manifest)

		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
	})

	it('carries the three shapes that used to fail it', () => {
		const dashboard = manifest.pages.find((p) => p.type === 'dashboard')
		const form = manifest.pages.find((p) => p.type === 'form')

		// 1. Every widget has an id and a title.
		expect(dashboard.config.widgets.length).toBeGreaterThan(0)
		for (const widget of dashboard.config.widgets) {
			expect(widget.id).toBeTruthy()
			expect(widget.title).toBeTruthy()
		}

		// 2. The layout is a list of placements, not the word "grid".
		expect(Array.isArray(dashboard.config.layout)).toBe(true)

		// 3. The form has field objects and one place to post to.
		for (const field of form.config.fields) {
			expect(typeof field).toBe('object')
			expect(field.key).toBeTruthy()
		}
		const destinations = ['submitHandler', 'submitEndpoint'].filter(
			(k) => form.config[k],
		)
		expect(destinations).toHaveLength(1)
	})
})
