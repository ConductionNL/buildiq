/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `readThrough` over scripted sequences of reads: fresh
 * hits, expiry, concurrent dedupe, stale-on-error inside and outside the
 * 10x window, a cache cleared while a load is in flight, synchronous throws
 * and plain (non-promise) loader values. Every result, error, loader call,
 * clock read and the settle order of concurrent callers is pinned.
 *
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
import { beforeEach, describe, expect, it } from 'vitest'
import {
	clearConnectorCache,
	readThrough,
} from '../../src/services/connectorCache.js'

/**
 * Build a scripted world: a manual clock, a loader factory and a log.
 *
 * @return {object}
 */
function world() {
	const state = { clock: 1000, reads: 0, calls: 0, log: [] }
	state.now = () => {
		state.reads++
		return state.clock
	}
	state.deferred = () => {
		let resolve
		let reject
		const promise = new Promise((res, rej) => {
			resolve = res
			reject = rej
		})
		return { promise, resolve, reject }
	}
	state.loader = (outcome) => () => {
		state.calls++
		if (outcome.throwSync) {
			throw new Error(outcome.throwSync)
		}
		if (outcome.plain !== undefined) {
			return outcome.plain
		}
		if (outcome.deferred) {
			return outcome.deferred.promise
		}
		return outcome.error
			? Promise.reject(new Error(outcome.error))
			: Promise.resolve(outcome.data)
	}
	state.read = (label, args) => {
		const [key, ttlMs, loader, useClock] = args
		const call =
			useClock === false
				? readThrough(key, ttlMs, loader)
				: readThrough(key, ttlMs, loader, state.now)
		return call.then(
			(result) => state.log.push(`${label} ok ${JSON.stringify(result)}`),
			(err) => state.log.push(`${label} err ${err.message}`),
		)
	}
	state.snapshot = () =>
		`${state.log.join(' | ')} || calls=${state.calls} reads=${state.reads}`
	return state
}

