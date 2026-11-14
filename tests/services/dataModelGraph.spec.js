/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `src/services/dataModelGraph.js` (data-model-diagram T01,
 * T02): OpenRegister's register model mapped onto canvas nodes, edges and
 * table rows.
 *
 * The fixtures follow the response of openregister's
 * `RegisterModelService::model()` (`GET /api/registers/{id}/model`): nodes
 * `{id, slug, title, external, readable, properties[{name, type}]}` and
 * edges `{from, to, property, cardinality, inverse, source, target,
 * dangling}`, where `source` is `property` for a `$ref` field and
 * `declared` for an `x-openregister-relations` entry.
 */

import { describe, expect, it } from 'vitest'
import {
	canOpen,
	cardinalityMarker,
	FIELD_LIMIT,
	toDiagram,
} from '../../src/services/dataModelGraph.js'

/**
 * A model node.
 *
 * @param {string} id Id.
 * @param {string} slug Slug.
 * @param {Array<string>} fields Field names.
 * @param {object} [extra] Overrides.
 * @return {object} The node.
 */
function node(id, slug, fields = [], extra = {}) {
	return {
		id,
		slug,
		title: slug.charAt(0).toUpperCase() + slug.slice(1),
		external: false,
		readable: true,
		properties: fields.map((name) => ({ name, type: 'string' })),
		...extra,
	}
}

/**
 * A model edge.
 *
 * @param {string} from Source id.
 * @param {string|null} to Target id.
 * @param {string} property Relation name.
 * @param {string} cardinality one or many.
 * @param {object} [extra] Overrides.
 * @return {object} The edge.
 */
function edge(from, to, property, cardinality, extra = {}) {
	return {
		from,
		to,
		property,
		cardinality,
		inverse: null,
		source: 'property',
		target: to === null ? `#/missing/${property}` : `#/components/schemas/${to}`,
		dangling: to === null,
		...extra,
	}
}

// The vergunningen app of the spec: permit -> applicant (one), permit -> document (many).
const VERGUNNINGEN = {
	nodes: [
		node('1', 'permit', ['subject', 'applicant', 'documents']),
		node('2', 'applicant', ['name']),
		node('3', 'document', ['file']),
	],
	edges: [
		edge('1', '2', 'applicant', 'one'),
		edge('1', '3', 'documents', 'many'),
	],
}

