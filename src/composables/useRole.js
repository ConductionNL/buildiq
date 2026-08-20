// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useRole — pure derivation of the caller's effective role on an Application.
// Per REQ-OBR-008 / REQ-OBRBAC-004 this is the SINGLE source of truth for
// role-keyed UI gating across all OpenBuild editor surfaces (textarea editor
// today; visual editors from chain specs #5 / #6 when they land).
//
// The user's group list is read from `loadState('openbuild',
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
		const groups = loadState('openbuild', 'currentUserGroups')
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
	// Match by group GID OR by the caller's own user principal. Permission
	// buckets carry `user:<uid>`, `group:<gid>`, or a bare GID — the same
	// grammar the backend PermissionResolver enforces. The previous group-only
	// check silently denied a user-principal owner (e.g. owners: ['user:admin'])
	// every role-gated action.
	const intersects = (bucket) =>
		Array.isArray(bucket)
		&& bucket.some(
			(p) =>
				groups.includes(p)
				|| (uid !== '' && (p === `user:${uid}` || p === uid)),
		)

	if (intersects(permissions.owners)) {
		return 'owner'
	}
	if (intersects(permissions.editors)) {
		return 'editor'
	}
	if (intersects(permissions.viewers)) {
		return 'viewer'
	}
	return 'none'
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
