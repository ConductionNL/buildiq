/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The installed `@conduction/nextcloud-vue` manifest schema accepts a widget
 * placement promoted to the Nextcloud dashboard (`ncDashboard`) and refuses the
 * two shapes the designer must never save (publish-widgets-to-nc-dashboard,
 * task 1.2). A lockfile on a library release without `ncDashboard` fails here,
 * instead of shipping a promote control whose saves the schema rejects.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/openbuild-page-designer/spec.md
 */

import Ajv from 'ajv/dist/2020.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SCHEMA = JSON.parse(readFileSync(
	resolve(__dirname, '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'),
	'utf-8',
))

const ajv = new Ajv.default({ allErrors: true, strict: false })
ajv.addSchema(SCHEMA)
const validateWidget = ajv.compile({ $ref: SCHEMA.$id + '#/$defs/widgetEntry' })

const PLACEMENT = {
	widgetKey: 'open-requests',
	slot: 'body',
	gridX: 0,
	gridY: 0,
	gridWidth: 6,
	gridHeight: 3,
}

describe('ncDashboard in the installed manifest schema', () => {
	it('is declared on a widget placement', () => {
		expect(SCHEMA.$defs.widgetEntry.properties.ncDashboard).toBeDefined()
	})

	it('accepts a promoted placement with a stable id', () => {
		const promoted = {
			...PLACEMENT,
			id: 'open-requests',
			ncDashboard: { title: 'Open requests', icon: 'InboxArrowDown', order: 10, link: '/requests' },
		}

		expect(validateWidget(promoted)).toBe(true)
		expect(validateWidget(PLACEMENT)).toBe(true)
	})

	it('refuses a promoted placement without an id, naming the missing id', () => {
		expect(validateWidget({ ...PLACEMENT, ncDashboard: { title: 'Open requests' } })).toBe(false)
		expect(validateWidget.errors).toContainEqual(expect.objectContaining({
			keyword: 'required',
			params: { missingProperty: 'id' },
		}))

		expect(validateWidget({ ...PLACEMENT, id: '', ncDashboard: {} })).toBe(false)
		expect(validateWidget.errors).toContainEqual(expect.objectContaining({ instancePath: '/id', keyword: 'pattern' }))
	})

	it('refuses a key outside title, icon, order and link, naming the key', () => {
		expect(validateWidget({ ...PLACEMENT, id: 'open-requests', ncDashboard: { title: 'Open requests', colour: 'red' } })).toBe(false)
		expect(validateWidget.errors).toContainEqual(expect.objectContaining({
			instancePath: '/ncDashboard',
			keyword: 'additionalProperties',
			params: { additionalProperty: 'colour' },
		}))
	})
})
