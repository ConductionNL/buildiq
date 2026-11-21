/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the schema lookups behind the document and workflow
 * attachment validators: slugs that are also object prototype names, schema
 * sets embedded as lists and maps, page bindings, and link properties whose
 * type and format disagree.
 *
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-001
 */
import { describe, expect, it } from 'vitest'
import { validateDocumentAttachments } from '../../src/services/manifestValidation/documentAttachments.js'
import { validateWorkflowAttachments } from '../../src/services/manifestValidation/workflowAttachments.js'

const UUID = '0b6d2c1e-3f4a-4b5c-8d9e-0f1a2b3c4d5e'

const SLUGS = ['case', 'toString', 'constructor', 'hasOwnProperty', 'ghost']

const PROPERTIES = {
	plain: { type: 'string' },
	dated: { format: 'date' },
	uriNumber: { type: 'integer', format: 'uri' },
	urlNumber: { type: 'number', format: 'url' },
	objectUri: { type: 'object', format: 'uri' },
	bare: {},
	count: { type: 'integer' },
}

const SCHEMA_SETS = [
	undefined,
	{},
	{ case: { properties: PROPERTIES } },
	[{ slug: 'case', properties: PROPERTIES }],
	[{ id: 'toString', schema: { properties: PROPERTIES } }],
]

const PAGE_SETS = [undefined, [], [{ config: { schema: 'case' } }], [{ config: {} }]]

/**
 * The manifests of every slug, schema set and page set.
 *
 * @param {function(string): object} runtimeOf Builds the runtime block for a slug.
 *
 * @return {Array<object>} The manifests.
 */
function manifests(runtimeOf) {
	const out = []
	for (const slug of SLUGS) {
		for (const schemas of SCHEMA_SETS) {
			for (const pages of PAGE_SETS) {
				const manifest = { runtime: runtimeOf(slug) }
				if (schemas !== undefined) {
					manifest.schemas = schemas
				}
				if (pages !== undefined) {
					manifest.pages = pages
				}
				out.push(manifest)
			}
		}
	}
	return out
}

describe('manifest schema lookup golden', () => {
	it('validateDocumentAttachments finds each schema as recorded', () => {
		const out = manifests((slug) => ({
			documents: [
				{
					schema: slug,
					templateId: 't',
					templateName: 'T',
					label: 'L',
					format: 'pdf',
				},
			],
		})).map((m) => validateDocumentAttachments(m))
		expect(out).toMatchSnapshot()
	})

	it('validateWorkflowAttachments finds each schema and link property as recorded', () => {
		const out = []
		for (const linkProperty of Object.keys(PROPERTIES)) {
			out.push(
				...manifests((slug) => ({
					workflows: [
						{
							id: 'w',
							schema: slug,
							caseTypeUuid: UUID,
							caseTypeName: 'Permit',
							trigger: 'on-create',
							linkProperty,
						},
					],
				})).map((m) => validateWorkflowAttachments(m)),
			)
		}
		expect(out).toMatchSnapshot()
	})
})
