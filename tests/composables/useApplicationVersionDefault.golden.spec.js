/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `useApplicationVersion(appSlug)` without a version slug:
 * every pairing of a version-list response and an Application-record response
 * (or failure) from two pools. The requests made and the resolved state are
 * pinned.
 *
 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-4
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import axios from '@nextcloud/axios'
import { useApplicationVersion } from '../../src/composables/useApplicationVersion.js'

const CHAIN = [
	{ uuid: 'dev', slug: 'development', promotesTo: 'acc' },
	{ uuid: 'acc', slug: 'acceptance', promotesTo: 'prod' },
	{ uuid: 'prod', slug: 'production' },
]

const ROOTS = [
	{ uuid: 'a', slug: 'one' },
	{ uuid: 'b', slug: 'two' },
]

const VERSION_RESPONSES = [
	{ data: ROOTS },
	{ data: { results: ROOTS } },
	{ data: CHAIN },
	{ data: { results: CHAIN } },
	{ data: { results: [CHAIN[2]] } },
	{ data: [CHAIN[0], CHAIN[1]] },
	{ data: [] },
	{ data: { results: 'none' } },
	{ data: null },
	{ data: 'text' },
	{ fail: { response: { status: 500 } } },
	{ fail: 'boom' },
]

const APP_RESPONSES = [
	{ data: { results: [{ slug: 'demo', productionVersion: 'prod' }] } },
	{ data: { results: [{ slug: 'demo', productionVersion: 'a' }] } },
	{ data: { results: [{ slug: 'demo', productionVersion: { uuid: 'a' } }] } },
	{ data: { results: [{ slug: 'demo', productionVersion: { id: 'a' } }] } },
	{ data: { results: [{ slug: 'demo', productionVersion: {} }] } },
	{ data: { results: [{ slug: 'demo' }] } },
	{ data: { results: [null, { slug: 'other', productionVersion: 'a' }] } },
	{ data: [{ slug: 'demo', productionVersion: 'a' }] },
	{
		data: {
			results: [
				{ slug: 'other', productionVersion: 'a' },
				{ slug: 'demo', productionVersion: 'b' },
			],
		},
	},
	{ data: { results: 'none' } },
	{ data: null },
	{ fail: { response: { status: 404 } } },
]

/**
 * Queue one mocked GET outcome.
 *
 * @param {object} outcome `{ data }` or `{ fail }`.
 *
 * @return {void}
 */
function queue(outcome) {
	if ('fail' in outcome) {
		axios.get.mockRejectedValueOnce(
			outcome.fail instanceof Object
				? Object.assign(new Error('request failed'), outcome.fail)
				: outcome.fail,
		)
	} else {
		axios.get.mockResolvedValueOnce({ data: outcome.data })
	}
}

describe('useApplicationVersion default version golden', () => {
	it('pins the resolved version for every pair of responses', async () => {
		const lines = []
		for (const versions of VERSION_RESPONSES) {
			for (const app of APP_RESPONSES) {
				axios.get.mockReset()
				queue(versions)
				queue(app)
				const state = useApplicationVersion('demo', undefined)
				await state.ready
				const error = state.error.value
				lines.push(
					`${JSON.stringify(versions)} + ${JSON.stringify(app)} => ${JSON.stringify(
						{
							version: state.applicationVersion.value,
							loading: state.loading.value,
							error:
								error === null
									? null
									: `${error.constructor.name}: ${error.message}`,
							calls: axios.get.mock.calls,
						},
					)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
