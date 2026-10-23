/**
 * Shared state for the version promotion dialog on the app detail page.
 *
 * The dialog is mounted once, by ApplicationDetailHeader. The version pills,
 * the Actions menu and the Version history tab all open it through
 * `openPromoteDialog()`, so there is one dialog and one promote request no
 * matter where the user starts. `promotedAt` changes after every successful
 * promotion, so the other parts of the page can reload what they show.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/version-promotion/spec.md
 */

import { reactive } from 'vue'

export const promoteDialog = reactive({
	open: false,
	sourceVersion: null,
	application: null,
	promotedAt: 0,
})

/**
 * Open the promotion dialog for a version.
 *
 * @param {object} payload What to promote.
 * @param {object} payload.sourceVersion The version to promote (carries `promotesTo`).
 * @param {object} [payload.application] The Application the version belongs to.
 * @return {void}
 *
 * @spec openspec/specs/version-promotion/spec.md
 */
export function openPromoteDialog({ sourceVersion, application = null }) {
	if (!sourceVersion) {
		return
	}
	promoteDialog.sourceVersion = sourceVersion
	promoteDialog.application = application
	promoteDialog.open = true
}

/**
 * Close the promotion dialog.
 *
 * @return {void}
 *
 * @spec openspec/specs/version-promotion/spec.md
 */
export function closePromoteDialog() {
	promoteDialog.open = false
	promoteDialog.sourceVersion = null
	promoteDialog.application = null
}

/**
 * Record a successful promotion so listeners reload.
 *
 * @return {void}
 *
 * @spec openspec/specs/version-promotion/spec.md
 */
export function markPromoted() {
	promoteDialog.promotedAt = Date.now()
}

/**
 * The own UUID of a version row, whatever shape the endpoint returned.
 *
 * @param {object} version The version row.
 * @return {string} The UUID, or ''.
 *
 * @spec openspec/specs/version-promotion/spec.md
 */
export function versionUuidOf(version) {
	if (!version) {
		return ''
	}
	const self = version['@self'] || {}
	return version.uuid || version.id || self.id || self.uuid || ''
}
