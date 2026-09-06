/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for manifest dependency auto-management (workflow attachments
 * and connector data sources — both reuse the same generic helpers).
 *
 * Spec: procest-workflow-attachments (REQ-PWA-006), openconnector-api-sources (REQ-OCAS-005),
 * docudesk-document-templates (REQ-DDT-005).
 */
import { describe, expect, it } from 'vitest'
import {
	hasConnectorBinding,
	hasDocumentAttachment,
	hasWorkflowAttachment,
	reconcileConnectorDependency,
	reconcileDocumentDependency,
	reconcileWorkflowDependency,
	stripDependencyMarker,
} from '../../src/services/manifestDependencies.js'

function withWf() {
	return {
		dependencies: [],
		runtime: { workflows: [{ id: 'a', schema: 's' }] },
	}
}

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
		const m = reconcileWorkflowDependency({
			dependencies: ['procest'],
			runtime: { workflows: [] },
		})
		expect(m.dependencies).toEqual(['procest'])
	})
	it('strips the internal marker before serialization', () => {
		const m = reconcileWorkflowDependency(withWf())
		expect(m._buildiqAutoDeps).toBeDefined()
		stripDependencyMarker(m)
		expect(m._buildiqAutoDeps).toBeUndefined()
	})
})

describe('manifestDependencies (connector)', () => {
	it('detects a connector binding on a page or widget', () => {
		expect(hasConnectorBinding({ pages: [{ config: { register: 'r' } }] })).toBe(
			false,
		)
		expect(
			hasConnectorBinding({
				pages: [{ config: { dataSource: { connector: {} } } }],
			}),
		).toBe(true)
		expect(
			hasConnectorBinding({
				pages: [
					{ config: { widgets: [{ dataSource: { connector: {} } }] } },
				],
			}),
		).toBe(true)
	})

	it('adds openconnector once when a binding exists', () => {
		const m = reconcileConnectorDependency({
			dependencies: [],
			pages: [{ config: { dataSource: { connector: {} } } }],
		})
		expect(m.dependencies).toEqual(['openconnector'])
		const again = reconcileConnectorDependency(m)
		expect(again.dependencies).toEqual(['openconnector'])
	})

	it('auto-removes openconnector when the last binding is gone (only if auto-added)', () => {
		let m = reconcileConnectorDependency({
			dependencies: [],
			pages: [{ config: { dataSource: { connector: {} } } }],
		})
		m = reconcileConnectorDependency({
			...m,
			pages: [{ config: { register: 'r' } }],
		})
		expect(m.dependencies).toEqual([])
	})

	it('never removes a manually-added openconnector dependency', () => {
		const m = reconcileConnectorDependency({
			dependencies: ['openconnector'],
			pages: [{ config: { register: 'r' } }],
		})
		expect(m.dependencies).toEqual(['openconnector'])
	})

	it('strips the internal marker before serialization', () => {
		const m = reconcileConnectorDependency({
			dependencies: [],
			pages: [{ config: { dataSource: { connector: {} } } }],
		})
		expect(m._buildiqAutoDeps).toBeDefined()
		stripDependencyMarker(m)
		expect(m._buildiqAutoDeps).toBeUndefined()
	})
})

function withDoc() {
	return {
		dependencies: [],
		runtime: {
			documents: [
				{
					id: 'd',
					schema: 's',
					templateId: 'u',
					templateName: 'T',
					label: 'L',
				},
			],
		},
	}
}

describe('manifestDependencies (document)', () => {
	it('detects a document attachment', () => {
		expect(hasDocumentAttachment({ runtime: { documents: [] } })).toBe(false)
		expect(hasDocumentAttachment(withDoc())).toBe(true)
	})
	it('adds docudesk once when an attachment exists', () => {
		const m = reconcileDocumentDependency(withDoc())
		expect(m.dependencies).toEqual(['filinq'])
		expect(reconcileDocumentDependency(m).dependencies).toEqual(['filinq'])
	})
	it('auto-removes docudesk when the last attachment is gone', () => {
		let m = reconcileDocumentDependency(withDoc())
		m = reconcileDocumentDependency({ ...m, runtime: { documents: [] } })
		expect(m.dependencies).toEqual([])
	})
	it('never removes a manually-added docudesk dependency', () => {
		const m = reconcileDocumentDependency({
			dependencies: ['filinq'],
			runtime: { documents: [] },
		})
		expect(m.dependencies).toEqual(['filinq'])
	})
})
