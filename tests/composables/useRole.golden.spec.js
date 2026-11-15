/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `useRole` over generated Applications, group lists and
 * signed-in users. The role of every case is pinned.
 *
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-2
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['staff'],
}))

import { useRole } from '../../src/composables/useRole.js'

const PRINCIPALS = [
	'admin',
	'user:ann',
	'ann',
	'group:staff',
	'staff',
	'user:bob',
	'',
]

const GROUP_LISTS = [
	'(loadState)',
	[],
	['admin'],
	['staff', 'x'],
	['user:ann'],
	'admin',
]

const USERS = [null, { uid: 'ann' }, { uid: '' }, 'bob']

/**
 * A small deterministic PRNG (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns floats in [0, 1).
 */
function mulberry32(seed) {
	let a = seed
	return () => {
		a = (a + 0x6d2b79f5) | 0
		let t = Math.imul(a ^ (a >>> 15), 1 | a)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one element of a list.
 *
 * @param {function(): number} rand The PRNG.
 * @param {Array} list The list.
 *
 * @return {*} The element.
 */
function pick(rand, list) {
	return list[Math.floor(rand() * list.length)]
}

/**
 * A bucket of principals, or a non-list now and then.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The bucket.
 */
function bucketFrom(rand) {
	const roll = rand()
	if (roll < 0.15) {
		return pick(rand, [undefined, null, 'admin', {}])
	}
	return Array.from({ length: Math.floor(rand() * 3) }, () =>
		pick(rand, PRINCIPALS),
	)
}

/**
 * Sign a user in the way `getCurrentUid` reads it.
 *
 * @param {*} user One entry of USERS.
 *
 * @return {void}
 */
function signIn(user) {
	if (user === null) {
		delete window.OC
	} else if (typeof user === 'string') {
		window.OC = { currentUser: user }
	} else {
		window.OC = { getCurrentUser: () => user }
	}
}

describe('useRole golden', () => {
	afterEach(() => {
		delete window.OC
	})

	it('pins the role of generated applications', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const application =
				rand() < 0.05
					? pick(rand, [null, undefined, 'app'])
					: {
							permissions:
								rand() < 0.1
									? pick(rand, [undefined, null, 'all'])
									: {
											owners: bucketFrom(rand),
											editors: bucketFrom(rand),
											viewers: bucketFrom(rand),
										},
						}
			const groups = pick(rand, GROUP_LISTS)
			const user = pick(rand, USERS)
			signIn(user)
			const role =
				groups === '(loadState)'
					? useRole(application)
					: useRole(application, groups)
			lines.push(
				`${JSON.stringify(application)} ${JSON.stringify(groups)} ${JSON.stringify(user)} => ${role}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
