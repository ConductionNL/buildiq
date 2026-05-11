// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useRole — pure derivation of the caller's effective role on an Application.
// Per REQ-OBR-008 / REQ-OBRBAC-004 this is the SINGLE source of truth for
// role-keyed UI gating across all OpenBuilt editor surfaces (textarea editor
// today; visual editors from chain specs #5 / #6 when they land).
//
// The user's group list is read from `loadState('openbuilt',
// 'currentUserGroups')` per ADR-004 hard rule (`gate-initial-state`). No DOM
// data-attribute reads.
//
// Returns one of: 'owner' | 'editor' | 'viewer' | 'none'. The role precedence
// is owner > editor > viewer; a caller whose groups intersect multiple
// buckets gets the highest-privilege role.

import { loadState } from '@nextcloud/initial-state'

/**
 * @typedef {Object} ApplicationPermissions
 * @property {string[]} [owners]
 * @property {string[]} [editors]
 * @property {string[]} [viewers]
 */

/**
 * @typedef {Object} Application
 * @property {ApplicationPermissions} [permissions]
 */

/**
 * Resolve the caller's group ID list from Nextcloud initial state.
 *
 * Falls back to an empty array when the state is missing — viewers/editors/
 * owners checks then short-circuit to 'none', so the user sees nothing and
 * the controller's 403 enforces server-side.
 *
 * @return {string[]} The caller's group IDs
 */
export function getCurrentUserGroups() {
	try {
		const groups = loadState('openbuilt', 'currentUserGroups')
		return Array.isArray(groups) ? groups : []
	} catch (e) {
		// loadState throws when the state was not provided server-side
		// (e.g. on an admin-settings page that didn't publish it).
		return []
	}
}

/**
 * Compute the caller's effective role on the given Application.
 *
 * @param {Application | null | undefined} application The Application object
 * @param {string[]}                       [userGroups] Optional explicit group list (defaults to loadState)
 * @return {'owner'|'editor'|'viewer'|'none'} The caller's effective role
 */
export function useRole(application, userGroups) {
	if (!application || typeof application !== 'object') {
		return 'none'
	}
	const groups = Array.isArray(userGroups) ? userGroups : getCurrentUserGroups()
	if (groups.length === 0) {
		return 'none'
	}
	const permissions = application.permissions || {}
	const intersects = (bucket) => Array.isArray(bucket) && bucket.some(g => groups.includes(g))

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
 */
export function hasAnyRole(application, userGroups) {
	return useRole(application, userGroups) !== 'none'
}
