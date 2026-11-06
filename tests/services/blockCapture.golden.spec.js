/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the block capture over a grid of fragments, app slugs and
 * metadata: the record and summary of every capture, or the collision it
 * refuses. Also pins the schema references collected and the rewrite of
 * fragments under maps that hold unusual keys.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
import { describe, expect, it } from 'vitest'
import { captureBlock, collectSchemaRefs } from '../../src/services/blockCapture.js'
import { rewriteSchemaRefs } from '../../src/services/templateCapture.js'

const FRAGMENTS = [
	{ id: 'w1', type: 'table', config: { schema: 'demo-case', columns: ['title'] } },
	{
		id: 'sec',
		widgets: [
			{ config: { schema: 'demo-task', relatedSchema: 'demo-case' } },
			{
				config: {
					schema: 'person',
					items: [{ relatedSchema: 'demo-task' }, { schema: '' }],
				},
			},
			{ config: { schema: 7, relatedSchema: null } },
		],
	},
	{
		widgets: [
			{ config: { schema: 'demo-case' } },
			{ config: { schema: 'case' } },
		],
	},
	{ config: { other: 'demo-case', nested: [[{ schema: 'shared-x' }]] } },
	[{ schema: 'demo-a' }, 'text', 3, null],
	{},
	null,
	undefined,
]

const APP_SLUGS = ['demo', '', undefined]

const METADATA = [
	{
		slug: 'blk',
		name: 'Block',
		description: 'D',
		category: 'layout',
		createdBy: 'alice',
	},
	{ slug: 'bare', name: 'Bare' },
	{ slug: 'e', name: 'E', description: '', category: '', createdBy: '' },
]

const MAPS = [
	{},
	{ '': 'empty', 'demo-case': 'case' },
	{ 'demo-case': 'case', 'demo-task': 'task', 7: 'seven' },
]

/**
 * The capture, or the refusal it throws.
 *
 * @param {Array} args The captureBlock arguments.
 *
 * @return {object} The result or the error's fields.
 */
function attempt(args) {
	try {
		return captureBlock(...args)
	} catch (e) {
		return {
			error: e.name,
			code: e.code,
			message: e.message,
			canonical: e.canonicalSlug,
			sources: e.sourceSlugs,
		}
	}
}

describe('blockCapture golden', () => {
	it('captures every grid cell as recorded', () => {
		const out = []
		for (const fragment of FRAGMENTS) {
			for (const appSlug of APP_SLUGS) {
				for (const metadata of METADATA) {
					const before = JSON.stringify(fragment)
					out.push(attempt([fragment, appSlug, metadata]))
					expect(JSON.stringify(fragment)).toBe(before)
				}
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('collects the schema references of every fragment as recorded', () => {
		expect(FRAGMENTS.map((f) => [...collectSchemaRefs(f)])).toMatchSnapshot()
	})

	it('rewrites every fragment under every map as recorded', () => {
		const out = []
		for (const fragment of FRAGMENTS) {
			for (const map of MAPS) {
				out.push(rewriteSchemaRefs(fragment, map))
			}
		}
		out.push(
			rewriteSchemaRefs({ schema: '', relatedSchema: '' }, { '': 'empty' }),
		)
		expect(out).toMatchSnapshot()
	})
})
