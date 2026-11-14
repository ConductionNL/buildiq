/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row `ux-onboarding-builder`: the builder guides a first-time maker through
 * its own walkthrough. Runs buildiq's manifest through the library's own
 * walkthrough machine, so a tour that would not start, or a step that
 * points at a missing spotlight target, fails here.
 *
 * @spec openspec/changes/openbuild-first-time-setup/specs/openbuild-first-time-setup/spec.md#requirement-the-builder-guides-a-first-time-maker-through-its-own-walkthrough
 */

import {
	__resetWalkthroughCacheForTests,
	useWalkthrough,
} from '@conduction/nextcloud-vue/src/composables/useWalkthrough.js'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(join(root, 'src/manifest.json'), 'utf8'))
// CnAppRoot and CnWalkthrough pass no appVersion, so the machine compares
// each step's sinceVersion with manifest.version, as it does here.
const appVersion = manifest.version

/**
 * Every `data-walkthrough-id` and `data-testid` value declared in a .vue file
 * under a directory: CnWalkthrough resolves an element step by either.
 *
 * @param {string} dir The directory to scan.
 * @return {Set<string>} The ids.
 */
function walkthroughIds(dir) {
	const ids = new Set()
	for (const name of readdirSync(dir)) {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			walkthroughIds(path).forEach((id) => ids.add(id))
		} else if (name.endsWith('.vue')) {
			for (const match of readFileSync(path, 'utf8').matchAll(/data-(?:walkthrough-id|testid)="([^"]+)"/g)) {
				ids.add(match[1])
			}
		}
	}
	return ids
}

afterEach(() => {
	__resetWalkthroughCacheForTests()
})

describe('the builder walkthrough', () => {
	it('starts for a maker who never finished it, showing where to define a schema and later where to add a page', () => {
		const walkthrough = useWalkthrough('buildiq', manifest)

		expect(walkthrough.autoStartTour.value?.id).toBe('buildiq:getting-started')

		walkthrough.start('buildiq:getting-started')
		const steps = walkthrough.activeTour.value.steps
		const schemaStep = steps.findIndex((step) => step.target?.kind === 'element' && step.target.ref === 'schema-designer')
		const pageStep = steps.findIndex((step) => step.target?.kind === 'element' && step.target.ref === 'page-designer')

		expect(schemaStep).toBeGreaterThan(-1)
		expect(pageStep).toBeGreaterThan(schemaStep)
	})

	it('does not start again for a maker who finished the current version', () => {
		const walkthrough = useWalkthrough('buildiq', manifest, { appVersion, seenVersion: appVersion })

		expect(walkthrough.autoStartTour.value).toBeNull()
	})

	it('remembers completion per user under its own key', () => {
		expect(manifest.walkthrough.enabled).toBe(true)
		expect(manifest.walkthrough.completionConfigKey).toBe('walkthrough_completed_version')
	})

	it('points every element step at a spotlight target the builder or its library renders', () => {
		const ids = new Set([
			...walkthroughIds(join(root, 'src')),
			...walkthroughIds(join(root, 'node_modules/@conduction/nextcloud-vue/src/components')),
		])
		const tour = manifest.walkthrough.tours.find((t) => t.id === 'buildiq:getting-started')
		const missing = tour.steps
			.filter((step) => step.target?.kind === 'element')
			.map((step) => step.target.ref)
			.filter((ref) => !ids.has(ref))

		expect(missing).toEqual([])
	})
})
