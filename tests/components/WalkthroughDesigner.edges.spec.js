// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WalkthroughDesigner from '../../src/components/walkthrough-editor/WalkthroughDesigner.vue'

/**
 * Spec: buildiq-walkthrough-editor (ADR-043). The edges of the controlled
 * designer: blocks with no step list yet, moves past either end, a recorder
 * pick with nothing to add it to, and the tour and option editors that the
 * main suite does not drive.
 */
function factory(manifest) {
	return shallowMount(WalkthroughDesigner, {
		propsData: manifest === undefined ? {} : { manifest },
		mocks: { t: (app, str) => str },
	})
}

function lastManifest(w) {
	const emitted = w.emitted('update:manifest')
	return emitted[emitted.length - 1][0]
}

describe('WalkthroughDesigner — edges', () => {
	it('without a manifest, adding a tour starts an enabled walkthrough', () => {
		const w = factory()

		w.vm.addTour()

		const next = lastManifest(w)
		expect(next.walkthrough.enabled).toBe(true)
		expect(next.walkthrough.tours).toEqual([
			{ id: 'tour-1', title: 'New tour', trigger: 'manual', steps: [] },
		])
	})

	it('a walkthrough whose tours are not a list gets one on the first tour', () => {
		const w = factory({ version: '2.0.0', walkthrough: { enabled: true, tours: 'broken' } })

		w.vm.addTour()

		expect(lastManifest(w).walkthrough.tours).toHaveLength(1)
		expect(lastManifest(w).version).toBe('2.0.0')
	})

	it('setTour edits a field on the active tour only', () => {
		const w = factory({ walkthrough: { tours: [{ id: 'a', title: 'A' }, { id: 'b', title: 'B' }] } })
		w.vm.activeTourIndex = 1

		w.vm.setTour('title', 'Renamed')

		expect(lastManifest(w).walkthrough.tours.map((tour) => tour.title)).toEqual(['A', 'Renamed'])
	})

	it('a tour without a step list gets one on the first step, from a step or a pick', () => {
		const w = factory({ version: '3.1.0', walkthrough: { tours: [{ id: 'a' }] } })

		w.vm.addStep()
		expect(lastManifest(w).walkthrough.tours[0].steps).toHaveLength(1)

		w.vm.onRecorderPick({ kind: 'element', ref: 'save' })
		const steps = lastManifest(w).walkthrough.tours[0].steps
		expect(steps).toEqual([
			{
				id: 'step-1',
				title: '',
				sinceVersion: '3.1.0',
				target: { kind: 'element', ref: 'save' },
				advanceOn: { type: 'click-target' },
			},
		])
	})

	it('a recorder pick without a target or without a tour adds nothing', () => {
		const w = factory({ walkthrough: { tours: [] } })

		w.vm.onRecorderPick(null)
		w.vm.onRecorderPick({ kind: 'element', ref: 'save' })

		expect(w.emitted('update:manifest')).toBeUndefined()
	})

	it('a step moved past either end stays where it is', () => {
		const w = factory({ walkthrough: { tours: [{ id: 'a', steps: [{ id: 's1' }, { id: 's2' }] }] } })

		w.vm.moveStep(0, -1)
		w.vm.moveStep(1, 1)

		expect(w.emitted('update:manifest')).toBeUndefined()
	})

	it('a setup without a step list gets one, and a setup step moved past either end stays', () => {
		const w = factory({ setup: { enabled: true } })

		w.vm.addSetupStep()
		expect(lastManifest(w).setup.steps).toEqual([{ id: 'step-1', type: 'info', required: false }])

		const moved = factory({ setup: { steps: [{ id: 's1' }, { id: 's2' }] } })
		moved.vm.moveSetupStep(0, -1)
		moved.vm.moveSetupStep(1, 1)
		expect(moved.emitted('update:manifest')).toBeUndefined()
	})

	it('deleteSetupOption removes only that option', () => {
		const w = factory({
			setup: {
				steps: [
					{
						id: 's1',
						type: 'choice',
						options: [
							{ value: 'a', label: 'A' },
							{ value: 'b', label: 'B' },
						],
					},
				],
			},
		})

		w.vm.deleteSetupOption(0, 0)

		expect(lastManifest(w).setup.steps[0].options).toEqual([{ value: 'b', label: 'B' }])
	})
})
