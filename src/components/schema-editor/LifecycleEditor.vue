<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - LifecycleEditor — authors `x-openregister-lifecycle` declaratively
  - (REQ-OBSD-004 + ADR-031). States + transitions + typed
  - `on_transition` actions drawn from a fixed enum. No free-text PHP /
  - JS fields anywhere — every action type is an enum, every payload
  - field is a typed input.
  -->
<template>
	<section class="openbuild-lifecycle-editor">
		<header class="openbuild-lifecycle-editor__header">
			<h3>{{ t('openbuild', 'Lifecycle') }}</h3>
			<p class="openbuild-lifecycle-editor__hint">
				{{
					t(
						'openbuild',
						'Declare states and transitions. Every action is a typed declarative record per ADR-031 — no free-text code.',
					)
				}}
			</p>
		</header>

		<!-- States -->
		<div class="openbuild-lifecycle-editor__section">
			<div class="openbuild-lifecycle-editor__section-header">
				<h4>{{ t('openbuild', 'States') }}</h4>
				<NcButton @click="addState">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openbuild', 'Add state') }}
				</NcButton>
			</div>
			<p v-if="states.length === 0" class="openbuild-lifecycle-editor__empty">
				{{ t('openbuild', 'No states yet.') }}
			</p>
			<ul v-else class="openbuild-lifecycle-editor__list">
				<li
					v-for="(state, sIndex) in states"
					:key="state._key"
					class="openbuild-lifecycle-editor__state-row">
					<NcCheckboxRadioSwitch
						type="radio"
						:model-value="state.initial ? state._key : null"
						:value="state._key"
						name="lifecycle-initial-state"
						@update:modelValue="setInitial(sIndex)">
						{{ t('openbuild', 'Initial') }}
					</NcCheckboxRadioSwitch>
					<NcTextField
						:model-value="state.name"
						:label="t('openbuild', 'State slug')"
						:error="!stateNameValid(state, sIndex)"
						:helper-text="
							!stateNameValid(state, sIndex)
								? t(
										'openbuild',
										'State slug must be kebab-case and unique.',
									)
								: ''
						"
						@update:modelValue="updateState(sIndex, 'name', $event)" />
					<NcTextField
						:model-value="state.label"
						:label="t('openbuild', 'Label')"
						@update:modelValue="updateState(sIndex, 'label', $event)" />
					<NcButton
						type="error"
						:aria-label="t('openbuild', 'Remove state')"
						@click="removeState(sIndex)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p
				v-if="states.length > 0 && initialCount !== 1"
				class="openbuild-lifecycle-editor__error">
				{{ t('openbuild', 'Exactly one initial state is required.') }}
			</p>
		</div>

		<!-- Transitions -->
		<div class="openbuild-lifecycle-editor__section">
			<div class="openbuild-lifecycle-editor__section-header">
				<h4>{{ t('openbuild', 'Transitions') }}</h4>
				<NcButton :disabled="states.length < 2" @click="addTransition">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openbuild', 'Add transition') }}
				</NcButton>
			</div>
			<p
				v-if="transitions.length === 0"
				class="openbuild-lifecycle-editor__empty">
				{{ t('openbuild', 'No transitions yet.') }}
			</p>
			<ul v-else class="openbuild-lifecycle-editor__list">
				<li
					v-for="(transition, tIndex) in transitions"
					:key="transition._key"
					class="openbuild-lifecycle-editor__transition-row">
					<div class="openbuild-lifecycle-editor__transition-grid">
						<NcSelect
							:input-label="t('openbuild', 'From')"
							:model-value="stateOption(transition.from)"
							:options="stateOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@update:modelValue="
								updateTransition(
									tIndex,
									'from',
									$event ? $event.value : '',
								)
							" />
						<NcSelect
							:input-label="t('openbuild', 'To')"
							:model-value="stateOption(transition.to)"
							:options="stateOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@update:modelValue="
								updateTransition(
									tIndex,
									'to',
									$event ? $event.value : '',
								)
							" />
						<NcTextField
							:model-value="transition.label || ''"
							:label="t('openbuild', 'Label (optional)')"
							@update:modelValue="
								updateTransition(tIndex, 'label', $event)
							" />
						<NcButton
							type="error"
							:aria-label="t('openbuild', 'Remove transition')"
							@click="removeTransition(tIndex)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</div>

					<!-- Actions for this transition -->
					<div class="openbuild-lifecycle-editor__actions-block">
						<div class="openbuild-lifecycle-editor__section-header">
							<h5>{{ t('openbuild', 'On-transition actions') }}</h5>
							<NcButton @click="addAction(tIndex)">
								<template #icon>
									<PlusIcon :size="18" />
								</template>
								{{ t('openbuild', 'Add action') }}
							</NcButton>
						</div>
						<p
							v-if="
								!transition.actions
								|| transition.actions.length === 0
							"
							class="openbuild-lifecycle-editor__empty">
							{{ t('openbuild', 'No actions on this transition.') }}
						</p>
						<ul v-else class="openbuild-lifecycle-editor__list">
							<li
								v-for="(action, aIndex) in transition.actions"
								:key="action._key"
								class="openbuild-lifecycle-editor__action-row">
								<NcSelect
									:input-label="t('openbuild', 'Action type')"
									:model-value="actionOption(action.type)"
									:options="actionOptions"
									:clearable="false"
									label="label"
									track-by="value"
									@update:modelValue="
										updateAction(
											tIndex,
											aIndex,
											'type',
											$event
												? $event.value
												: 'audit-event-emit',
										)
									" />
								<NcTextField
									:model-value="action.payload || ''"
									:label="
										t('openbuild', 'Payload key (declarative)')
									"
									:placeholder="
										t(
											'openbuild',
											'e.g. event name, template slug',
										)
									"
									@update:modelValue="
										updateAction(
											tIndex,
											aIndex,
											'payload',
											$event,
										)
									" />
								<NcButton
									type="error"
									:aria-label="t('openbuild', 'Remove action')"
									@click="removeAction(tIndex, aIndex)">
									<template #icon>
										<DeleteIcon :size="18" />
									</template>
								</NcButton>
							</li>
						</ul>
					</div>
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

