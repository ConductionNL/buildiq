/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `src/views/FeaturesRoadmap.vue`: the page hands the repository, the feature
 * list and the disabled flag from initial state to the library view, with
 * fallbacks when the server provides none.
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ values: {}, calls: [] }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		state.calls.push([app, key])
		return Object.hasOwn(state.values, key) ? state.values[key] : fallback
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFeaturesAndRoadmapView: { name: 'CnFeaturesAndRoadmapView', props: ['repo', 'features', 'disabled'], template: '<div />' },
}))

import FeaturesRoadmap from '../../../src/views/FeaturesRoadmap.vue'

beforeEach(() => {
	state.values = {}
	state.calls = []
})

describe('FeaturesRoadmap', () => {
	it('falls back to the buildiq repository, no features and enabled', () => {
		const view = shallowMount(FeaturesRoadmap).findComponent({ name: 'CnFeaturesAndRoadmapView' })

		expect(view.props()).toEqual({ repo: 'ConductionNL/buildiq', features: [], disabled: false })
		expect(state.calls).toEqual([
			['buildiq', 'features_roadmap_repo'],
			['buildiq', 'features_roadmap_features'],
			['buildiq', 'features_roadmap_disabled'],
		])
	})

	it('passes what the server provides', () => {
		state.values = {
			features_roadmap_repo: 'ConductionNL/other',
			features_roadmap_features: [{ id: 'f1', title: 'Export' }],
			features_roadmap_disabled: true,
		}

		const view = shallowMount(FeaturesRoadmap).findComponent({ name: 'CnFeaturesAndRoadmapView' })

		expect(view.props()).toEqual({ repo: 'ConductionNL/other', features: [{ id: 'f1', title: 'Export' }], disabled: true })
	})
})
