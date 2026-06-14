/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for manifest dependency auto-management.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-005).
 */
import { describe, it, expect } from 'vitest'
import {
	hasConnectorBinding,
	reconcileConnectorDependency,
	stripDependencyMarker,
} from '../../src/services/manifestDependencies.js'

describe('manifestDependencies', () => {
	it('detects a connector binding on a page or widget', () => {
		expect(hasConnectorBinding({ pages: [{ config: { register: 'r' } }] })).toBe(false)
		expect(hasConnectorBinding({ pages: [{ config: { dataSource: { connector: {} } } }] })).toBe(true)
		expect(hasConnectorBinding({ pages: [{ config: { widgets: [{ dataSource: { connector: {} } }] } }] })).toBe(true)
	})

	it('adds openconnector once when a binding exists', () => {
		const m = reconcileConnectorDependency({ dependencies: [], pages: [{ config: { dataSource: { connector: {} } } }] })
		expect(m.dependencies).toEqual(['openconnector'])
		const again = reconcileConnectorDependency(m)
		expect(again.dependencies).toEqual(['openconnector'])
	})

	it('auto-removes openconnector when the last binding is gone (only if auto-added)', () => {
		let m = reconcileConnectorDependency({ dependencies: [], pages: [{ config: { dataSource: { connector: {} } } }] })
		m = reconcileConnectorDependency({ ...m, pages: [{ config: { register: 'r' } }] })
		expect(m.dependencies).toEqual([])
	})

	it('never removes a manually-added openconnector dependency', () => {
		const m = reconcileConnectorDependency({ dependencies: ['openconnector'], pages: [{ config: { register: 'r' } }] })
		expect(m.dependencies).toEqual(['openconnector'])
	})

	it('strips the internal marker before serialization', () => {
		const m = reconcileConnectorDependency({ dependencies: [], pages: [{ config: { dataSource: { connector: {} } } }] })
		expect(m._openbuildAutoDeps).toBeDefined()
		stripDependencyMarker(m)
		expect(m._openbuildAutoDeps).toBeUndefined()
	})
})