// ADR-031: action types are a fixed enum — no free-text PHP/JS.
const ACTION_TYPES = [
	'audit-event-emit',
	'notification-send',
	'related-object-upsert',
	'related-object-archive',
	'webhook-dispatch',
]

const STATE_NAME_PATTERN = /^[a-z][a-z0-9-]*$/

let keyCounter = 0
function nextKey() {
	keyCounter += 1
	return `lc-${keyCounter}`
}

export default {
	name: 'LifecycleEditor',
	components: {
		DeleteIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		PlusIcon,
	},
	props: {
		states: { type: Array, default: () => [] },
		transitions: { type: Array, default: () => [] },
	},
	emits: ['update:states', 'update:transitions'],
	computed: {
		/**
		 * Count how many states are flagged initial.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @return {number} Initial-state count.
		 */
		initialCount() {
			return this.states.filter((s) => s.initial).length
		},
		/**
		 * Build state picker options from named states.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @return {Array} Option objects.
		 */
		stateOptions() {
			return this.states
				.filter((s) => s.name)
				.map((s) => ({ value: s.name, label: s.label || s.name }))
		},
		/**
		 * Build transition-action-type picker options.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @return {Array} Option objects.
		 */
		actionOptions() {
			return ACTION_TYPES.map((value) => ({
				value,
				label: this.t('openbuild', value),
			}))
		},
	},
	methods: {
		/**
		 * Resolve the selected state option.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {string} value State name.
		 * @return {object|null} Matching option.
		 */
		stateOption(value) {
			return this.stateOptions.find((o) => o.value === value) || null
		},
		/**
		 * Resolve the selected action-type option.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {string} value Action type.
		 * @return {object} Matching option.
		 */
		actionOption(value) {
			return (
				this.actionOptions.find((o) => o.value === value)
				|| this.actionOptions[0]
			)
		},
		/**
		 * Validate a state name: pattern and uniqueness.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {object} state State row.
		 * @param {number} index Row index.
		 * @return {boolean} True when valid.
		 */
		stateNameValid(state, index) {
			if (!STATE_NAME_PATTERN.test(state.name || '')) {
				return false
			}
			const duplicate = this.states.some(
				(other, otherIndex) =>
					otherIndex !== index && other.name === state.name,
			)
			return !duplicate
		},
		/**
		 * Emit the updated states array.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {Array} next Next states array.
		 * @return {void}
		 */
		emitStates(next) {
			this.$emit('update:states', next)
		},
		/**
		 * Emit the updated transitions array.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {Array} next Next transitions array.
		 * @return {void}
		 */
		emitTransitions(next) {
			this.$emit('update:transitions', next)
		},
		/**
		 * Append a new state (first state defaults to initial).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @return {void}
		 */
		addState() {
			const next = this.states.slice()
			next.push({
				_key: nextKey(),
				name: '',
				label: '',
				initial: next.length === 0,
			})
			this.emitStates(next)
		},
		/**
		 * Update a single field of a state row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} index Row index.
		 * @param {string} key Field key.
		 * @param {*} value New value.
		 * @return {void}
		 */
		updateState(index, key, value) {
			const next = this.states.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitStates(next)
		},
		/**
		 * Mark a single state as the initial one (clears the others).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} index Row index.
		 * @return {void}
		 */
		setInitial(index) {
			const next = this.states.map((s, i) => ({ ...s, initial: i === index }))
			this.emitStates(next)
		},
		/**
		 * Remove a state row by index.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} index Row index.
		 * @return {void}
		 */
		removeState(index) {
			const next = this.states.slice()
			next.splice(index, 1)
			this.emitStates(next)
		},
		/**
		 * Append a new transition between the first two states.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @return {void}
		 */
		addTransition() {
			const firstState = this.states[0]?.name || ''
			const secondState = this.states[1]?.name || firstState
			const next = this.transitions.slice()
			next.push({
				_key: nextKey(),
				from: firstState,
				to: secondState,
				label: '',
				actions: [],
			})
			this.emitTransitions(next)
		},
		/**
		 * Update a single field of a transition row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} index Row index.
		 * @param {string} key Field key.
		 * @param {*} value New value.
		 * @return {void}
		 */
		updateTransition(index, key, value) {
			const next = this.transitions.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitTransitions(next)
		},
		/**
		 * Remove a transition row by index.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} index Row index.
		 * @return {void}
		 */
		removeTransition(index) {
			const next = this.transitions.slice()
			next.splice(index, 1)
			this.emitTransitions(next)
		},
		/**
		 * Append a new action to a transition.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} tIndex Transition index.
		 * @return {void}
		 */
		addAction(tIndex) {
			const next = this.transitions.slice()
			const transition = { ...next[tIndex] }
			const actions = (transition.actions || []).slice()
			actions.push({
				_key: nextKey(),
				type: 'audit-event-emit',
				payload: '',
			})
			transition.actions = actions
			next[tIndex] = transition
			this.emitTransitions(next)
		},
		/**
		 * Update a single field of a transition action.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} tIndex Transition index.
		 * @param {number} aIndex Action index.
		 * @param {string} key Field key.
		 * @param {*} value New value.
		 * @return {void}
		 */
		updateAction(tIndex, aIndex, key, value) {
			const next = this.transitions.slice()
			const transition = { ...next[tIndex] }
			const actions = (transition.actions || []).slice()
			actions[aIndex] = { ...actions[aIndex], [key]: value }
			transition.actions = actions
			next[tIndex] = transition
			this.emitTransitions(next)
		},
		/**
		 * Remove an action from a transition.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-3
		 * @param {number} tIndex Transition index.
		 * @param {number} aIndex Action index.
		 * @return {void}
		 */
		removeAction(tIndex, aIndex) {
			const next = this.transitions.slice()
			const transition = { ...next[tIndex] }
			const actions = (transition.actions || []).slice()
			actions.splice(aIndex, 1)
			transition.actions = actions
			next[tIndex] = transition
			this.emitTransitions(next)
		},
	},
}