const SCENARIOS = {
	'miss, fresh hit, expiry': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: { v: 1 } })])
		w.clock += 99
		await w.read('b', ['k', 100, w.loader({ data: { v: 2 } })])
		w.clock += 1
		await w.read('c', ['k', 100, w.loader({ data: { v: 3 } })])
	},
	'fresh check uses the stored ttl, not the new one': async (w) => {
		await w.read('a', ['k', 500, w.loader({ data: 1 })])
		w.clock += 200
		await w.read('b', ['k', 100, w.loader({ data: 2 })])
		w.clock += 150
		await w.read('c', ['k', 1000, w.loader({ data: 3 })])
	},
	'ttl zero never hits': async (w) => {
		await w.read('a', ['k', 0, w.loader({ data: 'x' })])
		await w.read('b', ['k', 0, w.loader({ data: 'y' })])
	},
	'undefined data never hits': async (w) => {
		await w.read('a', ['k', 100, w.loader({})])
		await w.read('b', ['k', 100, w.loader({ data: null })])
		await w.read('c', ['k', 100, w.loader({ data: 'z' })])
	},
	'falsy data still hits': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 0 })])
		await w.read('b', ['k', 100, w.loader({ data: 9 })])
	},
	'concurrent reads share one load': async (w) => {
		const d = w.deferred()
		const p = [
			w.read('a', ['k', 100, w.loader({ deferred: d })]),
			w.read('b', ['k', 100, w.loader({ data: 'other' })]),
			w.read('c', ['k', 100, w.loader({ data: 'other' })]),
		]
		d.resolve('shared')
		await Promise.all(p)
	},
	'concurrent reads share one failure': async (w) => {
		const d = w.deferred()
		const p = [
			w.read('a', ['k', 100, w.loader({ deferred: d })]),
			w.read('b', ['k', 100, w.loader({ data: 'x' })]),
		]
		d.reject(new Error('down'))
		await Promise.all(p)
	},
	'expired entry with an in-flight reload is awaited': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 500
		const d = w.deferred()
		const p = [
			w.read('b', ['k', 100, w.loader({ deferred: d })]),
			w.read('c', ['k', 100, w.loader({ data: 'x' })]),
		]
		d.resolve('new')
		await Promise.all(p)
		await w.read('d', ['k', 100, w.loader({ data: 'y' })])
	},
	'failure with no previous entry throws': async (w) => {
		await w.read('a', ['k', 100, w.loader({ error: 'boom' })])
		await w.read('b', ['k', 100, w.loader({ data: 'ok' })])
	},
	'stale inside the 10x window': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 999
		await w.read('b', ['k', 100, w.loader({ error: 'boom' })])
		await w.read('c', ['k', 100, w.loader({ error: 'boom' })])
		w.clock += 1
		await w.read('d', ['k', 100, w.loader({ error: 'boom' })])
		await w.read('e', ['k', 100, w.loader({ data: 'fresh' })])
	},
	'stale window uses the new ttl': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 1500
		await w.read('b', ['k', 200, w.loader({ error: 'boom' })])
		await w.read('c', ['k', 100, w.loader({ error: 'boom' })])
	},
	'stale age is read after the load fails': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 500
		const d = w.deferred()
		const p = w.read('b', ['k', 100, w.loader({ deferred: d })])
		w.clock += 600
		d.reject(new Error('late'))
		await p
	},
	'stale with undefined previous data throws': async (w) => {
		await w.read('a', ['k', 100, w.loader({})])
		w.clock += 200
		await w.read('b', ['k', 100, w.loader({ error: 'boom' })])
	},
	'cleared while in flight, then fails': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 200
		const d = w.deferred()
		const p = w.read('b', ['k', 100, w.loader({ deferred: d })])
		clearConnectorCache()
		d.reject(new Error('gone'))
		await p
		await w.read('c', ['k', 100, w.loader({ data: 'after' })])
	},
	'cleared while in flight, then succeeds': async (w) => {
		const d = w.deferred()
		const p = w.read('a', ['k', 100, w.loader({ deferred: d })])
		clearConnectorCache()
		d.resolve('kept')
		await p
		await w.read('b', ['k', 100, w.loader({ data: 'x' })])
	},
	'cleared, re-read with another ttl, first fails': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 300
		const d1 = w.deferred()
		const p1 = w.read('b', ['k', 100, w.loader({ deferred: d1 })])
		clearConnectorCache()
		const d2 = w.deferred()
		const p2 = w.read('c', ['k', 5000, w.loader({ deferred: d2 })])
		d1.reject(new Error('first'))
		await p1
		d2.resolve('second')
		await p2
		await w.read('d', ['k', 100, w.loader({ data: 'x' })])
	},
	'synchronous loader throw': async (w) => {
		await w.read('a', ['k', 100, w.loader({ throwSync: 'sync' })])
		await w.read('b', ['k', 100, w.loader({ data: 'after' })])
	},
	'synchronous throw with a previous entry': async (w) => {
		await w.read('a', ['k', 100, w.loader({ data: 'old' })])
		w.clock += 200
		await w.read('b', ['k', 100, w.loader({ throwSync: 'sync' })])
		await w.read('c', ['k', 100, w.loader({ error: 'boom' })])
	},
	'plain loader value': async (w) => {
		await w.read('a', ['k', 100, w.loader({ plain: 'raw' })])
		await w.read('b', ['k', 100, w.loader({ plain: 'raw2' })])
	},
	'separate keys do not share': async (w) => {
		await w.read('a', ['k1', 100, w.loader({ data: 1 })])
		await w.read('b', ['k2', 100, w.loader({ data: 2 })])
		await w.read('c', ['k1', 100, w.loader({ data: 3 })])
	},
	'interleaved settle order': async (w) => {
		const d1 = w.deferred()
		const d2 = w.deferred()
		const p = [
			w.read('a', ['k1', 100, w.loader({ deferred: d1 })]),
			w.read('b', ['k2', 100, w.loader({ deferred: d2 })]),
			w.read('c', ['k1', 100, w.loader({ data: 'x' })]),
			w.read('d', ['k2', 100, w.loader({ data: 'x' })]),
		]
		d2.resolve('two')
		d1.resolve('one')
		await Promise.all(p)
	},
	'default clock': async (w) => {
		await w.read('a', ['k', 60000, w.loader({ data: 'd' }), false])
		await w.read('b', ['k', 60000, w.loader({ data: 'e' }), false])
	},
}

describe('readThrough golden', () => {
	beforeEach(() => clearConnectorCache())

	for (const [name, script] of Object.entries(SCENARIOS)) {
		it(`pins ${name}`, async () => {
			const w = world()
			await script(w)
			expect(w.snapshot()).toMatchSnapshot()
		})
	}
})
