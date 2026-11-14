/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Golden for the caller half of `src/composables/useRole.js`: which uid
 * `getCurrentUid` reads off every shape of the global OC object, and the
 * role (allowed) or 'none' (refused) a user principal earns from it.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(),
}))

import { loadState } from '@nextcloud/initial-state'
import { getCurrentUid, useRole } from '../../../src/composables/useRole.js'

const OC_SHAPES = {
	'no OC': undefined,
	'null OC': null,
	'getCurrentUser with uid': { getCurrentUser: () => ({ uid: 'alice' }) },
	'getCurrentUser with numeric uid': { getCurrentUser: () => ({ uid: 42 }) },
	'getCurrentUser without uid, currentUser set': {
		getCurrentUser: () => ({ uid: '' }),
		currentUser: 'bob',
	},
	'getCurrentUser returns null': { getCurrentUser: () => null },
	'getCurrentUser not a function': { getCurrentUser: 'x', currentUser: 'carol' },
	'only currentUser': { currentUser: 'dave' },
	'getCurrentUser throws': {
		getCurrentUser: () => {
			throw new Error('boom')
		},
		currentUser: 'erin',
	},
	'empty OC': {},
}

const APPLICATION = {
	permissions: {
		owners: ['user:alice'],
		editors: ['42'],
		viewers: ['bob', 'group:x'],
	},
}

describe('useRole caller', () => {
	beforeEach(() => {
		loadState.mockReset()
		loadState.mockReturnValue([])
	})

	afterEach(() => {
		delete window.OC
	})

	it('pins the uid and the role for every OC shape', () => {
		const lines = []
		for (const [label, oc] of Object.entries(OC_SHAPES)) {
			if (oc === undefined) {
				delete window.OC
			} else {
				window.OC = oc
			}
			lines.push(`${label}: uid=${JSON.stringify(getCurrentUid())} role=${useRole(APPLICATION)}`)
		}
		expect(lines).toEqual([
			'no OC: uid="" role=none',
			'null OC: uid="" role=none',
			'getCurrentUser with uid: uid="alice" role=owner',
			'getCurrentUser with numeric uid: uid="42" role=editor',
			'getCurrentUser without uid, currentUser set: uid="bob" role=viewer',
			'getCurrentUser returns null: uid="" role=none',
			'getCurrentUser not a function: uid="carol" role=none',
			'only currentUser: uid="dave" role=none',
			'getCurrentUser throws: uid="" role=none',
			'empty OC: uid="" role=none',
		])
	})

	it('refuses a caller with neither groups nor uid, and allows by group alone', () => {
		delete window.OC
		expect(useRole(APPLICATION, [])).toBe('none')
		expect(useRole(APPLICATION, ['group:x'])).toBe('viewer')
		expect(useRole({ permissions: { owners: 'user:alice' } }, ['user:alice'])).toBe('none')
		expect(useRole('app', ['x'])).toBe('none')
	})
})
