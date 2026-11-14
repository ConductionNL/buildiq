<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WriteActionSwitches — one switch per write action of a page (add, edit,
  - delete). Locked off, with a note, while the page binds a connected
  - database whose source takes no writes (REQ-BQDB-002). Emits `set` with
  - the config key and whether the action shows; the owning editor writes it.
  -->
<template>
	<div class="write-action-switches">
		<label
			v-for="action in actions"
			:key="action.key"
			class="write-action-switches__switch">
			<input
				type="checkbox"
				:checked="config[action.key] !== false"
				:disabled="readOnly"
				:data-test="'write-action-' + action.key"
				@change="$emit('set', action.key, $event.target.checked)" />
			{{ action.label }}
		</label>
		<p
			v-if="readOnly"
			class="write-action-switches__note"
			data-test="write-actions-read-only">
			{{
				t(
					'buildiq',
					'This database is read only. Ask an administrator to allow writes.',
				)
			}}
		</p>
	</div>
</template>

<script>
export default {
	name: 'WriteActionSwitches',
	props: {
		// The page config; only the action keys are read.
		config: { type: Object, default: () => ({}) },
		// The write actions of the page type, as `{key, label}`.
		actions: { type: Array, default: () => [] },
		// Whether the bound database takes no writes.
		readOnly: { type: Boolean, default: false },
	},

	emits: ['set'],
}
</script>

<style scoped>
.write-action-switches {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.write-action-switches__switch {
	display: flex;
	align-items: center;
	gap: 6px;
}

.write-action-switches__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
