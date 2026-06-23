// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import WalkthroughDesigner from '../../src/components/walkthrough-editor/WalkthroughDesigner.vue'

/**
 * Spec: openbuild-walkthrough-editor (ADR-043). The controlled designer edits a
 * manifest `walkthrough` block and emits `update:manifest` on every mutation.
 */
const baseManifest = (walkthrough) => ({ version: '1.0.0', menu: [], pages: [], walkthrough })

function factory(walkthrough = { enabled: true, tours: [] }) {
	return shallowMount(WalkthroughDesigner, {
		propsData: { manifest: baseManifest(walkthrough) },
		mocks: { t: (app, str) => str },
	})
}

function lastManifest(w) {
	const emitted = w.emitted('update:manifest')
	return emitted[emitted.length - 1][0]
}

describe('WalkthroughDesigner — controlled walkthrough editor', () => {
	it('addTour emits a manifest with a new empty-steps tour', () => {
		const w = factory()
		w.vm.addTour()
		const next = lastManifest(w)
		expect(next.walkthrough.tours.length).toBe(1)
		expect(next.walkthrough.tours[0].steps).toEqual([])
	})

	it('addStep seeds a step with sensible defaults', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [] }] })
		w.vm.activeTourIndex = 0
		w.vm.addStep()
		const step = lastManifest(w).walkthrough.tours[0].steps[0]
		expect(step.target.kind).toBe('nav-item')
		expect(step.advanceOn.type).toBe('manual')
		expect(step.sinceVersion).toBe('1.0.0')
	})

	it('setStep edits a field; moveStep reorders', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [
			{ id: 'a', target: { kind: 'nav-item' }, advanceOn: { type: 'manual' } },
			{ id: 'b', target: { kind: 'page' }, advanceOn: { type: 'manual' } },
		] }] })
		w.vm.setStep(0, 'title', 'Hi')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].title).toBe('Hi')
		w.vm.moveStep(0, 1)
		expect(lastManifest(w).walkthrough.tours[0].steps.map((s) => s.id)).toEqual(['b', 'a'])
	})

	it('setTarget / setAdvance merge nested keys', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [
			{ id: 'a', target: { kind: 'nav-item' }, advanceOn: { type: 'manual' } },
		] }] })
		w.vm.setTarget(0, 'ref', 'Products')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].target).toEqual({ kind: 'nav-item', ref: 'Products' })
		w.vm.setAdvance(0, 'type', 'route-match')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].advanceOn.type).toBe('route-match')
	})

	it('deleteTour removes the active tour', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [] }, { id: 't2', steps: [] }] })
		w.vm.activeTourIndex = 1
		w.vm.deleteTour()
		expect(lastManifest(w).walkthrough.tours.map((tr) => tr.id)).toEqual(['t1'])
	})

	it('setEnabled toggles the block flag', () => {
		const w = factory({ enabled: true, tours: [] })
		w.vm.setEnabled(false)
		expect(lastManifest(w).walkthrough.enabled).toBe(false)
	})
})
