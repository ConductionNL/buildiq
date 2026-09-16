/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests for `src/composables/useRole.js` — the single source
 * of truth for role-keyed UI gating across all Buildiq editor surfaces
 * (REQ-OBR-008 / REQ-OBRBAC-004). Covered scenarios:
 *
 *   - getCurrentUserGroups: success path + loadState-throws fallback
 *   - useRole: owner > editor > viewer precedence
 *   - useRole: returns 'none' when the user has no intersecting group
 *   - useRole: 'none' on null/undefined/empty application
 *   - useRole: explicit userGroups argument overrides loadState
 *   - hasAnyRole: boolean wrapper for the empty-state check in
 *     ApplicationEditor.vue's list view (REQ-OBRBAC-003)
 *
 * The `@nextcloud/initial-state` module is the only injection point —
 * mocking `loadState` covers every code path without bringing up a DOM.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

// Mocks for @nextcloud/initial-state are hoisted by Vitest above the
// imports below. We re-import a fresh module-scope mock between tests
// via vi.resetModules + dynamic import for the loadState-throws case.
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(),
}))

import { loadState } from '@nextcloud/initial-state'
import {
	getBuilderGroups,
	getCurrentUserGroups,
	hasAnyRole,
	useRole,
} from '../../../src/composables/useRole.js'

/**
 * Answer loadState per key: the caller's groups for `currentUserGroups`, and
 * the builder groups (none unless given) for `builderGroups`.
 *
 * @param {unknown} groups The caller's groups.
 * @param {string[]} [builderGroups] The configured builder groups.
 */
function givenGroups(groups, builderGroups = []) {
	loadState.mockImplementation((_app, key) =>
		key === 'builderGroups' ? builderGroups : groups,
	)
}

