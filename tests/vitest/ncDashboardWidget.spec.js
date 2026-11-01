/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The browser half of a promoted virtual-app widget.
 *
 * Two things are asserted here, and the second is the load-bearing one: a
 * widget key the runtime registry does not hold must be visible as UNKNOWN,
 * naming the key. A blank panel reads as "this widget has nothing to show" and
 * sends nobody looking for the key that is missing.
 */

import { registerDashboardWidget } from '@conduction/nextcloud-vue'
import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import {
	createNcDashboardWidget,
	registerNcDashboardWidgets,
	resolveWidgetComponent,
} from '../../src/ncDashboard.js'

const KNOWN_KEY = 'spec-open-cases-tile'
const UNKNOWN_KEY = 'spec-key-that-resolves-to-nothing'

const KnownWidget = {
	name: 'SpecKnownWidget',
	props: { content: { type: Object, default: () => ({}) } },
	render() {
		return h('p', { class: 'spec-known-widget' }, 'rendered by the registry')
	},
}

/**
 * A promoted descriptor, as VirtualAppWidget provides it in initial state.
 *
 * @param {object} overrides Fields to override.
 * @return {object} The descriptor.
 */
function descriptor(overrides = {}) {
	return {
		id: 'buildiq-11111111-2222-3333-4444-555555555555-open-cases',
		applicationSlug: 'pet-store',
		applicationName: 'Pet Store',
		entryId: 'open-cases',
		widgetKey: KNOWN_KEY,
		pageRoute: '/overview',
		props: { label: 'Open cases' },
		dataSource: { register: 'r', schema: 's' },
		title: 'Open cases',
		icon: 'clipboard',
		order: 12,
		link: '',
		...overrides,
	}
}

describe('ncDashboard entry', () => {
	beforeAll(() => {
		registerDashboardWidget(KNOWN_KEY, {
			renderer: KnownWidget,
			form: null,
			defaultContent: {},
			displayName: 'Spec open cases tile',
			icon: 'Clipboard',
		})
	})

	it('renders a promoted widget in the Nextcloud panel chrome', () => {
		const wrapper = mount(createNcDashboardWidget(descriptor(), {}))

		const panel = wrapper.find('.cn-widget-wrapper-stub')
		expect(panel.exists()).toBe(true)
		expect(panel.attributes('data-chrome')).toBe('nc-dashboard')
		// Nextcloud already draws the panel header from IWidget::getTitle();
		// a second title inside the body gives the panel two.
		expect(panel.attributes('data-show-title')).toBe('false')
		expect(wrapper.find('.spec-known-widget').exists()).toBe(true)
	})

	it('shows the unknown-widget state naming an unresolvable key', () => {
		const wrapper = mount(
			createNcDashboardWidget(descriptor({ widgetKey: UNKNOWN_KEY }), {}),
		)

		expect(wrapper.find('.spec-known-widget').exists()).toBe(false)
		expect(wrapper.text()).toContain(UNKNOWN_KEY)
		expect(wrapper.text()).not.toBe('')
	})

	it('prefers a consumer registry override over the built-in catalog', () => {
		const Override = {
			name: 'SpecOverrideWidget',
			render: () => h('p', { class: 'spec-override-widget' }, 'override'),
		}

		expect(resolveWidgetComponent(descriptor(), {})).toBe(KnownWidget)
		expect(resolveWidgetComponent(descriptor(), { [KNOWN_KEY]: Override })).toBe(
			Override,
		)
	})

	it('registers one dashboard widget per descriptor, and skips a descriptor with no id', () => {
		const register = vi.fn()
		const registered = registerNcDashboardWidgets(
			[
				descriptor(),
				descriptor({
					id: 'buildiq-11111111-2222-3333-4444-555555555555-recent',
				}),
				descriptor({ id: '' }),
			],
			{ register },
		)

		expect(registered).toBe(2)
		expect(register).toHaveBeenCalledTimes(2)
		expect(register.mock.calls.map((call) => call[0])).toEqual([
			'buildiq-11111111-2222-3333-4444-555555555555-open-cases',
			'buildiq-11111111-2222-3333-4444-555555555555-recent',
		])
	})

	it('registers nothing when Nextcloud has no dashboard registry', () => {
		expect(registerNcDashboardWidgets([descriptor()], undefined)).toBe(0)
	})
})
