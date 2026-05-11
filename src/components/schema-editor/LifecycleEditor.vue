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
	<section class="openbuilt-lifecycle-editor">
		<header class="openbuilt-lifecycle-editor__header">
			<h3>{{ t('openbuilt', 'Lifecycle') }}</h3>
			<p class="openbuilt-lifecycle-editor__hint">
				{{ t('openbuilt', 'Declare states and transitions. Every action is a typed declarative record per ADR-031 — no free-text code.') }}
			</p>
		</header>

		<!-- States -->
		<div class="openbuilt-lifecycle-editor__section">
			<div class="openbuilt-lifecycle-editor__section-header">
				<h4>{{ t('openbuilt', 'States') }}</h4>
				<NcButton @click="addState">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openbuilt', 'Add state') }}
				</NcButton>
			</div>
			<p v-if="states.length === 0" class="openbuilt-lifecycle-editor__empty">
				{{ t('openbuilt', 'No states yet.') }}
			</p>
			<ul v-else class="openbuilt-lifecycle-editor__list">
				<li
					v-for="(state, sIndex) in states"
					:key="state._key"
					class="openbuilt-lifecycle-editor__state-row">
					<NcCheckboxRadioSwitch
						type="radio"
						:checked="state.initial"
						:value="state._key"
						name="lifecycle-initial-state"
						@update:checked="setInitial(sIndex)">
						{{ t('openbuilt', 'Initial') }}
					</NcCheckboxRadioSwitch>
					<NcTextField
						:value="state.name"
						:label="t('openbuilt', 'State slug')"
						:error="!stateNameValid(state, sIndex)"
						:helper-text="!stateNameValid(state, sIndex) ? t('openbuilt', 'State slug must be kebab-case and unique.') : ''"
						@update:value="updateState(sIndex, 'name', $event)" />
					<NcTextField
						:value="state.label"
						:label="t('openbuilt', 'Label')"
						@update:value="updateState(sIndex, 'label', $event)" />
					<NcButton type="error" @click="removeState(sIndex)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p v-if="states.length > 0 && initialCount !== 1" class="openbuilt-lifecycle-editor__error">
				{{ t('openbuilt', 'Exactly one initial state is required.') }}
			</p>
		</div>

		<!-- Transitions -->
		<div class="openbuilt-lifecycle-editor__section">
			<div class="openbuilt-lifecycle-editor__section-header">
				<h4>{{ t('openbuilt', 'Transitions') }}</h4>
				<NcButton :disabled="states.length < 2" @click="addTransition">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('openbuilt', 'Add transition') }}
				</NcButton>
			</div>
			<p v-if="transitions.length === 0" class="openbuilt-lifecycle-editor__empty">
				{{ t('openbuilt', 'No transitions yet.') }}
			</p>
			<ul v-else class="openbuilt-lifecycle-editor__list">
				<li
					v-for="(transition, tIndex) in transitions"
					:key="transition._key"
					class="openbuilt-lifecycle-editor__transition-row">
					<div class="openbuilt-lifecycle-editor__transition-grid">
						<NcSelect
							:input-label="t('openbuilt', 'From')"
							:value="stateOption(transition.from)"
							:options="stateOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@input="updateTransition(tIndex, 'from', $event ? $event.value : '')" />
						<NcSelect
							:input-label="t('openbuilt', 'To')"
							:value="stateOption(transition.to)"
							:options="stateOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@input="updateTransition(tIndex, 'to', $event ? $event.value : '')" />
						<NcTextField
							:value="transition.label || ''"
							:label="t('openbuilt', 'Label (optional)')"
							@update:value="updateTransition(tIndex, 'label', $event)" />
						<NcButton type="error" @click="removeTransition(tIndex)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</div>

					<!-- Actions for this transition -->
					<div class="openbuilt-lifecycle-editor__actions-block">
						<div class="openbuilt-lifecycle-editor__section-header">
							<h5>{{ t('openbuilt', 'On-transition actions') }}</h5>
							<NcButton @click="addAction(tIndex)">
								<template #icon>
									<PlusIcon :size="18" />
								</template>
								{{ t('openbuilt', 'Add action') }}
							</NcButton>
						</div>
						<p v-if="!transition.actions || transition.actions.length === 0" class="openbuilt-lifecycle-editor__empty">
							{{ t('openbuilt', 'No actions on this transition.') }}
						</p>
						<ul v-else class="openbuilt-lifecycle-editor__list">
							<li
								v-for="(action, aIndex) in transition.actions"
								:key="action._key"
								class="openbuilt-lifecycle-editor__action-row">
								<NcSelect
									:input-label="t('openbuilt', 'Action type')"
									:value="actionOption(action.type)"
									:options="actionOptions"
									:clearable="false"
									label="label"
									track-by="value"
									@input="updateAction(tIndex, aIndex, 'type', $event ? $event.value : 'audit-event-emit')" />
								<NcTextField
									:value="action.payload || ''"
									:label="t('openbuilt', 'Payload key (declarative)')"
									:placeholder="t('openbuilt', 'e.g. event name, template slug')"
									@update:value="updateAction(tIndex, aIndex, 'payload', $event)" />
								<NcButton type="error" @click="removeAction(tIndex, aIndex)">
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
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
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
		initialCount() {
			return this.states.filter((s) => s.initial).length
		},
		stateOptions() {
			return this.states
				.filter((s) => s.name)
				.map((s) => ({ value: s.name, label: s.label || s.name }))
		},
		actionOptions() {
			return ACTION_TYPES.map((value) => ({
				value,
				label: this.t('openbuilt', value),
			}))
		},
	},
	methods: {
		stateOption(value) {
			return this.stateOptions.find((o) => o.value === value) || null
		},
		actionOption(value) {
			return this.actionOptions.find((o) => o.value === value) || this.actionOptions[0]
		},
		stateNameValid(state, index) {
			if (!STATE_NAME_PATTERN.test(state.name || '')) {
				return false
			}
			const duplicate = this.states.some((other, otherIndex) => otherIndex !== index && other.name === state.name)
			return !duplicate
		},
		emitStates(next) {
			this.$emit('update:states', next)
		},
		emitTransitions(next) {
			this.$emit('update:transitions', next)
		},
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
		updateState(index, key, value) {
			const next = this.states.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitStates(next)
		},
		setInitial(index) {
			const next = this.states.map((s, i) => ({ ...s, initial: i === index }))
			this.emitStates(next)
		},
		removeState(index) {
			const next = this.states.slice()
			next.splice(index, 1)
			this.emitStates(next)
		},
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
		updateTransition(index, key, value) {
			const next = this.transitions.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitTransitions(next)
		},
		removeTransition(index) {
			const next = this.transitions.slice()
			next.splice(index, 1)
			this.emitTransitions(next)
		},
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
		updateAction(tIndex, aIndex, key, value) {
			const next = this.transitions.slice()
			const transition = { ...next[tIndex] }
			const actions = (transition.actions || []).slice()
			actions[aIndex] = { ...actions[aIndex], [key]: value }
			transition.actions = actions
			next[tIndex] = transition
			this.emitTransitions(next)
		},
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
		const label = typeof s === 'string' ? s : (s.label || s.name)
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
		actions: ((tr.on_transition && tr.on_transition.actions) || tr.actions || []).map((a) => ({
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
		states: states.filter((s) => s.name).map((s) => ({
			name: s.name,
			label: s.label || s.name,
		})),
		transitions: (transitions || []).filter((tr) => tr.from && tr.to).map((tr) => ({
			from: tr.from,
			to: tr.to,
			...(tr.label ? { label: tr.label } : {}),
			...((tr.actions && tr.actions.length > 0)
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
.openbuilt-lifecycle-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.openbuilt-lifecycle-editor__header h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-lifecycle-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.openbuilt-lifecycle-editor__section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	background: var(--color-main-background);
}

.openbuilt-lifecycle-editor__section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.openbuilt-lifecycle-editor__section-header h4,
.openbuilt-lifecycle-editor__section-header h5 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.openbuilt-lifecycle-editor__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-lifecycle-editor__state-row,
.openbuilt-lifecycle-editor__action-row {
	display: grid;
	grid-template-columns: auto 1fr 1fr auto;
	gap: 8px;
	align-items: center;
}

.openbuilt-lifecycle-editor__transition-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.openbuilt-lifecycle-editor__transition-grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr auto;
	gap: 8px;
}

.openbuilt-lifecycle-editor__actions-block {
	padding-left: 12px;
	border-left: 2px solid var(--color-border);
}

.openbuilt-lifecycle-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuilt-lifecycle-editor__error {
	margin: 4px 0 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
