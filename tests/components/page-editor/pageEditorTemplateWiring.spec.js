/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the TEMPLATE WIRING of the page-type editors.
 *
 * The existing per-editor specs drive the component through `wrapper.vm`
 * (`vm.update('folder', …)`, `vm.commitDraft()`), which proves the methods but
 * never the markup that is supposed to call them. That leaves the whole class
 * of defect where a field is bound to the wrong key, reads the wrong
 * `$event.target` property, or listens on the wrong event: the methods stay
 * green while the form silently edits nothing — or edits the wrong key.
 *
 * So these tests type into, toggle and click the RENDERED controls and assert
 * the `update:config` payload that comes out, one field at a time.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChatPageEditor from '../../../src/components/page-editor/ChatPageEditor.vue'
import FilesPageEditor from '../../../src/components/page-editor/FilesPageEditor.vue'
import RoadmapPageEditor from '../../../src/components/page-editor/RoadmapPageEditor.vue'
import SearchPageEditor from '../../../src/components/page-editor/SearchPageEditor.vue'
import SettingsPageEditor from '../../../src/components/page-editor/SettingsPageEditor.vue'

/**
 * The control inside the `<label>` whose text contains `text`.
 *
 * Fields are addressed by their visible label rather than by index or CSS
 * class, so a test keeps pointing at the same field when the form is
 * reordered or restyled.
 *
 * @param {object} wrapper The mounted editor.
 * @param {string} text A distinctive part of the label's text.
 * @param {string} selector The control to find inside that label.
 * @return {object} The control wrapper.
 */
function field(wrapper, text, selector = 'input') {
	const matching = wrapper
		.findAll('label')
		.filter((candidate) => candidate.text().includes(text))
	if (matching.length === 0) {
		throw new Error(`no label containing "${text}"`)
	}
	// A term can appear in more than one label — "conversationSource" labels
	// both a radio and its text field — so take the first that actually holds
	// the control the caller asked for.
	const found = matching.find((candidate) => candidate.find(selector).exists())
	if (found === undefined) {
		throw new Error(`no label containing "${text}" has a ${selector}`)
	}
	return found.find(selector)
}

/**
 * Type `value` into a text control and let the handler run.
 *
 * @param {object} control The input wrapper.
 * @param {string} value The value to type.
 * @return {Promise<void>}
 */
async function type(control, value) {
	control.element.value = value
	await control.trigger('input')
}

/**
 * Pick `value` in a select and let the handler run.
 *
 * @param {object} control The select wrapper.
 * @param {string} value The option value to choose.
 * @return {Promise<void>}
 */
async function choose(control, value) {
	control.element.value = value
	await control.trigger('change')
}

/**
 * The payload of the editor's last `update:config` emission.
 *
 * @param {object} wrapper The mounted editor.
 * @return {object} The emitted config.
 */
function lastConfig(wrapper) {
	const events = wrapper.emitted('update:config')
	expect(events, 'the editor emitted no update:config').toBeTruthy()
	return events[events.length - 1][0]
}

/**
 * The control carrying this exact `aria-label`.
 *
 * The repeated rows (facets, tabs) have no visible `<label>`, so their fields
 * are addressed by the accessible name they expose instead.
 *
 * @param {object} wrapper The mounted editor.
 * @param {string} name The aria-label value.
 * @param {number} nth Which match to take, for repeated rows.
 * @return {object} The control wrapper.
 */
function byAria(wrapper, name, nth = 0) {
	const found = wrapper.findAll(`[aria-label="${name}"]`)
	if (found.length <= nth) {
		throw new Error(`no [aria-label="${name}"] at index ${nth}`)
	}
	return found[nth]
}

