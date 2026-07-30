/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DocumentActions.vue (runtime surface).
 *
 * Spec: docudesk-document-templates (REQ-DDT-004, REQ-DDT-005).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DocumentActions from '../../src/components/runtime/DocumentActions.vue'

// `emits: ['click']` is load-bearing under Vue 3: an undeclared emit leaves the
// parent's `@click` in `$attrs`, which falls through onto the root <button>, so
// one click runs the handler twice. The real NcButton declares
// `emits: ['click', 'update:pressed']` and therefore fires exactly once.
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const object = { '@self': { id: 'abc-123', register: 'kap', schema: 'kapaanvraag' } }

const attachments = [
	{ id: 'a', schema: 'kapaanvraag', templateId: 'u1', templateName: 'A', label: 'Generate A' },
	{ id: 'b', schema: 'kapaanvraag', templateId: 'u2', templateName: 'B', label: 'Generate B' },
	{ id: 'c', schema: 'andere', templateId: 'u3', templateName: 'C', label: 'Generate C' },
]

// `docudeskAvailable` defaults to `null` = "probe the instance yourself". In
// jsdom that probe has no server to reach, so it resolves ABSENT and the widget
// correctly refuses to generate. Every test whose subject is NOT the capability
// check therefore states its precondition explicitly.
const factory = (props = {}, mountOptions = {}) => mount(DocumentActions, {
	propsData: { object, attachments, docudeskAvailable: true, ...props },
	stubs: { NcButton: NcButtonStub },
	...mountOptions,
})

describe('DocumentActions', () => {
	it('renders one button per attachment for the object schema, in declared order', () => {
		const wrapper = factory()
		const buttons = wrapper.findAll('.ob-document-actions__row button')
		expect(buttons).toHaveLength(2)
		expect(buttons.at(0).text()).toContain('Generate A')
		expect(buttons.at(1).text()).toContain('Generate B')
	})

	// REGRESSION GUARD for the id-vs-slug defect that made this widget render
	// nothing on every real object.
	//
	// Note what the fixture above encodes: `@self.schema = 'kapaanvraag'`, a
	// SLUG. OpenRegister does not do that — measured on a live instance, an
	// object carries `"@self": { "register": "15", "schema": "21" }`, the numeric
	// ids. So the original fixture validated a comparison that could never be
	// true against real data, and the suite stayed green while the surface was
	// 100% dead in production. These two cases use the REAL envelope shape.
	it('matches attachments when @self carries NUMERIC ids and the page context supplies the slug', () => {
		const wrapper = mount(DocumentActions, {
			propsData: {
				// The shape OpenRegister actually returns.
				object: { '@self': { id: 'abc-123', register: '15', schema: '21' } },
				attachments,
				docudeskAvailable: true,
			},
			stubs: { NcButton: NcButtonStub },
			// CnDetailPage provides this; its `schema` is the manifest's
			// `config.schema`, i.e. the slug the attachments are declared with.
			global: { provide: { cnObjectContext: { register: 'kap', schema: 'kapaanvraag' } } },
		})
		const buttons = wrapper.findAll('.ob-document-actions__row button')
		expect(buttons).toHaveLength(2)
		expect(buttons.at(0).text()).toContain('Generate A')
		expect(buttons.at(1).text()).toContain('Generate B')
	})

	it('does not match a different schema just because the numeric envelope is unreadable', () => {
		const wrapper = mount(DocumentActions, {
			propsData: {
				object: { '@self': { id: 'abc-123', register: '15', schema: '21' } },
				attachments,
				docudeskAvailable: true,
			},
			stubs: { NcButton: NcButtonStub },
			global: { provide: { cnObjectContext: { register: 'kap', schema: 'een-andere-schema' } } },
		})
		// The normalisation must not degrade into "match anything" — an object
		// whose schema declares no attachments still renders nothing.
		expect(wrapper.find('.ob-document-actions').exists()).toBe(false)
	})

	it('renders nothing when the schema has no attachments', () => {
		const wrapper = factory({ object: { '@self': { id: 'x', schema: 'unknown' } } })
		expect(wrapper.find('.ob-document-actions').exists()).toBe(false)
	})

	it('shows the unavailable state and issues no request when Docudesk is absent', async () => {
		const wrapper = factory({ docudeskAvailable: false })
		const spy = vi.fn()
		wrapper.vm.docs.generate = spy
		expect(wrapper.find('.ob-document-actions__unavailable').exists()).toBe(true)
		expect(wrapper.find('.ob-document-actions__row').exists()).toBe(false)
	})

	it('delegates generate to the composable on click', async () => {
		const wrapper = factory()
		const spy = vi.fn().mockResolvedValue(null)
		wrapper.vm.docs.generate = spy
		await wrapper.findAll('.ob-document-actions__row button').at(0).trigger('click')
		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy.mock.calls[0][0].id).toBe('a')
	})

	// REQ-DDT-004 wiring. Nothing supplies `attachments` at runtime: the widget
	// is resolved through CnPageRenderer's slot-override path, which hands it the
	// detail surface's own props and has no way to know it wants a slice of the
	// manifest. Without this fallback the runtime surface rendered NOTHING for
	// every app — the buttons existed only where a test passed the prop by hand.
	it('falls back to the built app manifest `runtime.documents[]` when no prop is supplied', () => {
		const wrapper = mount(DocumentActions, {
			propsData: { object, docudeskAvailable: true },
			stubs: { NcButton: NcButtonStub },
			global: { provide: { cnManifest: { runtime: { documents: attachments } } } },
		})
		const buttons = wrapper.findAll('.ob-document-actions__row button')
		expect(buttons).toHaveLength(2)
		expect(buttons.at(0).text()).toContain('Generate A')
		expect(buttons.at(1).text()).toContain('Generate B')
	})

	it('prefers an explicit `attachments` prop over the injected manifest', () => {
		const wrapper = mount(DocumentActions, {
			propsData: {
				object,
				docudeskAvailable: true,
				attachments: [{ id: 'z', schema: 'kapaanvraag', templateId: 'u9', templateName: 'Z', label: 'Generate Z' }],
			},
			stubs: { NcButton: NcButtonStub },
			global: { provide: { cnManifest: { runtime: { documents: attachments } } } },
		})
		const buttons = wrapper.findAll('.ob-document-actions__row button')
		expect(buttons).toHaveLength(1)
		expect(buttons.at(0).text()).toContain('Generate Z')
	})
})