/**
 * Convert an `x-openregister-lifecycle` block into the editor's
 * `states` + `transitions` arrays.
 *
 * @param {object} lifecycle An `x-openregister-lifecycle` JSON block.
 * @return {{ states: Array, transitions: Array }} Editor model.
 */
export function lifecycleToEditor(lifecycle) {
	if (!lifecycle) {
		return { states: [], transitions: [] }
	}
	const initial = lifecycle.initial
	const states = (lifecycle.states || []).map((s) => {
		const name = typeof s === 'string' ? s : s.name
		const label = typeof s === 'string' ? s : s.label || s.name
		return {
			_key: nextKey(),
			name,
			label,
			initial: name === initial,
		}
	})
	const transitions = (lifecycle.transitions || []).map((tr) => ({
		_key: nextKey(),
		from: tr.from || '',
		to: tr.to || '',
		label: tr.label || '',
		actions: (
			(tr.on_transition && tr.on_transition.actions)
			|| tr.actions
			|| []
		).map((a) => ({
			_key: nextKey(),
			type: a.type || 'audit-event-emit',
			payload: a.payload || a.event || a.template || a.url || '',
		})),
	}))
	return { states, transitions }
}

/**
 * Reduce editor state back into an `x-openregister-lifecycle` block.
 *
 * @param {Array} states Editor state rows.
 * @param {Array} transitions Editor transition rows.
 * @return {object|null} An `x-openregister-lifecycle` block, or null
 *   when there are no states.
 */