describe('RoadmapPageEditor template wiring', () => {
	it('every plain text field writes its own config key', async () => {
		const cases = [
			['Repo', 'repo', 'acme/permits'],
			['Documentation URL', 'documentationUrl', 'https://docs.example.org'],
			['Suggest URL', 'suggestUrl', 'https://example.org/suggest'],
			['OpenBuilt URL', 'openbuiltUrl', 'https://example.org/built'],
			['LLM skills URL', 'llmSkillsUrl', 'https://example.org/skills'],
		]

		for (const [label, key, value] of cases) {
			const wrapper = mount(RoadmapPageEditor, { propsData: { config: {} } })
			await type(field(wrapper, label), value)
			expect(lastConfig(wrapper)[key], `${label} -> ${key}`).toBe(value)
		}
	})

	it('the forge-type select writes forge.type, and clearing it drops forge', async () => {
		const wrapper = mount(RoadmapPageEditor, {
			propsData: { config: { forge: { type: 'gitea', baseUrl: 'https://g' } } },
		})

		await choose(field(wrapper, 'Forge type', 'select'), 'github')
		expect(lastConfig(wrapper).forge).toEqual({
			type: 'github',
			baseUrl: 'https://g',
		})

		await choose(field(wrapper, 'Forge type', 'select'), '')
		expect(lastConfig(wrapper)).not.toHaveProperty('forge')
	})

	it('the forge base-URL field keeps the type alongside it', async () => {
		const wrapper = mount(RoadmapPageEditor, {
			propsData: { config: { forge: { type: 'forgejo' } } },
		})

		await type(field(wrapper, 'Forge base URL'), 'https://codeberg.org')

		expect(lastConfig(wrapper).forge).toEqual({
			type: 'forgejo',
			baseUrl: 'https://codeberg.org',
		})
	})

	it('the base-URL field is disabled until a forge type is chosen', () => {
		const withoutType = mount(RoadmapPageEditor, { propsData: { config: {} } })
		expect(field(withoutType, 'Forge base URL').element.disabled).toBe(true)

		const withType = mount(RoadmapPageEditor, {
			propsData: { config: { forge: { type: 'gitea' } } },
		})
		expect(field(withType, 'Forge base URL').element.disabled).toBe(false)
	})

	it('the Disabled checkbox writes the boolean, not the string', async () => {
		const wrapper = mount(RoadmapPageEditor, { propsData: { config: {} } })
		const box = field(wrapper, 'Disabled')

		box.element.checked = true
		await box.trigger('change')

		expect(lastConfig(wrapper).disabled).toBe(true)
	})
})

describe('FilesPageEditor template wiring', () => {
	it('the folder field writes config.folder', async () => {
		const wrapper = mount(FilesPageEditor, { propsData: { config: {} } })

		await type(field(wrapper, 'Folder path'), '/Documents')

		expect(lastConfig(wrapper).folder).toBe('/Documents')
	})

	it('Enter in the tag input commits the draft as an allowed type', async () => {
		const wrapper = mount(FilesPageEditor, { propsData: { config: { folder: '/x' } } })
		const tagInput = wrapper.find('.files-page-editor__tag-input')

		await tagInput.setValue('application/pdf')
		await tagInput.trigger('keydown.enter')

		expect(lastConfig(wrapper).allowedTypes).toEqual(['application/pdf'])
		expect(wrapper.vm.typeDraft).toBe('')
	})

	it('blurring the tag input commits the draft too', async () => {
		const wrapper = mount(FilesPageEditor, { propsData: { config: { folder: '/x' } } })
		const tagInput = wrapper.find('.files-page-editor__tag-input')

		await tagInput.setValue('.docx')
		await tagInput.trigger('blur')

		expect(lastConfig(wrapper).allowedTypes).toEqual(['.docx'])
	})

	it('the remove button on a tag drops that one type', async () => {
		const wrapper = mount(FilesPageEditor, {
			propsData: { config: { folder: '/x', allowedTypes: ['.pdf', '.docx'] } },
		})

		await wrapper.findAll('.files-page-editor__tag-remove')[0].trigger('click')

		expect(lastConfig(wrapper).allowedTypes).toEqual(['.docx'])
	})
})

