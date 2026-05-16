/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard/Step3Custom.vue.
 *
 * Covers spec openbuilt-app-creation-wizard task 6.4:
 *   - seeds 1 Production row when payload.versions is empty
 *   - seeds rows from payload.versions when present
 *   - name input auto-derives slug via toKebabCase
 *   - add row button appends an empty row
 *   - remove button removes the row; min-1 enforcement when only 1 row
 *   - moveUp / moveDown reorder rows correctly
 *   - duplicate slug detection marks both rows
 *   - isValid false when duplicate slugs exist
 *   - isValid false when a row slug is invalid
 *   - isValid true when all rows are valid and unique
 *   - _step3Valid + versions emitted on every change
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Step3Custom from '../../../src/dialogs/CreateApplicationWizard/Step3Custom.vue'

/**
 * Build a default payload.
 *
 * @param {object} overrides
 * @return {object}
 */
function makePayload(overrides = {}) {
	return {
		preset: 'custom',
		versions: [],
		...overrides,
	}
}

/**
 * Mount helper.
 *
 * @param {object} payloadOverrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountStep3(payloadOverrides = {}) {
	return mount(Step3Custom, {
		propsData: { payload: makePayload(payloadOverrides) },
	})
}

/** Return the latest 'update:payload' emit. */
function lastEmit(wrapper) {
	const emitted = wrapper.emitted('update:payload')
	if (!emitted || emitted.length === 0) return null
	return emitted[emitted.length - 1][0]
}