describe('dataModelGraph', () => {
	it('draws three linked schemas as boxes with their fields and marked lines', () => {
		const diagram = toDiagram(VERGUNNINGEN)

		expect(diagram.nodes.map((n) => n.id)).toEqual(['1', '2', '3'])
		expect(diagram.nodes[0].label).toBe('Permit')
		expect(diagram.nodes[0].data.fields.map((f) => f.name)).toEqual(['subject', 'applicant', 'documents'])
		expect(diagram.nodes[0].data.fields[0].type).toBe('string')

		expect(diagram.edges).toHaveLength(2)
		expect(diagram.edges[0]).toMatchObject({ source: '1', target: '2', label: 'applicant (1)' })
		expect(diagram.edges[0].data).toMatchObject({ property: 'applicant', marker: '1', cardinality: 'one' })
		expect(diagram.edges[1]).toMatchObject({ source: '1', target: '3', label: 'documents (n)' })
		expect(diagram.edges[1].data.marker).toBe('n')
	})

	it('lays the boxes out left to right along the relations', () => {
		const diagram = toDiagram(VERGUNNINGEN)
		const x = Object.fromEntries(diagram.nodes.map((n) => [n.id, n.position.x]))

		expect(x['1']).toBe(0)
		expect(x['2']).toBeGreaterThan(x['1'])
		expect(x['3']).toBe(x['2'])
		// Two boxes in one column do not overlap.
		const [a, b] = diagram.nodes.filter((n) => n.position.x === x['2'])
		expect(a.position.y).not.toBe(b.position.y)
	})

	it('keeps a cycle in bounded columns', () => {
		const diagram = toDiagram({
			nodes: [node('a', 'a'), node('b', 'b'), node('c', 'c')],
			edges: [edge('a', 'b', 'next', 'one'), edge('b', 'c', 'next', 'one'), edge('c', 'b', 'back', 'one')],
		})
		const x = Object.fromEntries(diagram.nodes.map((n) => [n.id, n.position.x]))

		expect(x.a).toBe(0)
		expect(x.b).toBeGreaterThan(0)
		expect(Math.max(...Object.values(x))).toBeLessThanOrEqual(2 * 320)
	})

	it('lists the relations as table rows and the schemas without one apart', () => {
		const diagram = toDiagram({
			nodes: [...VERGUNNINGEN.nodes, node('4', 'note', ['text'])],
			edges: VERGUNNINGEN.edges,
		})

		expect(diagram.relations.map((r) => [r.from.slug, r.property, r.to.slug, r.cardinality])).toEqual([
			['permit', 'applicant', 'applicant', 'one'],
			['permit', 'documents', 'document', 'many'],
		])
		expect(diagram.unlinked.map((s) => s.slug)).toEqual(['note'])
	})

	it('draws an external schema the caller may not read untitled, at the edge, and not openable', () => {
		const diagram = toDiagram(
			{
				nodes: [
					node('1', 'permit', ['subject']),
					{ id: '99', slug: null, title: null, external: true, readable: false, properties: [] },
				],
				edges: [edge('1', '99', 'owner', 'one')],
			},
			{ untitled: 'Schema you may not read' },
		)
		const locked = diagram.nodes.find((n) => n.id === '99')

		expect(locked.label).toBe('Schema you may not read')
		expect(locked.data.title).toBeNull()
		expect(locked.data.fields).toEqual([])
		expect(locked.position.x).toBeGreaterThan(diagram.nodes.find((n) => n.id === '1').position.x)
		expect(canOpen(locked.data)).toBe(false)
		expect(canOpen(diagram.nodes[0].data)).toBe(true)
	})

	it('keeps a dangling edge as a box named after the reference that resolves to nothing', () => {
		const diagram = toDiagram({
			nodes: [node('1', 'permit', ['subject'])],
			edges: [edge('1', null, 'zone', 'one', { target: 'zones' })],
		})

		expect(diagram.edges).toHaveLength(1)
		const target = diagram.nodes.find((n) => n.id === diagram.edges[0].target)
		expect(target.data.dangling).toBe(true)
		expect(target.label).toBe('zones')
		expect(diagram.edges[0].data.dangling).toBe(true)
		expect(canOpen(target.data)).toBe(false)
	})

	it('draws a schema with no relations and no edges', () => {
		const diagram = toDiagram({ nodes: [node('1', 'note', ['text'])], edges: [] })

		expect(diagram.nodes).toHaveLength(1)
		expect(diagram.nodes[0].position).toEqual({ x: 0, y: 0 })
		expect(diagram.edges).toEqual([])
		expect(diagram.unlinked.map((s) => s.slug)).toEqual(['note'])
	})

	// T02: a relation made in buildiq's relation editor lives only in
	// `x-openregister-relations`; OpenRegister returns it as a `declared` edge.
	it('draws a relation that exists only in x-openregister-relations', () => {
		const response = {
			nodes: [node('10', 'permit', ['subject']), node('11', 'applicant', ['name'])],
			edges: [{
				from: '10',
				to: '11',
				property: 'applicant',
				cardinality: 'one',
				inverse: 'permits',
				source: 'declared',
				target: 'applicant',
				dangling: false,
			}],
		}
		const diagram = toDiagram(response)

		expect(diagram.edges).toHaveLength(1)
		expect(diagram.edges[0]).toMatchObject({ source: '10', target: '11', label: 'applicant / permits (1)' })
		expect(diagram.edges[0].data).toMatchObject({ source: 'declared', inverse: 'permits', marker: '1' })
		expect(diagram.relations[0].inverse).toBe('permits')
	})

	it('lists twelve fields and counts the rest', () => {
		const fields = Array.from({ length: 20 }, (_, i) => `f${i}`)
		const diagram = toDiagram({ nodes: [node('1', 'wide', fields)], edges: [] })

		expect(diagram.nodes[0].data.fields).toHaveLength(FIELD_LIMIT)
		expect(diagram.nodes[0].data.more).toBe(8)
	})

	it('folds boxes to their titles above the threshold, and not at it', () => {
		const many = (count) => ({
			nodes: Array.from({ length: count }, (_, i) => node(String(i), `s${i}`, ['a', 'b'])),
			edges: [],
		})

		expect(toDiagram(many(50)).folded).toBe(false)
		expect(toDiagram(many(50)).nodes[0].data.fields).toHaveLength(2)
		const folded = toDiagram(many(51))
		expect(folded.folded).toBe(true)
		expect(folded.nodes[0].data.fields).toEqual([])
		expect(folded.unlinked).toHaveLength(51)
	})

	it('ignores malformed input instead of throwing', () => {
		expect(toDiagram(null).nodes).toEqual([])
		const diagram = toDiagram({
			nodes: [null, node('1', 'permit')],
			edges: [null, { from: '1' }, edge('7', '1', 'orphan', 'one')],
		})
		expect(diagram.nodes).toHaveLength(1)
		expect(diagram.edges).toEqual([])
	})

	it('marks many as n and anything else as 1', () => {
		expect(cardinalityMarker('many')).toBe('n')
		expect(cardinalityMarker('one')).toBe('1')
		expect(cardinalityMarker(undefined)).toBe('1')
	})
})