describe('ChatPageEditor template wiring', () => {
	it('the transport radios drop the other transport key', async () => {
		const wrapper = mount(ChatPageEditor, {
			propsData: { config: { conversationSource: '/stream' } },
		})

		const postUrlRadio = field(wrapper, 'postUrl (send endpoint)')
		postUrlRadio.element.checked = true
		await postUrlRadio.trigger('change')

		expect(lastConfig(wrapper)).not.toHaveProperty('conversationSource')
	})

	it('choosing the conversationSource shape drops postUrl', async () => {
		const wrapper = mount(ChatPageEditor, {
			propsData: { config: { postUrl: '/send' } },
		})

		const streamRadio = field(wrapper, 'conversationSource (message stream)')
		streamRadio.element.checked = true
		await streamRadio.trigger('change')

		expect(lastConfig(wrapper)).not.toHaveProperty('postUrl')
	})

	it('the conversationSource field is the one shown for that shape, and excludes postUrl', async () => {
		const wrapper = mount(ChatPageEditor, {
			propsData: { config: { conversationSource: '/old' } },
		})

		await type(field(wrapper, 'conversationSource', 'input[type="text"]'), '/new')

		const config = lastConfig(wrapper)
		expect(config.conversationSource).toBe('/new')
		expect(config).not.toHaveProperty('postUrl')
	})

	it('the postUrl field shows for the postUrl shape and excludes conversationSource', async () => {
		const wrapper = mount(ChatPageEditor, {
			propsData: { config: { postUrl: '/send' } },
		})

		await type(field(wrapper, 'postUrl', 'input[type="text"]'), '/send/v2')

		const config = lastConfig(wrapper)
		expect(config.postUrl).toBe('/send/v2')
		expect(config).not.toHaveProperty('conversationSource')
	})

	it('the schema field writes config.schema and clears on empty', async () => {
		const wrapper = mount(ChatPageEditor, { propsData: { config: {} } })

		await type(field(wrapper, 'Schema slug'), 'message')
		expect(lastConfig(wrapper).schema).toBe('message')

		await type(field(wrapper, 'Schema slug'), '')
		expect(lastConfig(wrapper)).not.toHaveProperty('schema')
	})
})

describe('SettingsPageEditor template wiring', () => {
	it('the saveEndpoint field writes its key', async () => {
		const wrapper = mount(SettingsPageEditor, { propsData: { config: {} } })

		await type(field(wrapper, 'saveEndpoint'), '/api/objects/app/settings')

		expect(lastConfig(wrapper).saveEndpoint).toBe('/api/objects/app/settings')
	})

	it('the layout radios switch between the sections and tabs shapes', async () => {
		const wrapper = mount(SettingsPageEditor, {
			propsData: { config: { sections: [{ id: 's1' }] } },
		})

		const tabbed = field(wrapper, 'Tabbed sections')
		tabbed.element.checked = true
		await tabbed.trigger('change')
		expect(lastConfig(wrapper)).not.toHaveProperty('sections')

		const flat = field(wrapper, 'Flat sections')
		flat.element.checked = true
		await flat.trigger('change')
		expect(lastConfig(wrapper)).not.toHaveProperty('tabs')
	})

	it('each tab-head field writes its own key on the right tab', async () => {
		const config = { tabs: [{ id: 'one' }, { id: 'two' }] }

		const cases = [
			['Tab id', 'id', 'general'],
			['Tab label (i18n key)', 'label', 'General'],
			['Icon (optional)', 'icon', 'cog'],
		]

		for (const [aria, key, value] of cases) {
			const wrapper = mount(SettingsPageEditor, { propsData: { config } })
			// Second row, so a hard-coded index 0 in the handler would show.
			await type(byAria(wrapper, aria, 1), value)
			const tabs = lastConfig(wrapper).tabs
			expect(tabs[1][key], `${aria} -> tabs[1].${key}`).toBe(value)
			expect(tabs[0]).toEqual({ id: 'one' })
		}
	})

	it('the add and remove tab buttons grow and shrink the list', async () => {
		const added = mount(SettingsPageEditor, {
			propsData: { config: { tabs: [{ id: 'one' }] } },
		})
		await added.find('.settings-page-editor__add').trigger('click')
		expect(lastConfig(added).tabs).toHaveLength(2)

		const removed = mount(SettingsPageEditor, {
			propsData: { config: { tabs: [{ id: 'one' }, { id: 'two' }] } },
		})
		await removed.findAll('.settings-page-editor__remove')[0].trigger('click')
		expect(lastConfig(removed).tabs).toEqual([{ id: 'two' }])
	})

	it('clearing a tab field drops that key rather than storing an empty string', async () => {
		const wrapper = mount(SettingsPageEditor, {
			propsData: { config: { tabs: [{ id: 'one', icon: 'cog' }] } },
		})

		await type(byAria(wrapper, 'Icon (optional)'), '')

		expect(lastConfig(wrapper).tabs[0]).not.toHaveProperty('icon')
	})
})

