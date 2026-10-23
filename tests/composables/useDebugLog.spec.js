/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQDM-001 to 003: the designer's debug log of the preview.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md
 */
import { describe, expect, it } from 'vitest'
import { useDebugLog } from '../../src/composables/useDebugLog.js'

describe('useDebugLog', () => {
	it('logs a failed request and a binding problem, not a successful request', () => {
		const log = useDebugLog({ now: () => 1000 })
		log.record({ kind: 'request', pageId: 'ticket', method: 'get', path: '/apps/openregister/api/objects/pipelinq/onderwerp', status: 200, durationMs: 80 })
		log.record({ kind: 'request', pageId: 'ticket', method: 'get', path: '/apps/openregister/api/objects/buildiq/agent?app', status: 403, durationMs: 40 })
		log.record({ kind: 'binding', pageId: 'ticket', problem: 'missing-property', register: 'pipelinq', schema: 'ticket', property: 'subjectReference.title', where: 'column' })

		expect(log.entries.map((e) => e.kind)).toEqual(['binding', 'request'])
		expect(log.entries[1]).toMatchObject({ pageId: 'ticket', request: 'GET /apps/openregister/api/objects/buildiq/agent?app', status: 403, count: 1 })
		expect(log.entries[0]).toMatchObject({ property: 'subjectReference.title', schema: 'ticket' })
	})

	it('leaves out the designer\'s own requests', () => {
		const log = useDebugLog()
		log.record({ kind: 'request', pageId: 'ticket', method: 'PUT', path: '/index.php/apps/buildiq/api/applications/pipelinq/manifest', status: 500 })
		expect(log.entries).toHaveLength(0)
	})

	it('counts the same problem once, with how often and when last', () => {
		let t = 0
		const log = useDebugLog({ now: () => ++t })
		for (let i = 0; i < 12; i++) {
			log.record({ kind: 'request', pageId: 'ticket', method: 'GET', path: '/objects/x', status: 0 })
		}
		log.record({ kind: 'render-error', pageId: 'ticket', component: 'CnTabsWidget', message: 'boom' })

		expect(log.entries).toHaveLength(2)
		expect(log.entries[1]).toMatchObject({ count: 12, lastAt: 12 })
		log.clear()
		expect(log.entries).toHaveLength(0)
	})

	it('keeps at most 200 problems', () => {
		const log = useDebugLog()
		for (let i = 0; i < 250; i++) {
			log.record({ kind: 'request', pageId: 'p', method: 'GET', path: `/objects/${i}`, status: 404 })
		}
		expect(log.entries).toHaveLength(200)
		expect(log.entries[0].request).toBe('GET /objects/249')
	})
})
