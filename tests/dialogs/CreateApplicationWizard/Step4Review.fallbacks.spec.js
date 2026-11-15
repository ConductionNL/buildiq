/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the fallbacks in the review summary of
 * src/dialogs/CreateApplicationWizard/Step4Review.vue:
 *   - a missing name or slug shows '—'
 *   - a version without a slug is named by its name, then '?', in the chain
 *   - the production slug falls back to the last version's name, then '—'
 *   - a non-array versions value counts as no versions
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Step4Review from '../../../src/dialogs/CreateApplicationWizard/Step4Review.vue'

/**
 * Mount the review step with the given payload.
 *
 * @param {object} payload The wizard payload.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountStep4(payload) {
	return mount(Step4Review, {
		propsData: {
			payload: { name: 'App', slug: 'app', description: '', preset: 'single', versions: [], ...payload },
		},
	})
}

describe('Step4Review — fallbacks', () => {
	it('shows a dash for a missing name and slug', () => {
		const wrapper = mountStep4({ name: '', slug: '' })
		const values = wrapper.findAll('.wizard-step4__row dd').map((dd) => dd.text())

		expect(values[0]).toBe('—')
		expect(values[1]).toBe('—')
	})

	it('names each version in the chain by slug, then name, then "?"', () => {
		const wrapper = mountStep4({
			versions: [{ slug: 'dev', name: 'Development' }, { name: 'Staging' }, {}],
		})

		expect(wrapper.vm.chainDisplay).toBe('dev → Staging → ?')
		expect(wrapper.text()).toContain('dev → Staging → ?')
	})

	it('takes the production slug from the last version, falling back to its name', () => {
		expect(mountStep4({ versions: [{ slug: 'dev' }, { slug: 'prod' }] }).vm.productionSlug).toBe('prod')
		expect(mountStep4({ versions: [{ slug: 'dev' }, { name: 'Live' }] }).vm.productionSlug).toBe('Live')
		expect(mountStep4({ versions: [{ slug: 'dev' }, {}] }).vm.productionSlug).toBe('—')
	})

	it('treats a non-array versions value as no versions', () => {
		const wrapper = mountStep4({ versions: 'production' })

		expect(wrapper.vm.versions).toEqual([])
		expect(wrapper.vm.chainDisplay).toBe('—')
		expect(wrapper.vm.productionSlug).toBe('—')
	})
})