describe('SearchPageEditor template wiring', () => {
	it('every text field writes its own config key', async () => {
		const cases = [
			['Title', 'title', 'Find a permit'],
			['Placeholder', 'placeholder', 'Search…'],
			['Search button label', 'searchLabel', 'Go'],
			['Idle label', 'idleLabel', 'Start typing'],
			['Empty-results label', 'emptyLabel', 'Nothing found'],
		]

		for (const [label, key, value] of cases) {
			const wrapper = mount(SearchPageEditor, { propsData: { config: {} } })
			await type(field(wrapper, label), value)
			expect(lastConfig(wrapper)[key], `${label} -> ${key}`).toBe(value)
		}
	})

	it('the facet key and label fields write onto the facet they belong to', async () => {
		const config = { facets: [{ key: 'a' }, { key: 'b' }] }

		const wrapperKey = mount(SearchPageEditor, { propsData: { config } })
		await type(byAria(wrapperKey, 'Facet key', 1), 'status')
		expect(lastConfig(wrapperKey).facets[1].key).toBe('status')
		expect(lastConfig(wrapperKey).facets[0].key).toBe('a')

		const wrapperLabel = mount(SearchPageEditor, { propsData: { config } })
		await type(byAria(wrapperLabel, 'Label (optional)', 1), 'Status')
		expect(lastConfig(wrapperLabel).facets[1].label).toBe('Status')
	})

	it('the Multiple checkbox writes a boolean on its own facet', async () => {
		const wrapper = mount(SearchPageEditor, {
			propsData: { config: { facets: [{ key: 'a' }, { key: 'b' }] } },
		})

		const boxes = wrapper
			.findAll('.search-page-editor__inline input[type="checkbox"]')
		boxes[1].element.checked = true
		await boxes[1].trigger('change')

		expect(lastConfig(wrapper).facets[1].multiple).toBe(true)
		expect(lastConfig(wrapper).facets[0]).not.toHaveProperty('multiple')
	})

	it('adding and removing a facet grows and shrinks the list', async () => {
		const added = mount(SearchPageEditor, { propsData: { config: {} } })
		const addFacet = added
			.findAll('.search-page-editor__row-add')
			.find((b) => b.text().includes('Add facet'))
		await addFacet.trigger('click')
		expect(lastConfig(added).facets).toHaveLength(1)

		const removed = mount(SearchPageEditor, {
			propsData: { config: { facets: [{ key: 'a' }, { key: 'b' }] } },
		})
		await removed.findAll('.search-page-editor__row-remove')[0].trigger('click')
		expect(lastConfig(removed).facets).toEqual([{ key: 'b' }])
	})

	it('a facet option writes through its facet index AND its option index', async () => {
		const config = {
			facets: [
				{ key: 'a', options: [{ value: 'x' }] },
				{ key: 'b', options: [{ value: 'y' }, { value: 'z' }] },
			],
		}

		const wrapper = mount(SearchPageEditor, { propsData: { config } })
		// Facet 1, option 1 — the third "Option value" box on the page.
		await type(byAria(wrapper, 'Option value', 2), 'zz')

		const facets = lastConfig(wrapper).facets
		expect(facets[1].options[1].value).toBe('zz')
		expect(facets[1].options[0].value).toBe('y')
		expect(facets[0].options[0].value).toBe('x')
	})

	it('adding and removing a facet option targets the right facet', async () => {
		const config = {
			facets: [{ key: 'a', options: [] }, { key: 'b', options: [{ value: 'y' }] }],
		}

		const added = mount(SearchPageEditor, { propsData: { config } })
		const addOption = added
			.findAll('.search-page-editor__row-add')
			.filter((b) => b.text().includes('Add option'))
		await addOption[1].trigger('click')
		expect(lastConfig(added).facets[1].options).toHaveLength(2)
		expect(lastConfig(added).facets[0].options).toHaveLength(0)

		const removed = mount(SearchPageEditor, { propsData: { config } })
		const removeButtons = removed.findAll('.search-page-editor__row-remove')
		// The last remove button belongs to facet 1's only option.
		await removeButtons[removeButtons.length - 1].trigger('click')
		expect(lastConfig(removed).facets[1].options).toEqual([])
	})
})