describe('Step3Custom.vue — spec task 6.4', () => {

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	it('seeds a single Production row when versions is empty', () => {
		const wrapper = mountStep3({ versions: [] })
		expect(wrapper.vm.localVersions).toHaveLength(1)
		expect(wrapper.vm.localVersions[0].slug).toBe('production')
	})

	it('seeds rows from payload.versions when present', () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		expect(wrapper.vm.localVersions).toHaveLength(2)
		expect(wrapper.vm.localVersions[0].slug).toBe('development')
	})

	// -------------------------------------------------------------------------
	// Name → slug auto-derivation
	// -------------------------------------------------------------------------

	it('typing a name auto-derives the slug when not manually overridden', async () => {
		const wrapper = mountStep3()
		const nameInput = wrapper.find('#wizard-version-name-0')
		await nameInput.setValue('My Version')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.localVersions[0].name).toBe('My Version')
		expect(wrapper.vm.localVersions[0].slug).toBe('my-version')
	})

	it('manual slug edit blocks auto-derivation', async () => {
		const wrapper = mountStep3()
		// Mark row 0 as manually edited
		wrapper.vm.localVersions[0]._slugManual = true
		wrapper.vm.localVersions[0].slug = 'custom-slug'

		const nameInput = wrapper.find('#wizard-version-name-0')
		await nameInput.setValue('New Name')
		await wrapper.vm.$nextTick()

		// Slug should not change
		expect(wrapper.vm.localVersions[0].slug).toBe('custom-slug')
	})

	// -------------------------------------------------------------------------
	// Add / remove rows
	// -------------------------------------------------------------------------

	it('clicking Add version appends an empty row', async () => {
		const wrapper = mountStep3()
		const countBefore = wrapper.vm.localVersions.length
		await wrapper.find('.wizard-step3__add-btn').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localVersions.length).toBe(countBefore + 1)
		const newRow = wrapper.vm.localVersions[wrapper.vm.localVersions.length - 1]
		expect(newRow.name).toBe('')
	})

	it('clicking Remove on the only row shows minRowError instead of removing', async () => {
		const wrapper = mountStep3()
		expect(wrapper.vm.localVersions).toHaveLength(1)
		await wrapper.find('.wizard-step3__btn-remove').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localVersions).toHaveLength(1)
		expect(wrapper.vm.minRowError).toBeTruthy()
	})

	it('clicking Remove when 2+ rows removes the row', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		const removeButtons = wrapper.findAll('.wizard-step3__btn-remove')
		await removeButtons.at(0).trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localVersions).toHaveLength(1)
		expect(wrapper.vm.localVersions[0].slug).toBe('production')
	})

	// -------------------------------------------------------------------------
	// Move up / down
	// -------------------------------------------------------------------------

	it('moveUp swaps the row with the previous one', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Staging', slug: 'staging' },
				{ name: 'Production', slug: 'production' },
			],
		})
		wrapper.vm.moveUp(1) // move Staging up
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.localVersions[0].slug).toBe('staging')
		expect(wrapper.vm.localVersions[1].slug).toBe('development')
	})

	it('moveDown swaps the row with the next one', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Staging', slug: 'staging' },
				{ name: 'Production', slug: 'production' },
			],
		})
		wrapper.vm.moveDown(0) // move Development down
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.localVersions[0].slug).toBe('staging')
		expect(wrapper.vm.localVersions[1].slug).toBe('development')
	})

	it('moveUp on first row is a no-op', () => {
		const wrapper = mountStep3()
		const original = wrapper.vm.localVersions[0].slug
		wrapper.vm.moveUp(0)
		expect(wrapper.vm.localVersions[0].slug).toBe(original)
	})

	it('moveDown on last row is a no-op', () => {
		const wrapper = mountStep3()
		const original = wrapper.vm.localVersions[0].slug
		wrapper.vm.moveDown(0)
		expect(wrapper.vm.localVersions[0].slug).toBe(original)
	})

	// -------------------------------------------------------------------------
	// Duplicate detection
	// -------------------------------------------------------------------------

	it('isDuplicate returns true for both rows with same slug', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'A', slug: 'staging' },
				{ name: 'B', slug: 'staging' },
			],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isDuplicate(0)).toBe(true)
		expect(wrapper.vm.isDuplicate(1)).toBe(true)
	})

	it('isDuplicate is false for unique slugs', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isDuplicate(0)).toBe(false)
		expect(wrapper.vm.isDuplicate(1)).toBe(false)
	})

	it('duplicate check is case-insensitive', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'A', slug: 'Staging' },
				{ name: 'B', slug: 'staging' },
			],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.duplicateSlugs.size).toBe(2)
	})

	it('duplicate slug chip gets --duplicate class', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'A', slug: 'staging' },
				{ name: 'B', slug: 'staging' },
			],
		})
		await wrapper.vm.$nextTick()
		const chips = wrapper.findAll('.wizard-step3__slug-chip--duplicate')
		expect(chips.length).toBeGreaterThanOrEqual(1)
	})

	// -------------------------------------------------------------------------
	// isValid
	// -------------------------------------------------------------------------

	it('isValid is false when duplicates exist', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'A', slug: 'staging' },
				{ name: 'B', slug: 'staging' },
			],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is false when a slug has invalid chars', async () => {
		const wrapper = mountStep3({
			versions: [{ name: 'A', slug: 'bad slug!' }],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is true when all slugs are valid and unique', async () => {
		const wrapper = mountStep3({
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isValid).toBe(true)
	})

	it('isValid is false for empty localVersions', async () => {
		const wrapper = mountStep3({ versions: [{ name: 'Prod', slug: 'prod' }] })
		// Remove the row so array is empty
		wrapper.vm.localVersions.splice(0, 1)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.isValid).toBe(false)
	})

	// -------------------------------------------------------------------------
	// Emit contract
	// -------------------------------------------------------------------------

	it('emits _step3Valid and versions on mount', () => {
		const wrapper = mountStep3({
			versions: [{ name: 'Production', slug: 'production' }],
		})
		const emitted = wrapper.emitted('update:payload')
		expect(emitted).toBeTruthy()
		const first = emitted[0][0]
		expect('_step3Valid' in first).toBe(true)
		expect(Array.isArray(first.versions)).toBe(true)
	})

	it('emitted versions contain only name + slug (no internal fields)', async () => {
		const wrapper = mountStep3({
			versions: [{ name: 'Production', slug: 'production' }],
		})
		await wrapper.vm.$nextTick()
		const e = lastEmit(wrapper)
		if (e && e.versions) {
			e.versions.forEach(v => {
				expect(Object.keys(v)).toEqual(expect.arrayContaining(['name', 'slug']))
				expect('_id' in v).toBe(false)
				expect('_slugManual' in v).toBe(false)
			})
		}
	})
})
