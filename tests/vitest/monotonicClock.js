/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A wall clock for the test suite that never runs backwards.
 *
 * Vue Test Utils stamps every event it triggers with `Date.now() + 1`, and
 * Vue's event invoker drops an event whose stamp is not later than the moment
 * the listener was attached. When the machine's wall clock steps back between
 * a mount and a trigger (a time sync under load steps it back by hundreds of
 * milliseconds), the handler silently does not run and the test fails on a
 * value the handler should have set. Holding `Date.now()` at its highest value
 * so far keeps every stamp later than every attach.
 */

/**
 * Replace `target.now` with a reading that never goes below an earlier one.
 *
 * @param {{now: function(): number}} target The clock to wrap, normally `Date`.
 * @return {function(): number} The installed reading, marked `monotonic`.
 */
export function installMonotonicClock(target) {
	const read = target.now.bind(target)
	let highest = -Infinity
	const now = () => {
		highest = Math.max(highest, read())
		return highest
	}
	now.monotonic = true
	target.now = now
	return now
}
