/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the builder views mirror `useApplicationVersion` into their own
 * state: the first read, the version followed while loading, the loading flag
 * and error copied when loading ends, and nothing followed after that.
 * Each view's version-resolving code runs against a reactive stand-in for the
 * component, so no view has to be mounted.
 */
import { describe, expect, it, vi } from 'vitest'
import { nextTick, reactive, ref, watch } from 'vue'

const state = vi.hoisted(() => ({ refs: null, calls: [] }))

vi.mock('../../src/composables/useApplicationVersion.js', async (importOriginal) => ({
	...(await importOriginal()),
	useApplicationVersion: (...args) => {
		state.calls.push(args)
		return state.refs
	},
}))

import BuilderHost from '../../src/views/BuilderHost.vue'
import PageDesigner from '../../src/views/PageDesigner.vue'
import PageDesignerHost from '../../src/views/PageDesignerHost.vue'
import SchemaDesigner from '../../src/views/SchemaDesigner.vue'

/**
 * @return {object} a reactive stand-in for the component.
 */
function fakeVm() {
	const vm = reactive({
		slug: 'app',
		routeSlug: 'app',
		appSlug: 'app',
		versionSlug: 'staging',
		$route: { query: { _version: 'staging' } },
		applicationVersion: 'unset',
		versionLoading: 'unset',
		versionError: 'unset',
		onKeydown: () => {},
		observePreviewSurface: () => {},
	})
	vm.$watch = (getter, cb) => watch(getter, cb)
	return vm
}

/**
 * @return {object} the controlled refs plus a way to settle `ready`.
 */
function versionRefs() {
	let settle
	const ready = new Promise((resolve) => { settle = resolve })
	return {
		applicationVersion: ref({ id: 'v0' }),
		loading: ref(true),
		error: ref(null),
		ready,
		settle: () => settle(),
	}
}

const VIEWS = [
	['BuilderHost', (vm) => BuilderHost.methods.resolveVersion.call(vm)],
	['PageDesignerHost', (vm) => PageDesignerHost.methods.resolveVersion.call(vm)],
	['PageDesigner', (vm) => PageDesigner.mounted.call(vm)],
	['SchemaDesigner', (vm) => SchemaDesigner.methods.resolveVersion.call(vm)],
]

describe.each(VIEWS)('%s version mirror', (_name, run) => {
	it('follows the version until loading ends, then stops', async () => {
		state.refs = versionRefs()
		state.calls = []
		const vm = fakeVm()
		const pending = run(vm)
		const seen = [['first', vm.applicationVersion, vm.versionLoading, vm.versionError]]

		state.refs.applicationVersion.value = { id: 'v1' }
		await nextTick()
		seen.push(['followed', vm.applicationVersion, vm.versionLoading, vm.versionError])

		state.refs.error.value = 'gone'
		state.refs.loading.value = false
		await nextTick()
		seen.push(['ended', vm.applicationVersion, vm.versionLoading, vm.versionError])

		state.refs.applicationVersion.value = { id: 'v2' }
		state.refs.loading.value = true
		await nextTick()
		seen.push(['after', vm.applicationVersion, vm.versionLoading, vm.versionError])

		state.refs.settle()
		await pending
		await nextTick()
		seen.push(['settled', vm.applicationVersion, vm.versionLoading, vm.versionError])

		expect({ calls: state.calls, seen }).toMatchSnapshot()
	})
})
