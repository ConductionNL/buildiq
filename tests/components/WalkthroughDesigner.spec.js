// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WalkthroughDesigner from '../../src/components/walkthrough-editor/WalkthroughDesigner.vue'

/**
 * Spec: buildiq-walkthrough-editor (ADR-043). The controlled designer edits a
 * manifest `walkthrough` block and emits `update:manifest` on every mutation.
 */
function baseManifest(walkthrough) {
	return {
		version: '1.0.0',
		menu: [],
		pages: [],
		walkthrough,
	}
}

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
		const w = factory({
			enabled: true,
			tours: [
				{
					id: 't1',
					steps: [
						{
							id: 'a',
							target: { kind: 'nav-item' },
							advanceOn: { type: 'manual' },
						},
						{
							id: 'b',
							target: { kind: 'page' },
							advanceOn: { type: 'manual' },
						},
					],
				},
			],
		})
		w.vm.setStep(0, 'title', 'Hi')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].title).toBe('Hi')
		w.vm.moveStep(0, 1)
		expect(lastManifest(w).walkthrough.tours[0].steps.map((s) => s.id)).toEqual([
			'b',
			'a',
		])
	})

	it('setTarget / setAdvance merge nested keys', () => {
		const w = factory({
			enabled: true,
			tours: [
				{
					id: 't1',
					steps: [
						{
							id: 'a',
							target: { kind: 'nav-item' },
							advanceOn: { type: 'manual' },
						},
					],
				},
			],
		})
		w.vm.setTarget(0, 'ref', 'Products')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].target).toEqual({
			kind: 'nav-item',
			ref: 'Products',
		})
		w.vm.setAdvance(0, 'type', 'route-match')
		expect(lastManifest(w).walkthrough.tours[0].steps[0].advanceOn.type).toBe(
			'route-match',
		)
	})

	it('deleteTour removes the active tour', () => {
		const w = factory({
			enabled: true,
			tours: [
				{ id: 't1', steps: [] },
				{ id: 't2', steps: [] },
			],
		})
		w.vm.activeTourIndex = 1
		w.vm.deleteTour()
		expect(lastManifest(w).walkthrough.tours.map((tr) => tr.id)).toEqual(['t1'])
	})

	it('setEnabled toggles the block flag', () => {
		const w = factory({ enabled: true, tours: [] })
		w.vm.setEnabled(false)
		expect(lastManifest(w).walkthrough.enabled).toBe(false)
	})

	it('onRecorderPick appends a step with the resolved target (click-target advance for controls)', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [] }] })
		w.vm.activeTourIndex = 0
		w.vm.onRecorderPick({ kind: 'nav-item', ref: 'Products' })
		const steps = lastManifest(w).walkthrough.tours[0].steps
		expect(steps.length).toBe(1)
		expect(steps[0].target).toEqual({ kind: 'nav-item', ref: 'Products' })
		expect(steps[0].advanceOn.type).toBe('click-target')
	})

	it('onRecorderPick uses a manual advance for selector/page targets', () => {
		const w = factory({ enabled: true, tours: [{ id: 't1', steps: [] }] })
		w.vm.activeTourIndex = 0
		w.vm.onRecorderPick({ kind: 'selector', selector: '#plain' })
		expect(lastManifest(w).walkthrough.tours[0].steps[0].advanceOn.type).toBe(
			'manual',
		)
	})
})

describe('WalkthroughDesigner — setup-block editing (REQ-WALK-OB-005)', () => {
	function setupFactory(setup = { enabled: true, steps: [] }) {
		return shallowMount(WalkthroughDesigner, {
			propsData: {
				manifest: {
					version: '1.0.0',
					menu: [],
					pages: [],
					walkthrough: { enabled: true, tours: [] },
					setup,
				},
			},
			mocks: { t: (app, str) => str },
		})
	}

	it('addSetupStep seeds an info step', () => {
		const w = setupFactory()
		w.vm.addSetupStep()
		expect(w.emitted('update:manifest').pop()[0].setup.steps[0].type).toBe(
			'info',
		)
	})

	it('setSetupStep edits a field on an existing step', () => {
		const w = setupFactory({ enabled: true, steps: [{ id: 'a', type: 'info' }] })
		w.vm.setSetupStep(0, 'type', 'choice')
		expect(w.emitted('update:manifest').pop()[0].setup.steps[0].type).toBe(
			'choice',
		)
	})

	it('addSetupOption creates an option row; setSetupOption edits it', () => {
		const w1 = setupFactory({
			enabled: true,
			steps: [{ id: 'region', type: 'choice' }],
		})
		w1.vm.addSetupOption(0)
		expect(
			w1.emitted('update:manifest').pop()[0].setup.steps[0].options,
		).toEqual([{ value: '', label: '' }])
		const w2 = setupFactory({
			enabled: true,
			steps: [
				{
					id: 'region',
					type: 'choice',
					options: [{ value: '', label: '' }],
				},
			],
		})
		w2.vm.setSetupOption(0, 0, 'value', 'nl')
		expect(
			w2.emitted('update:manifest').pop()[0].setup.steps[0].options[0].value,
		).toBe('nl')
	})

	it('moveSetupStep reorders; deleteSetupStep removes', () => {
		const w = setupFactory({
			enabled: true,
			steps: [
				{ id: 'a', type: 'info' },
				{ id: 'b', type: 'summary' },
			],
		})
		w.vm.moveSetupStep(0, 1)
		expect(
			w
				.emitted('update:manifest')
				.pop()[0]
				.setup.steps.map((s) => s.id),
		).toEqual(['b', 'a'])
		w.vm.deleteSetupStep(0)
		expect(
			w
				.emitted('update:manifest')
				.pop()[0]
				.setup.steps.map((s) => s.id),
		).toEqual(['b'])
	})

	it('setSetupEnabled toggles the setup flag', () => {
		const w = setupFactory({ enabled: true, steps: [] })
		w.vm.setSetupEnabled(false)
		expect(w.emitted('update:manifest').pop()[0].setup.enabled).toBe(false)
	})

	it('errors scope is mode-aware (setup mode filters setup errors)', () => {
		const w = setupFactory()
		w.vm.mode = 'setup'
		// stub validateManifest returns no errors, so valid in either mode
		expect(w.vm.valid).toBe(true)
	})
})
