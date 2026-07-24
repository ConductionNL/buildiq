/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/services/blockCapture.js`.
 *
 * Covers the MANDATORY capture/de-namespace logic (tasks.md 7.1):
 *   - collectSchemaRefs discovers every `schema`/`relatedSchema` reference
 *     in an arbitrary widget or section fragment.
 *   - captureBlock de-namespaces those references exactly as
 *     save-as-template's captureTemplate does for companion schemas.
 *   - "Saving a widget captures its config, not its data" — no object rows
 *     ever leak into a captured block.
 */

import { describe, it, expect } from 'vitest'
import {
	BLOCK_CATEGORIES,
	captureBlock,
	collectSchemaRefs,
	buildSectionFragment,
	isSectionFragment,
	SlugCollisionError,
} from '../../src/services/blockCapture.js'

const widgetFragment = {
	id: 'status-badge-1',
	widgetKey: 'status-badge',
	slot: 'body',
	gridX: 0,
	gridY: 0,
	gridWidth: 4,
	gridHeight: 2,
	props: { colorMap: { submitted: 'info' } },
	dataSource: { register: 'vergunning-app', schema: 'vergunning-app-permit-application' },
}

describe('blockCapture — collectSchemaRefs', () => {
	it('finds schema/relatedSchema references anywhere in the fragment', () => {
		const refs = collectSchemaRefs(widgetFragment)
		expect([...refs]).toEqual(['vergunning-app-permit-application'])
	})

	it('collects distinct references across a section fragment', () => {
		const section = buildSectionFragment('section-1', [
			{ ...widgetFragment, id: 'a' },
			{ id: 'b', widgetKey: 'field-display', props: { relatedSchema: 'vergunning-app-applicant' } },
		])
		const refs = [...collectSchemaRefs(section)]
		expect(refs).toEqual(expect.arrayContaining([
			'vergunning-app-permit-application',
			'vergunning-app-applicant',
		]))
		expect(refs).toHaveLength(2)
	})

	it('returns an empty set for a fragment with no schema references', () => {
		expect([...collectSchemaRefs({ id: 'x', widgetKey: 'clock' })]).toEqual([])
	})
})

describe('blockCapture — captureBlock (single widget)', () => {
	const meta = { slug: 'status-badge-widget', name: 'Status badge', description: 'A badge', category: 'display' }

	it('de-namespaces schema references and records schemaDependencies', () => {
		const { record, summary } = captureBlock(widgetFragment, 'vergunning-app', meta)

		expect(record.slug).toBe('status-badge-widget')
		expect(record.sourceApplicationSlug).toBe('vergunning-app')
		expect(record.schemaDependencies).toEqual(['permit-application'])
		expect(record.fragment.dataSource.schema).toBe('permit-application')
		expect(summary.schemaDependencies[0]).toMatchObject({
			sourceSlug: 'vergunning-app-permit-application',
			slug: 'permit-application',
			shared: false,
		})
	})

	it('never captures object rows — only structure', () => {
		const withRows = { ...widgetFragment, __rows: [{ id: 1 }, { id: 2 }] }
		const { record } = captureBlock(withRows, 'vergunning-app', meta)
		// The fragment is captured verbatim (structure only); the function
		// itself never fetches or synthesises any row/object data.
		expect(record).not.toHaveProperty('objects')
		expect(record).not.toHaveProperty('data')
		expect(JSON.stringify(record)).not.toContain('"rows"')
	})

	it('does not mutate the source fragment', () => {
		const copy = JSON.parse(JSON.stringify(widgetFragment))
		captureBlock(widgetFragment, 'vergunning-app', meta)
		expect(widgetFragment).toEqual(copy)
	})

	it('flags an unprefixed shared schema and captures its slug unchanged', () => {
		const fragment = { id: 'w', widgetKey: 'x', dataSource: { schema: 'shared-contacts' } }
		const { record, summary } = captureBlock(fragment, 'vergunning-app', meta)
		expect(summary.schemaDependencies[0]).toMatchObject({ slug: 'shared-contacts', shared: true })
		expect(record.schemaDependencies).toEqual(['shared-contacts'])
	})

	it('throws a typed SlugCollisionError naming both schemas on a de-namespace collision', () => {
		const section = buildSectionFragment('s', [
			{ id: 'a', widgetKey: 'x', dataSource: { schema: 'vergunning-app-tasks' } },
			{ id: 'b', widgetKey: 'y', dataSource: { relatedSchema: 'tasks' } },
		])
		expect(() => captureBlock(section, 'vergunning-app', meta)).toThrow(SlugCollisionError)
		try {
			captureBlock(section, 'vergunning-app', meta)
		} catch (e) {
			expect(e.code).toBe('slug-collision')
			expect(e.sourceSlugs).toEqual(expect.arrayContaining(['vergunning-app-tasks', 'tasks']))
		}
	})
})

describe('blockCapture — captureBlock (page section)', () => {
	it('captures every selected widget, preserving their relative order', () => {
		const section = buildSectionFragment('applicant-summary', [
			{ id: 'name', widgetKey: 'field-display', props: { schema: 'vergunning-app-applicant' } },
			{ id: 'status', widgetKey: 'status-badge', props: { schema: 'vergunning-app-applicant' } },
		])
		const meta = { slug: 'applicant-summary-section', name: 'Applicant summary', category: 'layout' }
		const { record } = captureBlock(section, 'vergunning-app', meta)

		expect(isSectionFragment(record.fragment)).toBe(true)
		expect(record.fragment.widgets).toHaveLength(2)
		expect(record.fragment.widgets.map((w) => w.id)).toEqual(['name', 'status'])
		expect(record.fragment.widgets[0].props.schema).toBe('applicant')
	})
})

describe('blockCapture — helpers', () => {
	it('exports the suggested (non-enforced) category list', () => {
		expect(BLOCK_CATEGORIES).toEqual(
			expect.arrayContaining(['display', 'layout', 'form', 'navigation', 'data']),
		)
	})

	it('isSectionFragment discriminates a section wrapper from a single widget', () => {
		expect(isSectionFragment(widgetFragment)).toBe(false)
		expect(isSectionFragment(buildSectionFragment('s', []))).toBe(true)
		expect(isSectionFragment(null)).toBe(false)
	})
})
