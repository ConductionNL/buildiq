/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for MapPageEditor (REQ-PEC-003).
 *
 * Covers:
 *  - Mounting with the REQ-PEC-002 default config renders centre/zoom
 *    pre-filled.
 *  - Editing zoom emits update:config preserving an unsurfaced key
 *    (REQ-PEC-007 "Unsurfaced config keys survive a form edit").
 *  - Switching the marker branch to register+schema clears
 *    markers.dataSource.url and shows the reserved-shape hint
 *    (REQ-PEC-007 "Map marker-source branches are mutually exclusive").
 *  - validatedConfigKeys equals the five surfaced keys.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const fetchRegisters = vi.fn(async () => [
	{ slug: 'openbuild-hello-world', title: 'Hello World' },
])
const fetchSchemas = vi.fn(async () => [{ slug: 'locations', title: 'Locations' }])
const fetchSchemaProperties = vi.fn(async () => ({
	lat: { type: 'number' },
	lng: { type: 'number' },
}))

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters,
		fetchSchemas,
		fetchSchemaProperties,
		resolveAppRegister: () => '',
	}),
}))

const MapPageEditor = (
	await import('../../../src/components/page-editor/MapPageEditor.vue')
).default

function mountEditor(config = {}) {
	return mount(MapPageEditor, { propsData: { config, appSlug: 'hello-world' } })
}

describe('MapPageEditor', () => {
	beforeEach(() => {
		fetchRegisters.mockClear()
		fetchSchemas.mockClear()
		fetchSchemaProperties.mockClear()
	})

	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Map page')
	})

	it('mounting with the pinned default config renders centre + zoom pre-filled', () => {
		const wrapper = mountEditor({
			center: [52.1326, 5.2913],
			zoom: 7,
			layers: [],
			markers: {},
		})
		expect(wrapper.vm.centerLat).toBe(52.1326)
		expect(wrapper.vm.centerLng).toBe(5.2913)
		expect(wrapper.find('input[type="number"]').element.value).toBeTruthy()
		expect(wrapper.vm.config.zoom).toBe(7)
	})

	it('calls fetchRegisters on mount', async () => {
		mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchRegisters).toHaveBeenCalled()
	})

	it('editing zoom emits update:config preserving an unsurfaced key', async () => {
		const wrapper = mountEditor({
			center: [1, 2],
			zoom: 7,
			attributionPosition: 'bottomleft',
		})
		wrapper.vm.updateZoom('10')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.zoom).toBe(10)
		expect(next.attributionPosition).toBe('bottomleft')
	})

	it('updateCenterPart writes lat/lng as a two-number array', async () => {
		const wrapper = mountEditor({ center: [1, 2] })
		wrapper.vm.updateCenterPart(0, '10.5')
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:config')[0][0]
		expect(next.center).toEqual([10.5, 2])
		// Propagate the emitted config back as the controlled prop before the
		// next edit, mirroring how PageDesigner echoes update:config.
		await wrapper.setProps({ config: next })
		wrapper.vm.updateCenterPart(1, '20.25')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config')[1][0]
		expect(next.center).toEqual([10.5, 20.25])
	})

	it('defaults to the URL marker-source shape', () => {
		expect(mountEditor({}).vm.markerSourceShape).toBe('url')
	})

	it('a config with only dataSource.register reports the register shape', () => {
		const wrapper = mountEditor({ markers: { dataSource: { register: 'r' } } })
		expect(wrapper.vm.markerSourceShape).toBe('register')
	})

	it('switching the marker branch to register+schema clears dataSource.url and shows the reserved-shape hint', async () => {
		const wrapper = mountEditor({
			markers: { dataSource: { url: 'https://example.test/markers.json' } },
		})
		wrapper.vm.setMarkerSourceShape('register')
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:config')[0][0]
		// The only dataSource key was `url`; clearing it leaves dataSource
		// (and thus markers) with no residue.
		expect((next.markers && next.markers.dataSource) || {}).not.toHaveProperty(
			'url',
		)
		await wrapper.setProps({ config: next })

		wrapper.vm.updateMarkerDataSourceRegister('openbuild-hello-world')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.markers.dataSource.register).toBe('openbuild-hello-world')
		expect(next.markers.dataSource).not.toHaveProperty('url')

		await wrapper.setProps({ config: next })
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain(
			'Renderer support for register-bound markers is pending',
		)
	})

	it('setting a marker source URL clears dataSource.register/schema (mutex)', async () => {
		const wrapper = mountEditor({
			markers: { dataSource: { register: 'r', schema: 's' } },
		})
		wrapper.vm.setMarkerSourceShape('url')
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:config')[0][0]
		expect((next.markers && next.markers.dataSource) || {}).not.toHaveProperty(
			'register',
		)
		expect((next.markers && next.markers.dataSource) || {}).not.toHaveProperty(
			'schema',
		)
		await wrapper.setProps({ config: next })

		wrapper.vm.updateMarkerDataSourceField('url', 'https://example.test/x.json')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		expect(next.markers.dataSource.url).toBe('https://example.test/x.json')
		expect(next.markers.dataSource).not.toHaveProperty('register')
		expect(next.markers.dataSource).not.toHaveProperty('schema')
	})

	it('layers row-list: add/update/remove round-trip', async () => {
		const wrapper = mountEditor({ layers: [] })
		wrapper.vm.addLayer()
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:config')[0][0]
		expect(next.layers).toEqual([{ type: 'tile', url: '' }])

		wrapper.vm.updateLayerField(
			0,
			'url',
			'https://tiles.example.test/{z}/{x}/{y}.png',
		)
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config')[1][0]
		expect(next.layers[0].url).toBe('https://tiles.example.test/{z}/{x}/{y}.png')

		wrapper.vm.removeLayer(0)
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config')[2][0]
		expect(next).not.toHaveProperty('layers')
	})

	it('validatedConfigKeys equals the five surfaced keys', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual([
			'center',
			'zoom',
			'height',
			'layers',
			'markers',
		])
	})

	it('preserves unsurfaced config keys on a markers field edit (lossless round-trip)', async () => {
		const wrapper = mountEditor({ markers: {}, extraThing: { keep: true } })
		wrapper.vm.updateMarkerField('clustering', true)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.extraThing).toEqual({ keep: true })
		expect(next.markers.clustering).toBe(true)
	})
})
