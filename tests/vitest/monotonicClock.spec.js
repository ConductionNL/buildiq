/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the suite's monotonic wall clock, and for the event drop it
 * prevents: a name typed into the custom-chain wizard step after the wall
 * clock stepped back.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import Step3Custom from '../../src/dialogs/CreateApplicationWizard/Step3Custom.vue'
import { installMonotonicClock } from './monotonicClock.js'

const suiteNow = Date.now

/**
 * A clock that reads `time.value`, so a test can step it back.
 *
 * @return {{time: {value: number}, clock: {now: function(): number}}} The clock and its time.
 */
function steppableClock() {
	const time = { value: 1_000_000 }
	return { time, clock: { now: () => time.value } }
}

/**
 * Mount the wizard step, step the clock back 600 ms, type a name.
 *
 * @param {{value: number}} time The time the clock reads.
 * @return {Promise<object>} The first version row afterwards.
 */
async function typeNameAfterStepBack(time) {
	const wrapper = mount(Step3Custom, { propsData: { payload: { preset: 'custom', versions: [] } } })
	time.value -= 600
	await wrapper.find('#wizard-version-name-0').setValue('My Version')
	return wrapper.vm.localVersions[0]
}

describe('monotonic clock', () => {
	afterEach(() => {
		Date.now = suiteNow
	})

	it('is installed for the whole suite', () => {
		expect(Date.now.monotonic).toBe(true)
	})

	it('never reads below an earlier reading', () => {
		const { time, clock } = steppableClock()
		const now = installMonotonicClock(clock)
		expect(clock.now).toBe(now)
		expect(now()).toBe(1_000_000)
		time.value -= 600
		expect(now()).toBe(1_000_000)
		time.value += 1_000
		expect(now()).toBe(1_000_400)
	})

	it('without it, a clock that steps back drops a triggered input event', async () => {
		const { time, clock } = steppableClock()
		Date.now = clock.now
		const row = await typeNameAfterStepBack(time)
		expect(row.name).toBe('Production')
	})

	it('with it, the same input event reaches its handler', async () => {
		const { time, clock } = steppableClock()
		Date.now = installMonotonicClock(clock)
		const row = await typeNameAfterStepBack(time)
		expect(row.name).toBe('My Version')
		expect(row.slug).toBe('my-version')
	})
})
