/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Source checks for two things a component test cannot see.
 *
 *  - The schema designer's visible strings carried internal references
 *    ("ADR-031", "design Decision 7", "chain spec #5") and em-dashes.
 *  - The toast stylesheet was never imported, so every showSuccess and
 *    showError rendered as bare text in the top-left corner.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')

const DESIGNER_FILES = [
	'src/views/SchemaDesigner.vue',
	'src/components/schema-editor/AccessEditor.vue',
	'src/components/schema-editor/AggregationEditor.vue',
	'src/components/schema-editor/CalculationEditor.vue',
	'src/components/schema-editor/FieldEditor.vue',
	'src/components/schema-editor/LifecycleEditor.vue',
	'src/components/schema-editor/NotificationEditor.vue',
	'src/components/schema-editor/RelationEditor.vue',
	'src/components/schema-editor/SchemaHeaderForm.vue',
	'src/components/schema-editor/SchemaListPanel.vue',
	'src/components/schema-editor/WidgetEditor.vue',
	'src/dialogs/BreakingSchemaChangeDialog.vue',
	'src/services/schemaChanges.js',
]

/**
 * Every literal passed to t('buildiq', ...) in a file.
 *
 * @param {string} file Path relative to the app root.
 * @return {string[]} The source strings.
 */
function translatedStrings(file) {
	const source = readFileSync(resolve(root, file), 'utf8')
	const pattern = /\bt\(\s*'buildiq',\s*(['"])((?:\\.|(?!\1).)*)\1/gs
	return [...source.matchAll(pattern)].map((match) => match[2])
}

describe('schema designer copy', () => {
	it.each(DESIGNER_FILES)(
		'%s shows no internal references or em-dashes',
		(file) => {
			const offending = translatedStrings(file).filter((text) =>
				/ADR-\d|Decision \d|chain spec|REQ-[A-Z]|OQ-\d|—/.test(text),
			)
			expect(offending).toEqual([])
		},
	)
})

describe('toast styles', () => {
	it.each(['src/main.js', 'src/builder.js'])(
		'%s imports the toast stylesheet',
		(file) => {
			const source = readFileSync(resolve(root, file), 'utf8')
			expect(source).toMatch(/import '@nextcloud\/dialogs\/style\.css'/)
		},
	)
})
