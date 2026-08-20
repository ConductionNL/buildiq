/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard/Step4Review.vue.
 *
 * Covers spec openbuild-app-creation-wizard task 6.5:
 *   - renders read-only name, slug, description fields
 *   - renders version chain in arrow form (e.g. development → production)
 *   - highlights the terminal/production version in the production callout
 *   - summary matches payload shape for each preset
 *   - icon previews (light + dark) rendered when provided in payload
 *   - icon previews absent when not provided
 *   - empty versions renders '—' chain display
 *   - emits no events itself (the parent wizard Create button calls onSubmit)
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Step4Review from '../../../src/dialogs/CreateApplicationWizard/Step4Review.vue'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal wizard payload for review.
 *
 * @param {object} overrides
 * @return {object}
 */
function makePayload(overrides = {}) {
	return {
		name: 'Hello World',
		slug: 'hello-world',
		description: 'A test application.',
		icon: null,
		iconDark: null,
		preset: 'single',
		versions: [{ name: 'Production', slug: 'production' }],
		_step1Valid: true,
		_step2Valid: true,
		_step3Valid: true,
		...overrides,
	}
}

/**
 * Mount Step4Review with the given payload overrides.
 *
 * @param {object} payloadOverrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountStep4(payloadOverrides = {}) {
	return mount(Step4Review, {
		propsData: { payload: makePayload(payloadOverrides) },
	})
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Step4Review.vue — spec task 6.5', () => {
	// -------------------------------------------------------------------------
	// Read-only summary fields
	// -------------------------------------------------------------------------

	it('renders the app name from payload', () => {
		const wrapper = mountStep4({ name: 'My App', slug: 'my-app' })
		expect(wrapper.text()).toContain('My App')
	})

	it('renders the slug in a <code> element', () => {
		const wrapper = mountStep4({ slug: 'my-app' })
		const codeEl = wrapper.find('code')
		expect(codeEl.exists()).toBe(true)
		expect(codeEl.text()).toContain('my-app')
	})

	it('renders description when present', () => {
		const wrapper = mountStep4({ description: 'Great new app.' })
		expect(wrapper.text()).toContain('Great new app.')
	})

	it('does not render description row when description is empty', () => {
		const wrapper = mountStep4({ description: '' })
		// Should not find the description dd element
		const rows = wrapper.findAll('.wizard-step4__row')
		// VTU v2 `findAll` returns a plain Array — v1's `.wrappers` is gone.
		const rowTexts = rows.map((r) => r.text())
		expect(rowTexts.some((t) => t.includes('Description'))).toBe(false)
	})

	// -------------------------------------------------------------------------
	// Version chain display
	// -------------------------------------------------------------------------

	it('renders single-version chain as just the slug (no arrow)', () => {
		const wrapper = mountStep4({
			preset: 'single',
			versions: [{ name: 'Production', slug: 'production' }],
		})
		const chain = wrapper.find('.wizard-step4__chain')
		expect(chain.exists()).toBe(true)
		expect(chain.text()).toBe('production')
	})

	it('renders dev-prod chain with arrow between versions', () => {
		const wrapper = mountStep4({
			preset: 'dev-prod',
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		const chain = wrapper.find('.wizard-step4__chain')
		expect(chain.text()).toContain('development')
		expect(chain.text()).toContain('→')
		expect(chain.text()).toContain('production')
		expect(chain.text()).toBe('development → production')
	})

	it('renders dev-staging-prod three-tier chain with two arrows', () => {
		const wrapper = mountStep4({
			preset: 'dev-staging-prod',
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Staging', slug: 'staging' },
				{ name: 'Production', slug: 'production' },
			],
		})
		const chain = wrapper.find('.wizard-step4__chain')
		expect(chain.text()).toBe('development → staging → production')
	})

	it('renders custom chain with user-defined slugs and arrows', () => {
		const wrapper = mountStep4({
			preset: 'custom',
			versions: [
				{ name: 'Alpha', slug: 'alpha' },
				{ name: 'Beta', slug: 'beta' },
				{ name: 'Main', slug: 'main' },
			],
		})
		const chain = wrapper.find('.wizard-step4__chain')
		expect(chain.text()).toBe('alpha → beta → main')
	})

	it('renders "—" chain when versions is empty', () => {
		const wrapper = mountStep4({ versions: [] })
		const chain = wrapper.find('.wizard-step4__chain')
		expect(chain.text()).toBe('—')
	})

	// -------------------------------------------------------------------------
	// Terminal/production version callout
	// -------------------------------------------------------------------------

	it('shows the last version slug as production for single preset', () => {
		const wrapper = mountStep4({
			preset: 'single',
			versions: [{ name: 'Production', slug: 'production' }],
		})
		const callout = wrapper.find('.wizard-step4__production-callout')
		expect(callout.exists()).toBe(true)
		expect(callout.text()).toContain('production')
	})

	it('shows the terminal slug (last in chain) as production for dev-prod', () => {
		const wrapper = mountStep4({
			preset: 'dev-prod',
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		const callout = wrapper.find('.wizard-step4__production-callout')
		expect(callout.text()).toContain('production')
		// Should NOT highlight development as production
		expect(wrapper.vm.productionSlug).toBe('production')
	})

	it('shows the last custom version slug as the terminal version', () => {
		const wrapper = mountStep4({
			preset: 'custom',
			versions: [
				{ name: 'Alpha', slug: 'alpha' },
				{ name: 'Beta', slug: 'beta' },
				{ name: 'Main', slug: 'main' },
			],
		})
		expect(wrapper.vm.productionSlug).toBe('main')
		const callout = wrapper.find('.wizard-step4__production-callout')
		expect(callout.text()).toContain('main')
	})

	it('shows "—" production slug when versions is empty', () => {
		const wrapper = mountStep4({ versions: [] })
		expect(wrapper.vm.productionSlug).toBe('—')
	})

	// -------------------------------------------------------------------------
	// Preset payload shapes — summary consistency
	// -------------------------------------------------------------------------

	it('single preset: chainDisplay matches the single production slug', () => {
		const wrapper = mountStep4({
			preset: 'single',
			versions: [{ name: 'Production', slug: 'production' }],
		})
		expect(wrapper.vm.chainDisplay).toBe('production')
		expect(wrapper.vm.productionSlug).toBe('production')
	})

	it('dev-prod preset: chainDisplay is development → production', () => {
		const wrapper = mountStep4({
			preset: 'dev-prod',
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Production', slug: 'production' },
			],
		})
		expect(wrapper.vm.chainDisplay).toBe('development → production')
		expect(wrapper.vm.productionSlug).toBe('production')
	})

	it('dev-staging-prod preset: chainDisplay is development → staging → production', () => {
		const wrapper = mountStep4({
			preset: 'dev-staging-prod',
			versions: [
				{ name: 'Development', slug: 'development' },
				{ name: 'Staging', slug: 'staging' },
				{ name: 'Production', slug: 'production' },
			],
		})
		expect(wrapper.vm.chainDisplay).toBe('development → staging → production')
		expect(wrapper.vm.productionSlug).toBe('production')
	})

	it('custom preset: chainDisplay reflects the custom-ordered chain', () => {
		const wrapper = mountStep4({
			preset: 'custom',
			versions: [
				{ name: 'Alpha', slug: 'alpha' },
				{ name: 'Beta', slug: 'beta' },
				{ name: 'Main', slug: 'main' },
			],
		})
		expect(wrapper.vm.chainDisplay).toBe('alpha → beta → main')
		expect(wrapper.vm.productionSlug).toBe('main')
	})

	// -------------------------------------------------------------------------
	// Icon previews
	// -------------------------------------------------------------------------

	// The wizard no longer carries Blob/File uploads through the payload: the icon
	// picker stores a VALUE (`iconValue` / `iconDarkValue`) — raw SVG, or a glyph
	// key — which Step4Review resolves to SVG markup via `resolveAppIcon`. These
	// tests drive that contract; there is no `URL.createObjectURL` in the path.
	const LIGHT_SVG =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>'
	const DARK_SVG =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>'

	it('does not render icons section when neither icon nor iconDark provided', () => {
		const wrapper = mountStep4({ iconValue: null, iconDarkValue: null })
		expect(wrapper.find('.wizard-step4__icons').exists()).toBe(false)
	})

	// resolveAppIcon now DOMPurify-sanitizes author SVG (harden-xss-dos-csrf),
	// which normalizes markup (e.g. `<path/>` → `<path></path>`), so these assert
	// on the preserved path data rather than a byte-exact match of the raw SVG.
	it('renders icons section when icon (light) is provided', () => {
		const wrapper = mountStep4({ iconValue: LIGHT_SVG, iconDarkValue: null })
		expect(wrapper.find('.wizard-step4__icons').exists()).toBe(true)
		expect(wrapper.find('figure.wizard-step4__icon-preview').exists()).toBe(true)
		expect(wrapper.vm.lightIconSvg).toContain('d="M0 0h24v24H0z"')
	})

	it('falls back to the light icon for dark when no dark icon is chosen', () => {
		// Mirrors what the wizard actually attaches on submit: dark resolves to
		// the same sanitized SVG as light.
		const wrapper = mountStep4({ iconValue: LIGHT_SVG, iconDarkValue: null })
		expect(wrapper.vm.darkIconSvg).toBe(wrapper.vm.lightIconSvg)
	})

	it('renders dark icon preview with --dark modifier class when iconDark provided', () => {
		const wrapper = mountStep4({ iconValue: null, iconDarkValue: DARK_SVG })
		expect(wrapper.find('.wizard-step4__icon-preview--dark').exists()).toBe(true)
		expect(wrapper.vm.darkIconSvg).toContain('d="M4 4h16v16H4z"')
	})

	it('renders both light and dark previews when both icons provided', () => {
		const wrapper = mountStep4({ iconValue: LIGHT_SVG, iconDarkValue: DARK_SVG })
		const figures = wrapper.findAll('figure.wizard-step4__icon-preview')
		expect(figures.length).toBe(2)
		expect(wrapper.vm.lightIconSvg).toContain('d="M0 0h24v24H0z"')
		expect(wrapper.vm.darkIconSvg).toContain('d="M4 4h16v16H4z"')
	})

	// -------------------------------------------------------------------------
	// Computed properties — versions accessor
	// -------------------------------------------------------------------------

	it('versions computed returns an empty array when payload.versions is not an array', () => {
		const wrapper = mountStep4({ versions: 'not-an-array' })
		expect(wrapper.vm.versions).toEqual([])
	})

	it('versions computed returns the payload.versions array unchanged', () => {
		const versionsList = [
			{ name: 'Development', slug: 'development' },
			{ name: 'Production', slug: 'production' },
		]
		const wrapper = mountStep4({ versions: versionsList })
		expect(wrapper.vm.versions).toHaveLength(2)
		expect(wrapper.vm.versions[0].slug).toBe('development')
	})

	// -------------------------------------------------------------------------
	// No emitted events (Step4Review is purely display)
	// -------------------------------------------------------------------------

	it('Step4Review emits no events on render', () => {
		const wrapper = mountStep4()
		// The review step has no interactive elements that emit — it is read-only
		expect(wrapper.emitted()).toEqual({})
	})
})
