/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Validates one widget against the real v1 manifest schema's `$defs.widgetDef`,
 * read from the installed @conduction/nextcloud-vue package (the stub alias
 * only covers the package's JS entry, not this file).
 */

import Ajv from 'ajv/dist/2020.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const schema = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json'),
		'utf8',
	),
)

const ajv = new Ajv.default({ allErrors: true, strict: false })
ajv.addSchema(schema)
const validate = ajv.compile({ $ref: `${schema.$id}#/$defs/widgetDef` })

/**
 * The schema errors for a widget, empty when it validates.
 *
 * @param {object} widget - the widget definition.
 * @return {Array<object>} the Ajv errors.
 */
export function validateWidgetDef(widget) {
	return validate(widget) ? [] : validate.errors
}