describe('useRole — REQ-OBR-008 / REQ-OBRBAC-004', () => {
	beforeEach(() => {
		loadState.mockReset()
	})

	describe('getCurrentUserGroups', () => {
		it('returns the loadState array when set', () => {
			givenGroups(['team-alpha', 'team-beta'])
			expect(getCurrentUserGroups()).toEqual(['team-alpha', 'team-beta'])
			expect(loadState).toHaveBeenCalledWith('buildiq', 'currentUserGroups')
		})

		it('returns [] when loadState returns a non-array', () => {
			givenGroups(null)
			expect(getCurrentUserGroups()).toEqual([])
		})

		it('returns [] when loadState throws (state not provided server-side)', () => {
			loadState.mockImplementation(() => {
				throw new Error('state not provided')
			})
			expect(getCurrentUserGroups()).toEqual([])
		})
	})

	describe('useRole — happy paths (REQ-OBR-008)', () => {
		it("returns 'owner' when user is in the owners array", () => {
			givenGroups(['team-alpha'])
			const app = {
				permissions: { owners: ['team-alpha'], editors: [], viewers: [] },
			}
			expect(useRole(app)).toBe('owner')
		})

		it("returns 'editor' when user is in the editors array only", () => {
			givenGroups(['team-beta'])
			const app = {
				permissions: {
					owners: ['team-alpha'],
					editors: ['team-beta'],
					viewers: [],
				},
			}
			expect(useRole(app)).toBe('editor')
		})

		it("returns 'viewer' when user is in the viewers array only", () => {
			givenGroups(['team-gamma'])
			const app = {
				permissions: {
					owners: ['team-alpha'],
					editors: ['team-beta'],
					viewers: ['team-gamma'],
				},
			}
			expect(useRole(app)).toBe('viewer')
		})
	})

	describe('useRole — precedence (owner > editor > viewer)', () => {
		it("returns 'owner' when the user is in BOTH owners and editors", () => {
			givenGroups(['team-alpha'])
			const app = {
				permissions: {
					owners: ['team-alpha'],
					editors: ['team-alpha'],
					viewers: ['team-alpha'],
				},
			}
			expect(useRole(app)).toBe('owner')
		})

		it("returns 'editor' when the user is in editors AND viewers (but not owners)", () => {
			givenGroups(['team-beta'])
			const app = {
				permissions: {
					owners: ['team-alpha'],
					editors: ['team-beta'],
					viewers: ['team-beta'],
				},
			}
			expect(useRole(app)).toBe('editor')
		})
	})

	describe('useRole — denial paths', () => {
		it("returns 'none' when the user has no intersecting group", () => {
			givenGroups(['team-outsider'])
			const app = {
				permissions: {
					owners: ['team-alpha'],
					editors: ['team-beta'],
					viewers: ['team-gamma'],
				},
			}
			expect(useRole(app)).toBe('none')
		})

		it("returns 'none' when the user has zero groups", () => {
			givenGroups([])
			const app = { permissions: { owners: ['team-alpha'] } }
			expect(useRole(app)).toBe('none')
		})

		it("returns 'none' on null application", () => {
			givenGroups(['team-alpha'])
			expect(useRole(null)).toBe('none')
		})

		it("returns 'none' on undefined application", () => {
			givenGroups(['team-alpha'])
			expect(useRole(undefined)).toBe('none')
		})

		it("returns 'none' when the application has no permissions block", () => {
			givenGroups(['team-alpha'])
			expect(useRole({ name: 'no-perms-app' })).toBe('none')
		})

		it("returns 'none' when all permission buckets are empty arrays", () => {
			givenGroups(['team-alpha'])
			const app = { permissions: { owners: [], editors: [], viewers: [] } }
			expect(useRole(app)).toBe('none')
		})
	})

	describe('useRole — explicit userGroups argument', () => {
		it('honours the explicit argument and ignores loadState', () => {
			givenGroups(['team-outsider'])
			const app = { permissions: { owners: ['team-alpha'] } }
			expect(useRole(app, ['team-alpha'])).toBe('owner')
			// loadState should NOT have been consulted when an explicit
			// list is passed (the composable is a pure function in that
			// mode — important for component tests that need full
			// determinism).
			expect(loadState).not.toHaveBeenCalled()
		})

		it('falls back to loadState when explicit arg is not an array', () => {
			givenGroups(['team-alpha'])
			const app = { permissions: { owners: ['team-alpha'] } }
			// Pass a non-array — composable should ignore it and consult
			// loadState instead.
			expect(useRole(app, 'team-alpha')).toBe('owner')
			expect(loadState).toHaveBeenCalledWith('buildiq', 'currentUserGroups')
		})
	})

	describe('hasAnyRole — REQ-OBRBAC-003 list filter helper', () => {
		it('returns true when the user has ANY of the three roles', () => {
			givenGroups(['team-gamma'])
			const app = { permissions: { viewers: ['team-gamma'] } }
			expect(hasAnyRole(app)).toBe(true)
		})

		it('returns false when the user has none', () => {
			givenGroups(['team-outsider'])
			const app = { permissions: { owners: ['team-alpha'] } }
			expect(hasAnyRole(app)).toBe(false)
		})

		it('returns false on null application (defensive)', () => {
			givenGroups(['team-alpha'])
			expect(hasAnyRole(null)).toBe(false)
		})
	})

	describe('builder groups — REQ-OBRBAC-008', () => {
		const app = {
			permissions: {
				owners: ['user:alice'],
				editors: [],
				viewers: ['team-readers'],
			},
		}

		it('makes a builder-group member an editor of an app where they hold no role', () => {
			givenGroups(['buildiq-builders'], ['buildiq-builders'])
			expect(useRole(app)).toBe('editor')
		})

		it('lifts a viewer who is also a builder to editor', () => {
			givenGroups(['team-readers', 'buildiq-builders'], ['buildiq-builders'])
			expect(useRole(app)).toBe('editor')
		})

		it('grants nothing when the caller is in no builder group', () => {
			givenGroups(['team-outsider'], ['buildiq-builders'])
			expect(useRole(app)).toBe('none')
		})

		it('grants nothing when no builder groups are configured', () => {
			givenGroups(['buildiq-builders'], [])
			expect(useRole(app)).toBe('none')
		})

		it('keeps an app with no permission block closed, as the server does', () => {
			givenGroups(['buildiq-builders'], ['buildiq-builders'])
			expect(useRole({})).toBe('none')
		})

		it('reads the builder groups from the builderGroups initial state', () => {
			givenGroups(['x'], ['buildiq-builders'])
			expect(getBuilderGroups()).toEqual(['buildiq-builders'])
			expect(loadState).toHaveBeenCalledWith('buildiq', 'builderGroups')
		})
	})
})
