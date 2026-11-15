/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RoadmapPageEditor's forge field clearing.
 *
 * Covers:
 *  - clearing one forge field keeps the rest of the forge.
 *  - clearing the last forge field removes the forge key.
 *  - a forge field set on a page without a forge starts one.
 *  - changing the forge type keeps its other fields.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RoadmapPageEditor from '../../../src/components/page-editor/RoadmapPageEditor.vue'

function mountEditor(config = {}) {
	return mount(RoadmapPageEditor, { props: { config } })
}

describe('RoadmapPageEditor forge', () => {
	it('clearing one forge field keeps the others', () => {
		const wrapper = mountEditor({ repo: 'a/b', forge: { type: 'gitlab', baseUrl: 'https://git.example' } })
		wrapper.vm.updateForgeField('baseUrl', '')
		expect(wrapper.emitted('update:config')[0][0]).toEqual({ repo: 'a/b', forge: { type: 'gitlab' } })
	})

	it('clearing the last forge field removes the forge', () => {
		const wrapper = mountEditor({ repo: 'a/b', forge: { baseUrl: 'https://git.example' } })
		wrapper.vm.updateForgeField('baseUrl', null)
		expect(wrapper.emitted('update:config')[0][0]).toEqual({ repo: 'a/b' })
	})

	it('a field set without a forge starts one', () => {
		const wrapper = mountEditor({ repo: 'a/b' })
		wrapper.vm.updateForgeField('baseUrl', 'https://git.example')
		expect(wrapper.emitted('update:config')[0][0].forge).toEqual({ baseUrl: 'https://git.example' })
	})

	it('changing the type keeps the base URL', () => {
		const config = { forge: { type: 'github', baseUrl: 'https://git.example' } }
		const wrapper = mountEditor(config)
		wrapper.vm.updateForgeType('gitea')
		expect(wrapper.emitted('update:config')[0][0].forge).toEqual({ type: 'gitea', baseUrl: 'https://git.example' })
		expect(config.forge.type).toBe('github')
	})
})
