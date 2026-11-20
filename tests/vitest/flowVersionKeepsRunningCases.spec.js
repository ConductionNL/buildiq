/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCV-018: changing a published flow leaves running flows on their
 * version. buildiq does not own the flow editor or the flow engine: the
 * Automations page opens a flow on the `ApplicationFlow` page, whose type
 * `flow` is nextcloud-vue's shared flow editor (CnFlowEditorPage over
 * CnFlowDetail and its `useFlowStore`), and OpenRegister publishes versions
 * and pins each run to the version it started on.
 *
 * This spec asserts the half buildiq ships against, on the nextcloud-vue
 * version installed here, by driving the real flow store:
 *   - a published flow is locked: an edit is refused with `version-immutable`
 *     and the editor offers "Create draft version";
 *   - creating a draft and publishing it are the two lifecycle calls on
 *     OpenRegister's flow API, after which the flow is at version 4.
 * That a run already in progress keeps running on version 3 is OpenRegister's
 * engine (FlowPublishedGraph runs each FlowRun on its pinned version) and is
 * asserted there, in tests/Unit/Service/Flow/FlowVersionPinningTest.php and
 * FlowRunVersionPinGoldenTest.php.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/automation-designer/spec.md#requirement-changing-a-published-flow-leaves-running-flows-on-their-version-req-bqcv-018
 */
import * as fs from 'fs'
import * as path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import axios from '@nextcloud/axios'
import { useFlowStore } from '../../node_modules/@conduction/nextcloud-vue/src/composables/useFlowStore.js'

const LIBRARY = path.resolve(__dirname, '../../node_modules/@conduction/nextcloud-vue/src')

/**
 * The flow as OpenRegister lists it, at a version and lifecycle status.
 *
 * @param {number} version The flow's current version.
 * @param {string} lifecycleStatus `published` or `draft`.
 * @return {object}
 */
function flowAt(version, lifecycleStatus) {
	return {
		id: 'flow-intake',
		app: 'permit-desk',
		name: 'Intake',
		version,
		lifecycleStatus,
		nodes: [{ id: 'start', type: 'start', position: { x: 0, y: 0 } }],
		edges: [],
	}
}

/**
 * A flow store with the given flow open, as the editor holds it.
 *
 * @param {object} flow The flow.
 * @return {ReturnType<typeof useFlowStore>}
 */
function storeWithOpen(flow) {
	const store = useFlowStore()
	store.flows = [flow]
	store.open(flow.id, flow.app)
	return store
}

beforeEach(() => {
	setActivePinia(createPinia())
	axios.get.mockReset()
	axios.post.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('REQ-BQCV-018 changing a published flow leaves running flows on their version', () => {
	it('the Automations page opens a flow on the shared flow editor page, not an app-owned editor', () => {
		const { pages } = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = pages.find((p) => p.id === 'ApplicationFlow')
		const pageTypes = fs.readFileSync(path.join(LIBRARY, 'components/CnPageRenderer/pageTypes.js'), 'utf8')

		expect(page.type).toBe('flow')
		expect(pageTypes).toMatch(/flow: defineAsyncComponent\(\(\) => import\('\.\.\/CnFlowsPage\/CnFlowEditorPage\.vue'\)/)
	})

	it('A published flow is not edited in place: the edit is refused and a draft version is offered', () => {
		const store = storeWithOpen(flowAt(3, 'published'))

		expect(store.graphLocked).toBe(true)
		expect(store.pushUndo()).toBe(false)
		expect(store.lifecycleRefusal).toEqual({ reason: 'version-immutable', lifecycleStatus: 'published' })

		const detail = fs.readFileSync(path.join(LIBRARY, 'components/CnFlowDetail/CnFlowDetail.vue'), 'utf8')
		expect(detail).toContain("id: 'graph-locked'")
		expect(detail).toContain("label: this.t('nextcloud-vue', 'Create draft version')")
	})

	it('A changed flow does not move a running case: draft, change, publish make version 4 the one new runs start on', async () => {
		const store = storeWithOpen(flowAt(3, 'published'))
		let listed = flowAt(3, 'published')
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/versions')) {
				return Promise.resolve({ data: { results: [] } })
			}
			return Promise.resolve({ data: { results: [listed] } })
		})

		axios.post.mockImplementationOnce(() => {
			listed = flowAt(4, 'draft')
			return Promise.resolve({ data: { version: 4, lifecycleStatus: 'draft' } })
		})
		await store.createDraft()

		expect(axios.post).toHaveBeenLastCalledWith('/apps/openregister/api/flows/flow-intake/draft')
		expect(store.flow.version).toBe(4)
		expect(store.graphLocked).toBe(false)
		expect(store.pushUndo()).not.toBe(false)

		axios.post.mockImplementationOnce(() => {
			listed = flowAt(4, 'published')
			return Promise.resolve({ data: { version: 4, lifecycleStatus: 'published' } })
		})
		await store.publish()

		expect(axios.post).toHaveBeenLastCalledWith('/apps/openregister/api/flows/flow-intake/publish')
		expect(store.flow.version).toBe(4)
		expect(store.flow.lifecycleStatus).toBe('published')
		expect(store.graphLocked).toBe(true)
	})
})
