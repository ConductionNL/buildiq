/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * StoriesWidget (ai-copilot-documents-and-code-help REQ-BQCH-007): one row per
 * well-formed story, each with the pages and schemas that serve it.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StoriesWidget from '../../../src/components/applicationDetail/widgets/StoriesWidget.vue'

const stories = [
	{ id: 's1', role: 'an applicant', goal: 'track my permit', servedBy: [{ kind: 'page', id: 'mine', title: 'Mijn aanvragen' }, { kind: 'schema', id: 'aanvraag' }] },
	{ id: 's2', role: 'a reviewer', goal: 'decide on a request', benefit: 'applicants hear back', servedBy: [{ kind: 'menu', id: 'x' }] },
	{ id: 's3', role: 'a clerk', goal: 'see every request', servedBy: [] },
	{ id: 's4', role: 'nobody' },
]

describe('StoriesWidget', () => {
	it('lists the well-formed stories with what serves each', () => {
		const wrapper = mount(StoriesWidget, { props: { stories } })
		const rows = wrapper.findAll('[data-testid="stories-widget-story"]')

		expect(rows).toHaveLength(3)
		expect(rows[0].find('.ob-stories-widget__sentence').text()).toBe('As {role} I want to {goal}')
		expect(rows[0].findAll('.ob-stories-widget__served-by li').map((li) => li.text())).toEqual(['Page Mijn aanvragen', 'Schema aanvraag'])
		expect(rows[1].find('.ob-stories-widget__sentence').text()).toBe('As {role} I want to {goal}, so that {benefit}')
		expect(rows[1].find('.ob-stories-widget__served-by').exists()).toBe(false)
	})

	it('says so when the app has no stories', () => {
		const wrapper = mount(StoriesWidget, { props: { stories: [] } })
		expect(wrapper.find('.ob-stories-widget__empty').text()).toBe('This app has no user stories.')
		expect(wrapper.findAll('[data-testid="stories-widget-story"]')).toHaveLength(0)
	})

	it('reads a missing stories value as none', () => {
		const wrapper = mount(StoriesWidget, { props: { stories: null } })
		expect(wrapper.find('.ob-stories-widget__empty').exists()).toBe(true)
	})
})