export function editorToLifecycle(states, transitions) {
	if (!states || states.length === 0) {
		return null
	}
	const initial = (states.find((s) => s.initial) || states[0]).name
	return {
		initial,
		states: states
			.filter((s) => s.name)
			.map((s) => ({
				name: s.name,
				label: s.label || s.name,
			})),
		transitions: (transitions || [])
			.filter((tr) => tr.from && tr.to)
			.map((tr) => ({
				from: tr.from,
				to: tr.to,
				...(tr.label ? { label: tr.label } : {}),
				...(tr.actions && tr.actions.length > 0
					? {
							on_transition: {
								actions: tr.actions.map((a) => ({
									type: a.type,
									...(a.payload ? { payload: a.payload } : {}),
								})),
							},
						}
					: {}),
			})),
	}
}
</script>

<style scoped>
.openbuild-lifecycle-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.openbuild-lifecycle-editor__header h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 600;
}

.openbuild-lifecycle-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.openbuild-lifecycle-editor__section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	background: var(--color-main-background);
}

.openbuild-lifecycle-editor__section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.openbuild-lifecycle-editor__section-header h4,
.openbuild-lifecycle-editor__section-header h5 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.openbuild-lifecycle-editor__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-lifecycle-editor__state-row,
.openbuild-lifecycle-editor__action-row {
	display: grid;
	grid-template-columns: auto 1fr 1fr auto;
	gap: 8px;
	align-items: center;
}

.openbuild-lifecycle-editor__transition-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.openbuild-lifecycle-editor__transition-grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr auto;
	gap: 8px;
}

.openbuild-lifecycle-editor__actions-block {
	padding-left: 12px;
	border-left: 2px solid var(--color-border);
}

.openbuild-lifecycle-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuild-lifecycle-editor__error {
	margin: 4px 0 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
