/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `provisionPortalPage` over first saves and repeat saves:
 * every sign-in level, stored pages with and without matching collections
 * and create actions, stored page-level levels, every response envelope and
 * every error. The requests sent (method, url, payload) and the result or
 * error of each case are pinned.
 *
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import { provisionPortalPage } from '../../src/services/externalFormProvisioningService.js'

const LEVELS = [undefined, null, 'low', 'substantial', 'high', 0, 'bogus']

const STORED = {
	none: null,
	empty: {},
	'other entries': {
		label: 'Kept',
		pages: [{ id: 'p' }],
		collections: [{ register: 'r', schema: 'other', anonymous: true }, null],
		actions: [
			{ type: 'update', register: 'r', schema: 's' },
			null,
			{ type: 'create', register: 'x', schema: 's' },
		],
	},
	'matching entries with a level': {
		minTrust: 'high',
		collections: [
			{ register: 'r', schema: 's', minTrust: 'substantial', extra: 1 },
		],
		actions: [
			{
				type: 'create',
				register: 'r',
				schema: 's',
				minTrust: 'substantial',
				anonymous: true,
			},
		],
	},
	'matching anonymous entries, page level 0': {
		minTrust: 0,
		status: 'draft',
		collections: [{ register: 'r', schema: 's', anonymous: true }],
		actions: [{ type: 'create', register: 'r', schema: 's', minTrust: 0 }],
	},
	'non-array lists, undefined page level': {
		minTrust: undefined,
		collections: 'x',
		actions: { 0: 'y' },
	},
}

const RESPONSES = {
	'@self id': { '@self': { id: 'self-1' }, id: 'id-1', uuid: 'uuid-1' },
	id: { id: 'id-2', uuid: 'uuid-2' },
	uuid: { uuid: 'uuid-3' },
	'empty @self': { '@self': {}, uuid: 'uuid-4' },
	empty: {},
	null: null,
}

const ERRORS = {
	404: { response: { status: 404 } },
	500: { response: { status: 500 } },
	'no response': { message: 'network' },
	null: null,
}

/**
 * An axios-like client that logs every request and answers from a script.
 *
 * @param {object} script - `{ current, response, failOn, error }`.
 * @return {{client: object, log: Array<string>}}
 */
function scriptedClient(script) {
	const log = []
	const answer = (method, url, body) => {
		const keys =
			body === undefined
				? ''
				: ` keys=${Object.keys(body).join(',')} ${JSON.stringify(body)}`
		log.push(`${method} ${url}${keys}`)
		if (script.failOn === method) {
			return Promise.reject(script.error)
		}
		return Promise.resolve({
			data: method === 'get' ? script.current : script.response,
		})
	}
	return {
		log,
		client: {
			get: (url) => answer('get', url),
			put: (url, body) => answer('put', url, body),
			post: (url, body) => answer('post', url, body),
		},
	}
}

/**
 * Run one case and describe the requests and the outcome.
 *
 * @param {object} opts - provisionPortalPage options.
 * @param {object} script - the client script.
 * @return {Promise<string>}
 */
async function run(opts, script) {
	const { client, log } = scriptedClient(script)
	let outcome
	try {
		outcome = 'ok ' + JSON.stringify(await provisionPortalPage(opts, client))
	} catch (err) {
		outcome = 'err ' + JSON.stringify(err)
	}
	return `${JSON.stringify(opts)} => ${log.join(' ; ')} => ${outcome}`
}

describe('provisionPortalPage golden', () => {
	it('pins first saves', async () => {
		const lines = []
		for (const objectId of [undefined, null, '']) {
			for (const minTrust of LEVELS) {
				for (const response of Object.values(RESPONSES)) {
					lines.push(
						await run(
							{ register: 'r', schema: 's', objectId, minTrust },
							{ response },
						),
					)
				}
			}
		}
		lines.push(
			await run({ register: 'r', schema: 's' }, { response: { id: 1 } }),
		)
		lines.push(
			await run(
				{ register: 'a b', schema: 'c/d', minTrust: 'low' },
				{ response: { id: 1 } },
			),
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins repeat saves', async () => {
		const lines = []
		for (const [name, current] of Object.entries(STORED)) {
			for (const minTrust of LEVELS) {
				lines.push(`[${name}]`)
				lines.push(
					await run(
						{ register: 'r', schema: 's', objectId: 'obj 1', minTrust },
						{ current, response: RESPONSES.id },
					),
				)
			}
			for (const [rname, response] of Object.entries(RESPONSES)) {
				lines.push(`[${name} / ${rname}]`)
				lines.push(
					await run(
						{ register: 'r', schema: 's', objectId: 'obj-2' },
						{ current, response },
					),
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins errors', async () => {
		const lines = []
		for (const [ename, error] of Object.entries(ERRORS)) {
			for (const failOn of ['get', 'put', 'post']) {
				for (const objectId of [undefined, 'obj-3']) {
					lines.push(`[${ename} on ${failOn}]`)
					lines.push(
						await run(
							{
								register: 'r',
								schema: 's',
								objectId,
								minTrust: 'low',
							},
							{ current: {}, response: { id: 'x' }, failOn, error },
						),
					)
				}
			}
		}
		lines.push('[no options]')
		lines.push(
			await provisionPortalPage(undefined, scriptedClient({}).client).then(
				() => 'ok',
				(err) => `err ${err.constructor.name}`,
			),
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
