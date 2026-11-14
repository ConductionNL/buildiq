/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/connectorPages.js
 * (integrations-connector-bound-pages T01, REQ-BQCB-001).
 */

import { describe, expect, it } from 'vitest'
import {
	connectorDataSourceOf,
	hasConnectorRewrites,
	normalizeConnectorPages,
	restoreConnectorPages,
} from '../../src/services/connectorPages.js'

const connector = {
	endpointPath: 'suppliers',
	fields: { name: 'name', city: 'address.city' },
}

function indexPage() {
	return {
		id: 'suppliers',
		route: '/suppliers',
		title: 'Suppliers',
		type: 'index',
		permission: 'suppliers.read',
		config: { dataSource: { connector }, columns: ['name'], showTitle: true },
	}
}

function dashboardPage() {
	return {
		id: 'home',
		route: '/',
		title: 'Home',
		type: 'dashboard',
		config: {
			dataSource: { connector },
			widgets: [{ id: 'stats', title: 'Stats', type: 'stat' }],
			layout: [
				{
					id: '1',
					widgetId: 'stats',
					gridX: 0,
					gridY: 0,
					gridWidth: 6,
					gridHeight: 2,
				},
			],
		},
	}
}

function plainPage() {
	return {
		id: 'cases',
		route: '/cases',
		title: 'Cases',
		type: 'index',
		config: { register: 'r', schema: 's' },
	}
}

describe('normalizeConnectorPages', () => {
	it('turns a connector-bound index page into a connector-list custom page', () => {
		const out = normalizeConnectorPages({ pages: [indexPage()] })

		expect(out.pages[0]).toEqual({
			id: 'suppliers',
			route: '/suppliers',
			title: 'Suppliers',
			type: 'custom',
			permission: 'suppliers.read',
			component: 'connector-list',
			config: { dataSource: { connector } },
		})
	})

	it('puts a connector-data widget bound to the page on top of a dashboard', () => {
		const out = normalizeConnectorPages({ pages: [dashboardPage()] })
		const config = out.pages[0].config

		expect(out.pages[0].type).toBe('dashboard')
		expect(config.widgets[0]).toEqual({
			id: 'connector-data-home',
			title: 'Home',
			type: 'connector-data',
			content: { dataSource: { connector } },
		})
		expect(config.widgets[1].id).toBe('stats')
		expect(config.layout[0]).toEqual({
			id: 'connector-data-home',
			widgetId: 'connector-data-home',
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 4,
		})
		expect(config.layout[1]).toEqual({
			id: '1',
			widgetId: 'stats',
			gridX: 0,
			gridY: 4,
			gridWidth: 6,
			gridHeight: 2,
		})
	})

	it('picks a widget id the dashboard does not use yet', () => {
		const page = dashboardPage()
		page.config.widgets.push({ id: 'connector-data-home', type: 'stat' })

		const out = normalizeConnectorPages({ pages: [page] })

		expect(out.pages[0].config.widgets[0].id).toBe('connector-data-home-2')
	})

	it('leaves a page without a connector as the same object', () => {
		const page = plainPage()
		const out = normalizeConnectorPages({ pages: [page, indexPage()] })

		expect(out.pages[0]).toBe(page)
	})

	it('returns the manifest itself when nothing is connector-bound', () => {
		const manifest = { pages: [plainPage()] }

		expect(normalizeConnectorPages(manifest)).toBe(manifest)
		expect(hasConnectorRewrites(manifest)).toBe(false)
	})

	it('leaves a connector block on another page type alone', () => {
		const page = {
			id: 'd',
			route: '/d',
			title: 'D',
			type: 'detail',
			config: { dataSource: { connector } },
		}

		expect(normalizeConnectorPages({ pages: [page] }).pages[0]).toBe(page)
	})

	it('never touches the stored manifest', () => {
		const stored = { version: '1', pages: [indexPage(), dashboardPage()] }
		const snapshot = JSON.parse(JSON.stringify(stored))

		const out = normalizeConnectorPages(stored)
		out.pages[0].config.dataSource.connector.endpointPath = 'changed'
		out.pages[1].config.widgets.push({ id: 'x' })

		expect(stored).toEqual(snapshot)
		expect(out).not.toBe(stored)
		expect(out.version).toBe('1')
	})

	it('passes through input that is not a manifest with pages', () => {
		expect(normalizeConnectorPages(null)).toBe(null)
		const noPages = { menu: [] }
		expect(normalizeConnectorPages(noPages)).toBe(noPages)
	})
})

describe('connectorDataSourceOf', () => {
	it('returns the data source only when it carries a connector object', () => {
		expect(connectorDataSourceOf(indexPage())).toEqual({ connector })
		expect(connectorDataSourceOf(plainPage())).toBe(null)
		expect(
			connectorDataSourceOf({ config: { dataSource: { connector: [] } } }),
		).toBe(null)
		expect(connectorDataSourceOf({ config: [] })).toBe(null)
	})
})

describe('restoreConnectorPages', () => {
	it('gives back the authored index page, keeping a title edited in the app', () => {
		const out = normalizeConnectorPages({ pages: [indexPage(), plainPage()] })
		out.pages[0].title = 'Leveranciers'

		const stored = restoreConnectorPages(out)

		expect(stored.pages[0]).toEqual({ ...indexPage(), title: 'Leveranciers' })
		expect(stored.pages[1]).toEqual(plainPage())
	})

	it('drops the injected dashboard widget and moves the cells back up', () => {
		const out = normalizeConnectorPages({ pages: [dashboardPage()] })

		expect(restoreConnectorPages(out).pages[0]).toEqual(dashboardPage())
	})

	it('keeps a widget added to the dashboard in the app', () => {
		const out = normalizeConnectorPages({ pages: [dashboardPage()] })
		out.pages[0].config.widgets.push({ id: 'new', type: 'stat' })
		out.pages[0].config.layout.push({
			id: '2',
			widgetId: 'new',
			gridX: 6,
			gridY: 4,
			gridWidth: 6,
			gridHeight: 2,
		})

		const page = restoreConnectorPages(out).pages[0]

		expect(page.config.widgets.map((w) => w.id)).toEqual(['stats', 'new'])
		expect(page.config.layout[1]).toEqual({
			id: '2',
			widgetId: 'new',
			gridX: 6,
			gridY: 0,
			gridWidth: 6,
			gridHeight: 2,
		})
	})

	it('drops a page removed in the app', () => {
		const out = normalizeConnectorPages({ pages: [indexPage(), plainPage()] })
		out.pages.splice(0, 1)

		expect(restoreConnectorPages(out).pages).toEqual([plainPage()])
	})

	it('returns a manifest without rewrites as is', () => {
		const manifest = { pages: [indexPage()] }

		expect(restoreConnectorPages(manifest)).toBe(manifest)
	})
})
