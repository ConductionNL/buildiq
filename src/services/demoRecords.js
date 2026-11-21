// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * demoRecords — fill an app with demo records generated from its own schemas,
 * and remove them again (capability-requirements-backfill REQ-BQCV-030).
 *
 * The server makes the records, so each validates against its schema, and
 * lists them on the Application as `demoRecords`; removing deletes exactly
 * those, so records a maker created are kept.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The demo records endpoint of an app.
 *
 * @param {string} appUuid The Application's uuid.
 * @return {string} The URL.
 */
function demoRecordsUrl(appUuid) {
	return generateUrl('/apps/buildiq/api/applications/{appUuid}/demo-records', { appUuid })
}

/**
 * Fill the app with demo records.
 *
 * @param {string} appUuid The Application's uuid.
 * @return {Promise<{created: number, skipped: string[], failed: number, total: number}>} The server's answer.
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
export async function fillDemoRecords(appUuid) {
	const { data } = await axios.post(demoRecordsUrl(appUuid))
	return data || {}
}

/**
 * Remove the app's demo records.
 *
 * @param {string} appUuid The Application's uuid.
 * @return {Promise<{removed: number, failed: number}>} The server's answer.
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
export async function removeDemoRecords(appUuid) {
	const { data } = await axios.delete(demoRecordsUrl(appUuid))
	return data || {}
}

/**
 * How many demo records an app lists.
 *
 * @param {object|null} application The Application record.
 * @return {number} The count.
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
export function demoRecordCount(application) {
	const listed = application && application.demoRecords
	return Array.isArray(listed) ? listed.length : 0
}

/**
 * The message after a fill.
 *
 * @param {{created?: number, skipped?: string[]}} result The server's answer.
 * @return {string} The message.
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
export function fillMessage(result) {
	const created = Number(result && result.created) || 0
	const skipped = (result && Array.isArray(result.skipped)) ? result.skipped : []
	const made = n('buildiq', 'Added %n demo record.', 'Added %n demo records.', created)
	if (skipped.length === 0) {
		return made
	}
	return made + ' ' + t('buildiq', 'No demo records could be made for: {schemas}.', { schemas: skipped.join(', ') })
}

/**
 * The message after a removal.
 *
 * @param {{removed?: number, failed?: number}} result The server's answer.
 * @return {string} The message.
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
export function removeMessage(result) {
	const removed = Number(result && result.removed) || 0
	const failed = Number(result && result.failed) || 0
	const done = n('buildiq', 'Removed %n demo record.', 'Removed %n demo records.', removed)
	if (failed === 0) {
		return done
	}
	return done + ' ' + n('buildiq', '%n could not be removed.', '%n could not be removed.', failed)
}
