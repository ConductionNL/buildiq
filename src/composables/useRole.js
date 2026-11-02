// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useRole — pure derivation of the caller's effective role on an Application.
// Per REQ-OBR-008 / REQ-OBRBAC-004 this is the SINGLE source of truth for
// role-keyed UI gating across all Buildiq editor surfaces (textarea editor
// today; visual editors from chain specs #5 / #6 when they land).
//
// The user's group list is read from `loadState('buildiq',
// 'currentUserGroups')` per ADR-004 hard rule (`gate-initial-state`). No DOM
// data-attribute reads.
//
// Returns one of: 'owner' | 'editor' | 'viewer' | 'none'. The role precedence
// is owner > editor > viewer; a caller whose groups intersect multiple
// buckets gets the highest-privilege role.

import { loadState } from '@nextcloud/initial-state'

/**
 * @typedef {object} ApplicationPermissions
 * @property {string[]} [owners] - Group IDs with owner role (full control)
 * @property {string[]} [editors] - Group IDs with editor role (save drafts)
 * @property {string[]} [viewers] - Group IDs with viewer role (read-only)
 */

/**
 * @typedef {object} Application
 * @property {ApplicationPermissions} [permissions] - Per-Application permission block
 */

/**
 * Resolve the caller's group ID list from Nextcloud initial state.
 *
 * Falls back to an empty array when the state is missing — viewers/editors/
 * owners checks then short-circuit to 'none', so the user sees nothing and
 * the controller's 403 enforces server-side.
 *
 * @return {string[]} The caller's group IDs
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-2
 */
export function getCurrentUserGroups() {
	try {
		const groups = loadState('buildiq', 'currentUserGroups')
		return Array.isArray(groups) ? groups : []
	} catch (e) {
		// loadState throws when the state was not provided server-side
		// (e.g. on an admin-settings page that didn't publish it).
		return []
	}
}

/**
 * Resolve the current Nextcloud user id from the global OC object.
 *
 * @return {string} The caller's uid, or '' when not signed in / unavailable.
 */
export function getCurrentUid() {
	try {
		if (typeof window !== 'undefined' && window.OC) {
			if (typeof window.OC.getCurrentUser === 'function') {
				const u = window.OC.getCurrentUser()
				if (u && u.uid) {
					return String(u.uid)
				}
			}
			if (window.OC.currentUser) {
				return String(window.OC.currentUser)
			}
		}
	} catch (e) {
		// Defensive: never throw from a pure role derivation.
	}
	return ''
}

/** The permission buckets, strongest first, with the role each grants. */
const ROLE_BUCKETS = Object.freeze([
	['owners', 'owner'],
	['editors', 'editor'],
	['viewers', 'viewer'],
])

/**
 * A predicate that tells whether a permission principal is the caller.
 *
 * Matches by group GID OR by the caller's own user principal. Permission
 * buckets carry `user:<uid>`, `group:<gid>`, or a bare GID — the same grammar
 * the backend PermissionResolver enforces. A group-only check silently denies
 * a user-principal owner (e.g. owners: ['user:admin']) every role-gated action.
 *
 * @param {{groups: string[], uid: string}} caller The caller's groups and uid.
 * @return {function(string): boolean} The predicate.
 */
function principalMatcher(caller) {
	return (p) =>
		caller.groups.includes(p)
		|| (caller.uid !== '' && (p === `user:${caller.uid}` || p === caller.uid))
}

/**
 * Whether a permission bucket names the caller.
 *
 * @param {unknown} bucket A permission bucket (a list of principals).
 * @param {function(string): boolean} holds Tells whether a principal is the caller.
 * @return {boolean}
 */
function holdsAny(bucket, holds) {
	return Array.isArray(bucket) && bucket.some(holds)
}

/**
 * Compute the caller's effective role on the given Application.
 *
 * @param {Application | null | undefined} application The Application object
 * @param {string[]}                       [userGroups] Optional explicit group list (defaults to loadState)
 * @return {'owner'|'editor'|'viewer'|'none'} The caller's effective role
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-2
 */
export function useRole(application, userGroups) {
	if (!application || typeof application !== 'object') {
		return 'none'
	}
	const groups = Array.isArray(userGroups) ? userGroups : getCurrentUserGroups()
	const uid = getCurrentUid()
	if (groups.length === 0 && uid === '') {
		return 'none'
	}
	const permissions = application.permissions || {}
	const holds = principalMatcher({ groups, uid })
	const role = ROLE_BUCKETS.find(([bucket]) =>
		holdsAny(permissions[bucket], holds),
	)
	return role ? role[1] : 'none'
}

/**
 * Convenience helper — true when the caller has any role on the Application
 * (i.e. the Application should appear in their list per REQ-OBR-007).
 *
 * @param {Application | null | undefined} application The Application object
 * @param {string[]}                       [userGroups] Optional explicit group list
 * @return {boolean} True when the caller has owner/editor/viewer
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-2
 */
export function hasAnyRole(application, userGroups) {
	return useRole(application, userGroups) !== 'none'
}
