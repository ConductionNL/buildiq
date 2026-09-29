<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - ExpressionNodeEditor: builds one node of an OpenRegister calculation
  - expression. A node is a field of the record, a typed value, or an
  - operator from the published catalogue whose operands are nodes again.
  - There is no free-text formula (REQ-BQCF-002).
  -->
<template>
	<div
		class="buildiq-expr-node"
		:class="{ 'buildiq-expr-node--nested': depth > 0 }">
		<div class="buildiq-expr-node__row">
			<NcSelect
				:inputLabel="t('buildiq', 'Part')"
				:modelValue="kindOption"
				:options="kindOptions"
				:clearable="false"
				label="label"
				trackBy="value"
				@update:modelValue="onKind" />
			<NcSelect
				v-if="kind === 'field'"
				:inputLabel="t('buildiq', 'Field')"
				:modelValue="fieldName"
				:options="fieldNames"
				:clearable="false"
				@update:modelValue="emitNode({ prop: $event })" />
			<NcTextField
				v-else-if="kind === 'value'"
				:modelValue="valueText"
				:label="t('buildiq', 'Value')"
				@update:modelValue="emitNode(parseLiteral($event))" />
			<NcSelect
				v-else
				:inputLabel="t('buildiq', 'Operator')"
				:modelValue="operatorOption"
				:options="operatorOptions"
				:clearable="false"
				label="label"
				trackBy="op"
				@update:modelValue="onOperator" />
		</div>
		<p
			v-if="kind === 'operator' && operatorOption"
			class="buildiq-expr-node__hint">
			{{ operatorOption.description }}
		</p>
		<ol v-if="kind === 'operator'" class="buildiq-expr-node__operands">
			<li v-for="(operand, index) in operands" :key="index">
				<ExpressionNodeEditor
					:node="operand"
					:fieldNames="fieldNames"
					:operators="operators"
					:depth="depth + 1"
					@update:node="onOperand(index, $event)" />
				<NcButton
					v-if="isOpenArity && operands.length > minOperands"
					:ariaLabel="
						t('buildiq', 'Remove operand {number}', {
							number: index + 1,
						})
					"
					@click="emitNode(removeOperand(node, index))">
					{{ t('buildiq', 'Remove') }}
				</NcButton>
			</li>
		</ol>
		<NcButton
			v-if="kind === 'operator' && isOpenArity"
			@click="emitNode(addOperand(node))">
			{{ t('buildiq', 'Add operand') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * The kind of an expression node.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {unknown} node An expression AST node.
 * @return {'field'|'value'|'operator'} The kind.
 */
export function nodeKind(node) {
	if (!node || typeof node !== 'object' || Array.isArray(node)) {
		return 'value'
	}
	return Object.keys(node)[0] === 'prop' ? 'field' : 'operator'
}

/**
 * A fresh node of a kind.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {'field'|'value'|'operator'} kind The kind.
 * @param {Array<string>} fieldNames The schema's fields.
 * @return {unknown} The node; an operator node without an operator is null.
 */
export function nodeForKind(kind, fieldNames = []) {
	if (kind === 'field') {
		return { prop: fieldNames[0] || '' }
	}
	if (kind === 'operator') {
		return null
	}
	return 0
}

/**
 * The smallest operand count an arity allows, and whether more may follow.
 *
 * @param {string} arity The catalogue arity, such as `2`, `1+` or `0`.
 * @return {{min: number, open: boolean}} The bounds.
 */
function arityBounds(arity) {
	const text = String(arity ?? '1')
	const open = text.endsWith('+')
	const min = parseInt(text, 10)
	return { min: Number.isNaN(min) ? 1 : min, open }
}

/**
 * Put an operator on a node, keeping the operands that fit.
 *
 * An open arity (`1+`) starts with two operands, so a multiply has
 * something to multiply.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {unknown} node The current node.
 * @param {{op: string, arity: string}} operator A catalogue row.
 * @return {object} The operator node.
 */
export function withOperator(node, operator) {
	const { min, open } = arityBounds(operator.arity)
	const current = nodeKind(node) === 'operator' ? Object.values(node)[0] : []
	const size = open ? Math.max(min, 2, current.length) : min
	const operands = []
	for (let i = 0; i < size; i++) {
		operands.push(i < current.length ? current[i] : 0)
	}
	return { [operator.op]: operands }
}

/**
 * Add a value operand to an operator node.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {object} node The operator node.
 * @return {object} The new node.
 */
export function addOperand(node) {
	const op = Object.keys(node)[0]
	return { [op]: [...node[op], 0] }
}

/**
 * Remove one operand of an operator node.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {object} node The operator node.
 * @param {number} index The operand to remove.
 * @return {object} The new node.
 */
export function removeOperand(node, index) {
	const op = Object.keys(node)[0]
	return { [op]: node[op].filter((_, i) => i !== index) }
}

/**
 * Read a typed literal out of what a maker typed.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @param {string} text The typed text.
 * @return {number|boolean|string} The literal.
 */
export function parseLiteral(text) {
	const value = String(text ?? '')
	if (value === 'true' || value === 'false') {
		return value === 'true'
	}
	if (value.trim() !== '' && !Number.isNaN(Number(value))) {
		return Number(value)
	}
	return value
}

export default {
	name: 'ExpressionNodeEditor',
	components: { NcButton, NcSelect, NcTextField },
	props: {
		node: { type: [Boolean, Object, String, Number], default: null },
		fieldNames: { type: Array, default: () => [] },
		operators: { type: Array, default: () => [] },
		depth: { type: Number, default: 0 },
	},

	emits: ['update:node'],
	data() {
		return { pickedOperator: false }
	},

	computed: {
		kind() {
			return this.pickedOperator && this.node === null
				? 'operator'
				: nodeKind(this.node)
		},

		kindOptions() {
			return [
				{ value: 'field', label: this.t('buildiq', 'Field') },
				{ value: 'value', label: this.t('buildiq', 'Value') },
				{ value: 'operator', label: this.t('buildiq', 'Operator') },
			]
		},

		kindOption() {
			return this.kindOptions.find((o) => o.value === this.kind)
		},

		fieldName() {
			const args = this.node?.prop
			return Array.isArray(args) ? args[0] : args || null
		},

		valueText() {
			return this.node === null || this.node === undefined
				? ''
				: String(this.node)
		},

		operatorOptions() {
			return this.operators
				.filter((o) => o.op !== 'prop' && o.op !== 'lit')
				.map((o) => ({ ...o, label: `${o.op} (${o.category})` }))
		},

		operatorOption() {
			if (nodeKind(this.node) !== 'operator') {
				return null
			}
			const op = Object.keys(this.node)[0]
			return this.operatorOptions.find((o) => o.op === op) || null
		},

		operands() {
			return nodeKind(this.node) === 'operator'
				? Object.values(this.node)[0]
				: []
		},

		minOperands() {
			return this.operatorOption
				? arityBounds(this.operatorOption.arity).min
				: 0
		},

		isOpenArity() {
			return this.operatorOption
				? arityBounds(this.operatorOption.arity).open
				: false
		},
	},

	methods: {
		addOperand,
		removeOperand,
		parseLiteral,
		/**
		 * Emit a replaced node.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
		 * @param {unknown} node The new node.
		 * @return {void}
		 */
		emitNode(node) {
			this.$emit('update:node', node)
		},

		/**
		 * Switch the node's kind.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
		 * @param {{value: string}} option The chosen kind.
		 * @return {void}
		 */
		onKind(option) {
			this.pickedOperator = option?.value === 'operator'
			this.emitNode(nodeForKind(option?.value, this.fieldNames))
		},

		/**
		 * Choose the operator of an operator node.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
		 * @param {object} option The chosen catalogue row.
		 * @return {void}
		 */
		onOperator(option) {
			if (option) {
				this.emitNode(withOperator(this.node, option))
			}
		},

		/**
		 * Replace one operand.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
		 * @param {number} index The operand.
		 * @param {unknown} operand Its new node.
		 * @return {void}
		 */
		onOperand(index, operand) {
			const op = Object.keys(this.node)[0]
			const next = [...this.node[op]]
			next[index] = operand
			this.emitNode({ [op]: next })
		},
	},
}
</script>

<style scoped>
.buildiq-expr-node {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.buildiq-expr-node--nested {
	padding-inline-start: 12px;
	border-inline-start: 2px solid var(--color-border);
}

.buildiq-expr-node__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.buildiq-expr-node__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-expr-node__operands {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
