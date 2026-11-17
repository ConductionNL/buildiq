/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Maps OpenRegister's register model (`GET /api/registers/{id}/model`) onto
 * the nodes and edges `CnGraphCanvas` draws, and onto the rows of the table
 * that lists the same picture for keyboard and screen-reader users.
 *
 * OpenRegister resolves every reference and decides which external schema
 * the caller may read; this module only draws what it is given. It keeps no
 * state and lays the boxes out left to right on every call: a schema that
 * nothing links to starts a column, each relation moves its target one
 * column further, and schemas outside the register stand in a last column
 * at the edge.
 *
 * @spec openspec/changes/data-model-diagram/specs/data-model-diagram/spec.md
 */

/** The node type every schema box is drawn as. */
export const SCHEMA_NODE_TYPE = 'schema'

/** How many fields a box lists before it says "and N more". */
export const FIELD_LIMIT = 12

/** Above this many schemas the boxes start folded to their titles. */
export const FOLD_THRESHOLD = 50

/** Horizontal distance between two columns. */
const COLUMN_WIDTH = 320

/** Vertical room per box: a title line plus one line per listed field. */
const ROW_HEADER = 56
const ROW_FIELD = 20
const ROW_GAP = 32

/**
 * A non-empty string, or null.
 *
 * @param {unknown} value Any value.
 * @return {string|null} The string, or null.
 */
function text(value) {
	return typeof value === 'string' && value !== '' ? value : null
}

/**
 * The marker a relation carries: `n` for many, `1` otherwise.
 *
 * @param {string} cardinality `one` or `many` as the model states it.
 * @return {string} `1` or `n`.
 */
export function cardinalityMarker(cardinality) {
	return cardinality === 'many' ? 'n' : '1'
}

/**
 * The model's nodes and edges, with anything malformed left out.
 *
 * @param {object|null} model The model response.
 * @return {{nodes: Array<object>, edges: Array<object>}} The clean model.
 */
function cleanModel(model) {
	const rawNodes = model && Array.isArray(model.nodes) ? model.nodes : []
	const rawEdges = model && Array.isArray(model.edges) ? model.edges : []
	const nodes = rawNodes.filter(
		(node) => node && (typeof node.id === 'string' || typeof node.id === 'number'),
	)
	const edges = rawEdges.filter(
		(edge) =>
			edge
			&& (typeof edge.from === 'string' || typeof edge.from === 'number')
			&& text(edge.property) !== null,
	)
	return { nodes, edges }
}

/**
 * The column of each internal schema: a schema nothing links to starts at
 * column 0 and each relation moves its target at least one column right of
 * its source (topological layering). Schemas left in a cycle keep the column
 * a placed source gave them, or 0.
 *
 * @param {Array<string>} ids The internal schema ids, in model order.
 * @param {Array<{from: string, to: string}>} links Links between internal schemas.
 * @return {Map<string, number>} Column per id.
 */
function columnsOf(ids, links) {
	const incoming = new Map(ids.map((id) => [id, 0]))
	const outgoing = new Map(ids.map((id) => [id, []]))
	for (const link of links) {
		if (link.from === link.to) {
			continue
		}
		incoming.set(link.to, incoming.get(link.to) + 1)
		outgoing.get(link.from).push(link.to)
	}

	const column = new Map()
	const queue = ids.filter((id) => incoming.get(id) === 0)
	queue.forEach((id) => column.set(id, 0))
	while (queue.length > 0) {
		const id = queue.shift()
		for (const to of outgoing.get(id)) {
			column.set(to, Math.max(column.get(to) ?? 0, column.get(id) + 1))
			incoming.set(to, incoming.get(to) - 1)
			if (incoming.get(to) === 0) {
				queue.push(to)
			}
		}
	}
	for (const id of ids) {
		if (!column.has(id)) {
			column.set(id, 0)
		}
	}
	return column
}

/**
 * The height a box takes on the canvas.
 *
 * @param {object} data The node data.
 * @return {number} Height in pixels.
 */
function boxHeight(data) {
	if (data.folded) {
		return ROW_HEADER
	}
	const lines = data.fields.length + (data.more > 0 ? 1 : 0)
	return ROW_HEADER + lines * ROW_FIELD
}

/**
 * Map a register model onto the diagram's nodes, edges and table rows.
 *
 * @param {object|null} model OpenRegister's `{nodes, edges}` for a register.
 * @param {object} [options] Options.
 * @param {number} [options.foldAbove] Fold boxes when there are more schemas than this.
 * @param {string} [options.untitled] The label of a schema the caller may not read.
 * @return {{nodes: Array<object>, edges: Array<object>, relations: Array<object>, unlinked: Array<object>, folded: boolean}}
 *   Canvas nodes and edges, the relation rows and the schemas without relations.
 *
 * @spec openspec/changes/data-model-diagram/tasks.md#T01
 */
