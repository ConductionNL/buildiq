/**
 * Default-strategy rule for the PromoteVersionDialog (spec REQ-OBVP-011).
 *
 * Pure function mirroring the PHP
 * `VersionPromotionService::defaultStrategyFor()` exactly. Returns
 * `migrate-existing-data` when the target IS the Application's
 * `productionVersion`; otherwise `start-with-source-data`. Never returns
 * `empty-start`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @param {object} application Application object — must expose `productionVersion`
 * @param {object} target      Target ApplicationVersion — must expose `id` or `uuid`
 * @return {string}
 */
export function defaultStrategyFor(application, target) {
	const productionUuid = (application && application.productionVersion) || ''
	const targetUuid = (target && (target.id || target.uuid)) || ''

	if (productionUuid !== '' && targetUuid !== '' && productionUuid === targetUuid) {
		return 'migrate-existing-data'
	}

	return 'start-with-source-data'
}
