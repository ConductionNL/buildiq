/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RoadmapPageEditor (REQ-PEC-004).
 *
 * Covers:
 *  - repo/forge fields render and emit shape { repo, forge: { type, baseUrl } }.
 *  - A seeded `features` array survives editing `repo` byte-identically
 *    (lossless — design.md Decision 4 keeps features[] Raw-JSON-only).
 *  - Unsetting forge type deletes the `forge` key.
 *  - The resolution-order hint text renders.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoadmapPageEditor from '../../../src/components/page-editor/RoadmapPageEditor.vue'

function mountEditor(config = {}) {
	return mount(RoadmapPageEditor, { propsData: { config } })
}

describe('RoadmapPageEditor', () => {
	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Roadmap page')
	})

	it('renders the resolution-order hint text', () => {
		expect(mountEditor().text()).toContain('features_roadmap_KEY')
	})

	it('editing repo emits the repo key', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('repo', 'ConductionNL/openbuild')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.repo).toBe('ConductionNL/openbuild')
	})

	it('updateForgeType writes forge as { type }', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.updateForgeType('github')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.forge).toEqual({ type: 'github' })
	})

	it('updateForgeField sets baseUrl while preserving type', async () => {
		const wrapper = mountEditor({ forge: { type: 'codeberg' } })
		wrapper.vm.updateForgeField('baseUrl', 'https://codeberg.org')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.forge).toEqual({
			type: 'codeberg',
			baseUrl: 'https://codeberg.org',
		})
	})

	it('unsetting forge type deletes the whole forge key', async () => {
		const wrapper = mountEditor({
			forge: { type: 'github', baseUrl: 'https://github.com' },
		})
		wrapper.vm.updateForgeType('')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('forge')
	})

	it('disabled checkbox writes an explicit boolean', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('disabled', true)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.disabled).toBe(true)
	})

	it('a seeded features array survives editing repo byte-identically (lossless)', async () => {
		const features = [
			{ slug: 'x', title: 'X', summary: 'summary', docsUrl: '/docs/x' },
		]
		const wrapper = mountEditor({ features })
		wrapper.vm.update('repo', 'ConductionNL/openbuild')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.features).toEqual(features)
	})

	it('validatedConfigKeys matches the surfaced keys', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual([
			'repo',
			'forge',
			'disabled',
			'documentationUrl',
			'suggestUrl',
			'openbuiltUrl',
			'llmSkillsUrl',
		])
	})

	it('override URL fields emit their keys', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('documentationUrl', 'https://docs.example.test')
		wrapper.vm.update('suggestUrl', 'https://suggest.example.test')
		wrapper.vm.update('openbuiltUrl', 'https://openbuilt.example.test')
		wrapper.vm.update('llmSkillsUrl', 'https://docs.conduction.nl/ai-skills')
		await wrapper.vm.$nextTick()
		const emissions = wrapper.emitted('update:config')
		expect(emissions[0][0].documentationUrl).toBe('https://docs.example.test')
		expect(emissions[1][0].suggestUrl).toBe('https://suggest.example.test')
		expect(emissions[2][0].openbuiltUrl).toBe('https://openbuilt.example.test')
		expect(emissions[3][0].llmSkillsUrl).toBe(
			'https://docs.conduction.nl/ai-skills',
		)
	})
})