export function toDiagram(model, options = {}) {
	const foldAbove = options.foldAbove ?? FOLD_THRESHOLD
	const untitled = options.untitled ?? ''
	const { nodes: rawNodes, edges: rawEdges } = cleanModel(model)

	const internalCount = rawNodes.filter((node) => node.external !== true).length
	const folded = internalCount > foldAbove

	const byId = new Map()
	for (const node of rawNodes) {
		const id = String(node.id)
		const readable = node.readable !== false
		const properties = readable && Array.isArray(node.properties) ? node.properties : []
		const fields = properties
			.filter((p) => p && text(p.name) !== null)
			.map((p) => ({ name: p.name, type: text(p.type) || '' }))
		const title = readable ? (text(node.title) || text(node.slug) || id) : null
		byId.set(id, {
			id,
			slug: readable ? (text(node.slug) || id) : null,
			title,
			label: title === null ? untitled : title,
			external: node.external === true,
			readable,
			dangling: false,
			fields: folded ? [] : fields.slice(0, FIELD_LIMIT),
			more: folded ? 0 : Math.max(0, fields.length - FIELD_LIMIT),
			folded,
		})
	}

	const edges = []
	const relations = []
	const linked = new Set()
	rawEdges.forEach((edge, index) => {
		const from = String(edge.from)
		if (!byId.has(from)) {
			return
		}
		const dangling = edge.dangling === true || edge.to === null || edge.to === undefined
		let to = dangling ? null : String(edge.to)
		if (to !== null && !byId.has(to)) {
			// A target the model did not list: draw it as an untitled edge box.
			byId.set(to, {
				id: to, slug: null, title: null, label: untitled, external: true,
				readable: false, dangling: false, fields: [], more: 0, folded,
			})
		}
		if (to === null) {
			// A reference that resolves to nothing: a box named after the
			// reference as written, so the broken link shows.
			to = `dangling:${from}:${edge.property}`
			const reference = text(edge.target) || edge.property
			byId.set(to, {
				id: to, slug: null, title: reference, label: reference, external: true,
				readable: false, dangling: true, fields: [], more: 0, folded,
			})
		}
		linked.add(from)
		linked.add(to)
		const cardinality = edge.cardinality === 'many' ? 'many' : 'one'
		const marker = cardinalityMarker(cardinality)
		const inverse = text(edge.inverse)
		const label = inverse ? `${edge.property} / ${inverse} (${marker})` : `${edge.property} (${marker})`
		edges.push({
			id: `e${index}:${from}:${edge.property}`,
			source: from,
			target: to,
			label,
			data: {
				property: edge.property,
				inverse,
				cardinality,
				marker,
				dangling,
				source: text(edge.source) || 'property',
				labelAriaLabel: label,
			},
		})
		relations.push({
			id: `e${index}:${from}:${edge.property}`,
			from: byId.get(from),
			property: edge.property,
			inverse,
			to: byId.get(to),
			cardinality,
		})
	})

	// Layout: internal schemas by column, external and dangling boxes at the edge.
	const internal = [...byId.values()].filter((n) => !n.external)
	const outside = [...byId.values()].filter((n) => n.external)
	const internalIds = new Set(internal.map((n) => n.id))
	const columns = columnsOf(
		internal.map((n) => n.id),
		edges
			.filter((e) => internalIds.has(e.source) && internalIds.has(e.target))
			.map((e) => ({ from: e.source, to: e.target })),
	)
	const lastColumn = internal.reduce((max, n) => Math.max(max, columns.get(n.id)), -1)
	const heights = new Map()
	const place = (node, column) => {
		const y = heights.get(column) || 0
		heights.set(column, y + boxHeight(node) + ROW_GAP)
		return { x: column * COLUMN_WIDTH, y }
	}

	const nodes = []
	for (const node of internal) {
		nodes.push(canvasNode(node, place(node, columns.get(node.id))))
	}
	for (const node of outside) {
		nodes.push(canvasNode(node, place(node, lastColumn + 1)))
	}

	const unlinked = internal.filter((n) => !linked.has(n.id))
	return { nodes, edges, relations, unlinked, folded }
}

/**
 * One canvas node.
 *
 * @param {object} node The box.
 * @param {{x: number, y: number}} position Where it stands.
 * @return {object} A Vue Flow node.
 */
function canvasNode(node, position) {
	return {
		id: node.id,
		label: node.label,
		position,
		data: { ...node, kind: SCHEMA_NODE_TYPE },
	}
}

/**
 * Whether a box opens a schema: only one the caller may read and that exists.
 *
 * @param {object|null|undefined} box The node data (or a relation end).
 * @return {boolean} Whether it opens.
 */
export function canOpen(box) {
	return Boolean(box && box.readable && !box.dangling && box.slug)
}
