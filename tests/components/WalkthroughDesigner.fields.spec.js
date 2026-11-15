// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import WalkthroughDesigner from '../../src/components/walkthrough-editor/WalkthroughDesigner.vue'

/**
 * Spec: buildiq-walkthrough-editor (ADR-043). The designer's template wiring:
 * every field, select and switch writes its own key on the right tour, step,
 * target, advance or setup option, the buttons reach their actions, and the
 * validation card lists the errors of the active editor only. The library
 * controls are stubbed so each one can be driven by its label.
 */

// The suite-wide library stub always validates; this spec sets the errors.
const validation = vi.hoisted(() => ({ errors: [] }))
vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	validateManifest: () => ({ errors: validation.errors }),
}))

afterEach(() => {
	validation.errors = []
})

const NcTextField = { name: 'NcTextField', props: ['label', 'modelValue'], emits: ['update:modelValue'], template: '<input />' }
const NcSelect = { name: 'NcSelect', props: ['inputLabel', 'options', 'modelValue'], emits: ['update:modelValue'], template: '<div />' }
const NcCheckboxRadioSwitch = { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], emits: ['update:modelValue'], template: '<label><slot /></label>' }
const NcButton = { name: 'NcButton', template: '<button><slot name="icon" /><slot /></button>' }
const NcNoteCard = { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' }
const CnTabs = { name: 'CnTabs', template: '<div><slot name="nav-end" /><slot /></div>' }
const CnTab = { name: 'CnTab', props: ['title', 'active'], template: '<section><slot /></section>' }
const StepHeadRow = { name: 'StepHeadRow', props: ['index', 'stepId', 'last'], emits: ['update:stepId', 'move', 'delete'], template: '<div />' }
const WalkthroughRecorder = { name: 'WalkthroughRecorder', props: ['appSlug', 'versionSlug'], emits: ['pick', 'close'], template: '<div class="recorder" />' }

function factory(manifest, props = {}, route = undefined) {
	return mount(WalkthroughDesigner, {
		props: { manifest, ...props },
		global: {
			stubs: {
				NcTextField,
				NcSelect,
				NcCheckboxRadioSwitch,
				NcButton,
				NcNoteCard,
				CnTabs,
				CnTab,
				StepHeadRow,
				WalkthroughRecorder,
				Plus: true,
				Delete: true,
				RecordCircleOutline: true,
			},
			mocks: route ? { $route: route } : {},
		},
	})
}

function lastManifest(w) {
	const emitted = w.emitted('update:manifest')
	return emitted[emitted.length - 1][0]
}

/** The nth stubbed control of a kind whose label (or inputLabel) matches. */
function control(w, kind, label, nth = 0) {
	const prop = kind === NcSelect ? 'inputLabel' : 'label'
	const found = w.findAllComponents(kind).filter((c) => c.props(prop) === label)
	if (!found[nth]) {
		throw new Error(`no ${kind.name} labelled ${label} (#${nth})`)
	}
	return found[nth]
}

/** The switch whose slot text matches. */
function toggle(w, text) {
	return w.findAllComponents(NcCheckboxRadioSwitch).find((c) => c.text() === text)
}

/** The button whose text matches. */
function button(w, text) {
	return w.findAll('button').find((b) => b.text() === text)
}

const TOURS = {
	version: '2.0.0',
	walkthrough: {
		enabled: true,
		tours: [
			{ id: 'first', title: 'First', trigger: 'manual', steps: [] },
			{
				id: 'second',
				title: 'Second',
				trigger: 'manual',
				steps: [
					{ id: 's1', target: { kind: 'nav-item', ref: 'home' }, advanceOn: { type: 'route-match', route: '/a' } },
					{ id: 's2', target: { kind: 'widget', ref: 'w' }, advanceOn: { type: 'object-created', register: 'r', schema: 's' } },
				],
			},
		],
	},
}

describe('WalkthroughDesigner — tour fields', () => {
	it('opens a tour from the rail by click, Enter or Space', async () => {
		const w = factory(TOURS)
		const rail = w.findAll('.wt-designer__tour')

		await rail[1].trigger('click')
		expect(w.vm.activeTourIndex).toBe(1)
		expect(rail[1].attributes('aria-current')).toBe('true')
		await rail[0].trigger('keydown.enter')
		expect(w.vm.activeTourIndex).toBe(0)
		await rail[1].trigger('keydown.space')
		expect(w.vm.activeTourIndex).toBe(1)
	})

	it('writes each tour field on the active tour', async () => {
		const w = factory(TOURS)
		w.vm.activeTourIndex = 1
		await w.vm.$nextTick()

		const edits = [
			[NcTextField, 'Tour id', 'id', 'renamed'],
			[NcTextField, 'Title', 'title', 'Second tour'],
			[NcSelect, 'Trigger', 'trigger', 'first-visit'],
			[NcTextField, 'Min app version', 'minAppVersion', '2.1.0'],
		]
		for (const [kind, label, key, value] of edits) {
			control(w, kind, label).vm.$emit('update:modelValue', value)
			const tours = lastManifest(w).walkthrough.tours
			expect(tours[1][key]).toBe(value)
			expect(tours[0]).toEqual(TOURS.walkthrough.tours[0])
		}
	})

	it('reaches the tour actions from their buttons', async () => {
		const w = factory(TOURS)

		await button(w, 'Delete tour').trigger('click')
		expect(lastManifest(w).walkthrough.tours.map((t) => t.id)).toEqual(['second'])

		await button(w, 'Add step').trigger('click')
		expect(lastManifest(w).walkthrough.tours[0].steps).toHaveLength(1)

		await w.find('button[aria-label="Add tour"]').trigger('click')
		expect(lastManifest(w).walkthrough.tours).toHaveLength(3)
	})

	it('toggles the walkthrough and saves from the bar', async () => {
		const w = factory(TOURS)

		toggle(w, 'Enabled').vm.$emit('update:modelValue', false)
		expect(lastManifest(w).walkthrough.enabled).toBe(false)

		await button(w, 'Save & preview').trigger('click')
		expect(w.emitted('save-and-preview')).toHaveLength(1)
	})
})

describe('WalkthroughDesigner — step fields', () => {
	async function onSecondTour() {
		const w = factory(TOURS)
		w.vm.activeTourIndex = 1
		await w.vm.$nextTick()
		return w
	}

	it('writes each step field on its own step', async () => {
		const w = await onSecondTour()

		const edits = [
			[NcTextField, 'Title', 'title', 'Welcome'],
			[NcTextField, 'Body', 'body', 'This is home.'],
			[NcTextField, 'Task (optional)', 'task', 'Click it'],
			[NcTextField, 'Since version', 'sinceVersion', '2.0.1'],
			[NcSelect, 'Placement', 'placement', 'left'],
		]
		for (const [kind, label, key, value] of edits) {
			const fields = w.findAllComponents(kind).filter((c) => c.props(kind === NcSelect ? 'inputLabel' : 'label') === label)
			fields[fields.length - 1].vm.$emit('update:modelValue', value)
			const steps = lastManifest(w).walkthrough.tours[1].steps
			expect(steps[1][key]).toBe(value)
			expect(steps[0]).toEqual(TOURS.walkthrough.tours[1].steps[0])
		}
	})

	it('merges target and advance edits into the existing objects', async () => {
		const w = await onSecondTour()

		control(w, NcSelect, 'Target kind', 0).vm.$emit('update:modelValue', 'action')
		expect(lastManifest(w).walkthrough.tours[1].steps[0].target).toEqual({ kind: 'action', ref: 'home' })

		control(w, NcTextField, 'Target ref (route / widgetKey / id)', 1).vm.$emit('update:modelValue', 'w2')
		expect(lastManifest(w).walkthrough.tours[1].steps[1].target).toEqual({ kind: 'widget', ref: 'w2' })

		control(w, NcSelect, 'Advance on', 0).vm.$emit('update:modelValue', 'delay')
		expect(lastManifest(w).walkthrough.tours[1].steps[0].advanceOn).toEqual({ type: 'delay', route: '/a' })

		control(w, NcTextField, 'Route').vm.$emit('update:modelValue', '/b')
		expect(lastManifest(w).walkthrough.tours[1].steps[0].advanceOn).toEqual({ type: 'route-match', route: '/b' })

		control(w, NcTextField, 'Register').vm.$emit('update:modelValue', 'r2')
		expect(lastManifest(w).walkthrough.tours[1].steps[1].advanceOn).toEqual({ type: 'object-created', register: 'r2', schema: 's' })

		control(w, NcTextField, 'Schema').vm.$emit('update:modelValue', 's2')
		expect(lastManifest(w).walkthrough.tours[1].steps[1].advanceOn.schema).toBe('s2')
	})

	it('shows the route field only for route-match and the object fields only for object-created', async () => {
		const w = await onSecondTour()

		expect(w.findAllComponents(NcTextField).filter((c) => c.props('label') === 'Route')).toHaveLength(1)
		expect(w.findAllComponents(NcTextField).filter((c) => c.props('label') === 'Register')).toHaveLength(1)
	})

	it('writes the step switches and the step head events', async () => {
		const w = await onSecondTour()

		w.findAllComponents(NcCheckboxRadioSwitch).filter((c) => c.text() === 'Optional (skip if absent)')[0].vm.$emit('update:modelValue', true)
		expect(lastManifest(w).walkthrough.tours[1].steps[0].optional).toBe(true)
		w.findAllComponents(NcCheckboxRadioSwitch).filter((c) => c.text() === 'Allow manual Next')[1].vm.$emit('update:modelValue', true)
		expect(lastManifest(w).walkthrough.tours[1].steps[1].allowManualNext).toBe(true)

		const heads = w.findAllComponents(StepHeadRow)
		expect(heads.map((h) => h.props('last'))).toEqual([false, true])
		heads[0].vm.$emit('update:stepId', 'intro')
		expect(lastManifest(w).walkthrough.tours[1].steps[0].id).toBe('intro')
		heads[0].vm.$emit('move', 1)
		expect(lastManifest(w).walkthrough.tours[1].steps.map((s) => s.id)).toEqual(['s2', 's1'])
		heads[1].vm.$emit('delete')
		expect(lastManifest(w).walkthrough.tours[1].steps.map((s) => s.id)).toEqual(['s1'])
	})

	it('opens the recorder only for an app, adds its pick and closes it', async () => {
		expect(button(factory(TOURS), 'Record from app')).toBeUndefined()

		const w = factory(TOURS, { appSlug: 'my-app', versionSlug: 'v2' })
		await button(w, 'Record from app').trigger('click')
		const recorder = w.findComponent(WalkthroughRecorder)
		expect(recorder.props()).toEqual({ appSlug: 'my-app', versionSlug: 'v2' })

		recorder.vm.$emit('pick', { kind: 'page', ref: '/home' })
		expect(lastManifest(w).walkthrough.tours[0].steps[0].advanceOn).toEqual({ type: 'manual' })
		recorder.vm.$emit('close')
		await w.vm.$nextTick()
		expect(w.findComponent(WalkthroughRecorder).exists()).toBe(false)
	})
})

describe('WalkthroughDesigner — setup fields', () => {
	const SETUP = {
		setup: {
			enabled: true,
			steps: [
				{ id: 'pick', type: 'choice', options: [{ value: 1, label: 'One' }, { label: 'Blank' }] },
				{ id: 'act', type: 'run-action', action: 'seed' },
				{ id: 'comp', type: 'component', component: 'X' },
				{ id: 'done', type: 'summary' },
			],
		},
	}

	function setupFactory() {
		return factory(SETUP, {}, { query: { mode: 'setup' } })
	}

	it('opens on the setup editor when the route asks for it', () => {
		expect(setupFactory().vm.mode).toBe('setup')
		expect(factory(SETUP).vm.mode).toBe('walkthrough')
	})

	it('writes each setup field on its own step', () => {
		const w = setupFactory()

		const edits = [
			[NcSelect, 'Type', 0, 0, 'type', 'info'],
			[NcTextField, 'Title', 1, 1, 'title', 'Run it'],
			[NcTextField, 'Body', 2, 2, 'body', 'Body text'],
			[NcTextField, 'Config key', 0, 0, 'configKey', 'colour'],
			[NcTextField, 'Action id', 0, 1, 'action', 'reseed'],
			[NcTextField, 'Component', 0, 2, 'component', 'Y'],
		]
		for (const [kind, label, nth, si, key, value] of edits) {
			control(w, kind, label, nth).vm.$emit('update:modelValue', value)
			expect(lastManifest(w).setup.steps[si][key]).toBe(value)
		}
	})

	it('shows an option value as text and edits, adds and removes options', async () => {
		const w = setupFactory()

		expect(w.findAllComponents(NcTextField).filter((c) => c.props('label') === 'Value').map((c) => c.props('modelValue'))).toEqual(['1', ''])
		control(w, NcTextField, 'Value', 1).vm.$emit('update:modelValue', 'b')
		expect(lastManifest(w).setup.steps[0].options[1]).toEqual({ label: 'Blank', value: 'b' })
		control(w, NcTextField, 'Label', 0).vm.$emit('update:modelValue', 'Uno')
		expect(lastManifest(w).setup.steps[0].options[0]).toEqual({ value: 1, label: 'Uno' })

		await w.find('button[aria-label="Add option"]').trigger('click')
		expect(lastManifest(w).setup.steps[0].options).toHaveLength(3)
		await w.findAll('button[aria-label="Remove option"]')[0].trigger('click')
		expect(lastManifest(w).setup.steps[0].options).toEqual([{ label: 'Blank' }])
	})

	it('writes the setup switches, only where the step type has them', () => {
		const w = setupFactory()

		expect(w.findAllComponents(NcCheckboxRadioSwitch).filter((c) => c.text() === 'Multi-select')).toHaveLength(1)
		expect(w.findAllComponents(NcCheckboxRadioSwitch).filter((c) => c.text() === 'Health recap')).toHaveLength(1)

		w.findAllComponents(NcCheckboxRadioSwitch).filter((c) => c.text() === 'Required (gates the app)')[1].vm.$emit('update:modelValue', true)
		expect(lastManifest(w).setup.steps[1].required).toBe(true)
		toggle(w, 'Multi-select').vm.$emit('update:modelValue', true)
		expect(lastManifest(w).setup.steps[0].multiple).toBe(true)
		toggle(w, 'Health recap').vm.$emit('update:modelValue', true)
		expect(lastManifest(w).setup.steps[3].healthCheck).toBe(true)
		toggle(w, 'Enabled').vm.$emit('update:modelValue', false)
		expect(lastManifest(w).setup.enabled).toBe(false)
	})

	it('reaches the setup step head events and the add button', async () => {
		const w = setupFactory()

		const heads = w.findAllComponents(StepHeadRow)
		heads[1].vm.$emit('update:stepId', 'action')
		expect(lastManifest(w).setup.steps[1].id).toBe('action')
		heads[1].vm.$emit('move', -1)
		expect(lastManifest(w).setup.steps.map((s) => s.id)).toEqual(['act', 'pick', 'comp', 'done'])
		heads[3].vm.$emit('delete')
		expect(lastManifest(w).setup.steps.map((s) => s.id)).toEqual(['pick', 'act', 'comp'])

		const adds = w.findAll('button').filter((b) => b.text() === 'Add step')
		await adds[adds.length - 1].trigger('click')
		expect(lastManifest(w).setup.steps[4]).toEqual({ id: 'step-5', type: 'info', required: false })
	})
})

describe('WalkthroughDesigner — validation', () => {
	it('lists only the active editor\'s errors and blocks saving while there are any', async () => {
		validation.errors = ['walkthrough.tours[0].trigger: unknown trigger', 'setup.steps[0].type: unknown type', 42]
		const w = factory(TOURS)

		expect(w.vm.errors).toEqual(['walkthrough.tours[0].trigger: unknown trigger'])
		expect(w.findAll('.wt-designer__errors li').map((li) => li.text())).toEqual(['walkthrough.tours[0].trigger: unknown trigger'])
		expect(w.vm.valid).toBe(false)

		w.vm.mode = 'setup'
		await w.vm.$nextTick()
		expect(w.findAll('.wt-designer__errors li').map((li) => li.text())).toEqual(['setup.steps[0].type: unknown type'])
	})

	it('shows no error card when nothing in the active editor is wrong', () => {
		validation.errors = ['setup.steps[0].type: unknown type']
		const w = factory(TOURS)

		expect(w.vm.valid).toBe(true)
		expect(w.find('.note').exists()).toBe(false)
	})
})
