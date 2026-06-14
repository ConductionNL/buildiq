/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for manifest dependency auto-management (workflow attachments).
 *
 * Spec: procest-workflow-attachments (REQ-PWA-006).
 */
import { describe, it, expect } from 'vitest'
import {
	hasWorkflowAttachment,
	reconcileWorkflowDependency,
	stripDependencyMarker,
} from '../../src/services/manifestDependencies.js'

const withWf = () => ({ dependencies: [], runtime: { workflows: [{ id: 'a', schema: 's' }] } })

describe('manifestDependencies (workflow)', () => {
	it('detects a workflow attachment', () => {
		expect(hasWorkflowAttachment({ runtime: { workflows: [] } })).toBe(false)
		expect(hasWorkflowAttachment(withWf())).toBe(true)
	})
	it('adds procest once when an attachment exists', () => {
		const m = reconcileWorkflowDependency(withWf())
		expect(m.dependencies).toEqual(['procest'])
		expect(reconcileWorkflowDependency(m).dependencies).toEqual(['procest'])
	})
	it('auto-removes procest when the last attachment is gone', () => {
		let m = reconcileWorkflowDependency(withWf())
		m = reconcileWorkflowDependency({ ...m, runtime: { workflows: [] } })
		expect(m.dependencies).toEqual([])
	})
	it('never removes a manually-added procest dependency', () => {
		const m = reconcileWorkflowDependency({ dependencies: ['procest'], runtime: { workflows: [] } })
		expect(m.dependencies).toEqual(['procest'])
	})
	it('strips the internal marker before serialization', () => {
		const m = reconcileWorkflowDependency(withWf())
		expect(m._openbuildAutoDeps).toBeDefined()
		stripDependencyMarker(m)
		expect(m._openbuildAutoDeps).toBeUndefined()
	})
})
