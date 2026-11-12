/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for src/components/runtimeAttachments.js: `upsertById`
 * replacing or appending without touching its input, `runtimeListWriter`
 * setting or dropping `runtime.<key>` (and `runtime` itself when empty), and
 * `detailTabInjector` adding a sidebar tab only to the detail pages of the
 * attached schema, once per component.
 */
import { describe, expect, it } from 'vitest'
import { detailTabInjector, runtimeListWriter, upsertById } from '../../src/components/runtimeAttachments.js'

describe('upsertById', () => {
	it('replaces the entry with the same id in place', () => {
		const list = [{ id: 'a', v: 1 }, { id: 'b', v: 2 }, { id: 'c', v: 3 }]
		const next = upsertById(list, { id: 'b', v: 20 })
		expect(next).toEqual([{ id: 'a', v: 1 }, { id: 'b', v: 20 }, { id: 'c', v: 3 }])
		expect(list[1]).toEqual({ id: 'b', v: 2 })
		expect(next).not.toBe(list)
	})

	it('replaces the first entry rather than appending a duplicate', () => {
		expect(upsertById([{ id: 'a', v: 1 }, { id: 'b' }], { id: 'a', v: 9 })).toEqual([{ id: 'a', v: 9 }, { id: 'b' }])
	})

	it('appends an entry whose id is not there', () => {
		const list = [{ id: 'a' }]
		expect(upsertById(list, { id: 'z' })).toEqual([{ id: 'a' }, { id: 'z' }])
		expect(list).toEqual([{ id: 'a' }])
	})

	it('appends to an empty list', () => {
		expect(upsertById([], { id: 'x' })).toEqual([{ id: 'x' }])
	})

	it('matches ids strictly', () => {
		expect(upsertById([{ id: 1 }], { id: '1' })).toEqual([{ id: 1 }, { id: '1' }])
	})
})

describe('runtimeListWriter', () => {
	const write = runtimeListWriter('documents')

	it('sets the list under runtime.<key>, keeping other keys', () => {
		const manifest = { id: 'app', runtime: { workflows: [{ id: 'w' }] } }
		const next = write(manifest, [{ id: 'd' }])
		expect(next).toEqual({ id: 'app', runtime: { workflows: [{ id: 'w' }], documents: [{ id: 'd' }] } })
		expect(manifest.runtime).toEqual({ workflows: [{ id: 'w' }] })
	})

	it('creates runtime when the manifest has none', () => {
		expect(write({ id: 'app' }, [{ id: 'd' }])).toEqual({ id: 'app', runtime: { documents: [{ id: 'd' }] } })
	})

	it('drops the key for an empty list but keeps the other runtime keys', () => {
		const next = write({ runtime: { documents: [{ id: 'd' }], workflows: [] } }, [])
		expect(next).toEqual({ runtime: { workflows: [] } })
	})

	it('drops runtime itself when nothing is left', () => {
		const manifest = { id: 'app', runtime: { documents: [{ id: 'd' }] } }
		const next = write(manifest, [])
		expect(next).toEqual({ id: 'app' })
		expect('runtime' in next).toBe(false)
		expect(manifest.runtime.documents).toEqual([{ id: 'd' }])
	})

	it('adds no runtime for an empty list on a manifest without one', () => {
		expect(write({ id: 'app' }, [])).toEqual({ id: 'app' })
	})
})

describe('detailTabInjector', () => {
	const tab = { id: 'docs', label: 'Documents', component: 'DocsTab' }
	const inject = detailTabInjector(tab)
	const detail = (schema, sidebarProps) => ({
		id: `detail-${schema}`,
		type: 'detail',
		config: sidebarProps === undefined ? { schema } : { schema, sidebarProps },
	})

	it('adds the tab to a detail page of the entry schema', () => {
		const next = inject({ pages: [detail('case')] }, { schema: 'case' })
		expect(next.pages[0].config.sidebarProps.tabs).toEqual([tab])
		expect(next.pages[0].config.sidebarProps.tabs[0]).not.toBe(tab)
	})

	it('leaves pages of other schemas and other types alone', () => {
		const other = detail('person')
		const index = { id: 'index', type: 'index', config: { schema: 'case' } }
		const next = inject({ pages: [other, index] }, { schema: 'case' })
		expect(next.pages[0]).toBe(other)
		expect(next.pages[1]).toBe(index)
	})

	it('keeps existing tabs and appends after them', () => {
		const page = detail('case', { tabs: [{ id: 'notes', component: 'NotesTab' }], open: true })
		const next = inject({ pages: [page] }, { schema: 'case' })
		expect(next.pages[0].config.sidebarProps).toEqual({
			tabs: [{ id: 'notes', component: 'NotesTab' }, tab],
			open: true,
		})
		expect(page.config.sidebarProps.tabs).toHaveLength(1)
	})

	it('does not add a second tab with the same component', () => {
		const page = detail('case', { tabs: [{ id: 'other-id', label: 'x', component: 'DocsTab' }] })
		const next = inject({ pages: [page] }, { schema: 'case' })
		expect(next.pages[0].config.sidebarProps.tabs).toEqual([{ id: 'other-id', label: 'x', component: 'DocsTab' }])
	})

	it('copes with a manifest without pages and with null pages', () => {
		expect(inject({ id: 'app' }, { schema: 'case' })).toEqual({ id: 'app', pages: [] })
		const next = inject({ pages: [null, detail('case')] }, { schema: 'case' })
		expect(next.pages[0]).toBeNull()
		expect(next.pages[1].config.sidebarProps.tabs).toEqual([tab])
	})

	it('does not mutate the input manifest', () => {
		const manifest = { pages: [detail('case')] }
		const next = inject(manifest, { schema: 'case' })
		expect(next.pages).not.toBe(manifest.pages)
		expect(manifest.pages[0].config.sidebarProps).toBeUndefined()
	})
})
