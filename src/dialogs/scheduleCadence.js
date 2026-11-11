/**
 * The cadence picker shared by the dialogs that author a schedule (the
 * automation editor's schedule trigger and the schedules editor): the four
 * interval presets, the six cadence options and the lookups between them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The interval presets, in seconds. */
export const INTERVAL_PRESETS = Object.freeze([
	{ id: 'hourly', interval: 3600 },
	{ id: 'daily', interval: 86400 },
	{ id: 'weekly', interval: 604800 },
	{ id: 'monthly', interval: 2592000 },
])

/**
 * The cadence picker options: the presets plus the two custom escape hatches.
 *
 * @return {Array<{id: string, label: string}>} the options.
 */
export function cadenceOptions() {
	return [
		{ id: 'hourly', label: t('buildiq', 'Hourly') },
		{ id: 'daily', label: t('buildiq', 'Daily') },
		{ id: 'weekly', label: t('buildiq', 'Weekly') },
		{ id: 'monthly', label: t('buildiq', 'Monthly') },
		{ id: 'custom-cron', label: t('buildiq', 'Custom (cron)') },
		{
			id: 'custom-interval',
			label: t('buildiq', 'Custom interval (seconds)'),
		},
	]
}

/**
 * @param {Array<{id: string}>} options - the picker options.
 * @param {string} id - a cadence id.
 * @return {object|undefined} the option with that id.
 */
export function cadenceById(options, id) {
	return options.find((o) => o.id === id)
}

/**
 * @param {number|undefined} interval - an interval in seconds.
 * @return {object|undefined} the preset of exactly that interval.
 */
export function presetForInterval(interval) {
	return INTERVAL_PRESETS.find((p) => p.interval === interval)
}

/**
 * @param {string|null|undefined} id - a cadence id.
 * @return {object|undefined} the preset with that id.
 */
export function presetById(id) {
	return INTERVAL_PRESETS.find((p) => p.id === id)
}
