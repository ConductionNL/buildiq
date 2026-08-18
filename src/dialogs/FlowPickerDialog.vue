<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FlowPickerDialog — choose which of the application's flows to open.
  -
  - "Edit flows…" used to hard-code the literal id "new", which made the modal
  - a creator that could never edit: every press started a blank canvas, and a
  - flow saved a minute earlier was unreachable from this surface. The picker
  - closes that gap — pick an existing flow to edit it, or start a new one.
  -
  - The list is fetched directly rather than through useFlowStore: the store
  - is a singleton the flow editor itself is about to load into, and warming
  - it up here would race the modal's own `open('new')`.
  -->
<template>
	<NcDialog :name="t('openbuild', 'Flows')" size="normal" @closing="$emit('close')">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-else-if="error" type="error">
			{{ t('openbuild', 'The flows could not be loaded. This does not mean there are none.') }}
		</NcNoteCard>

		<p v-else-if="!flows.length" class="flow-picker__hint">
			{{ t('openbuild', 'This application has no flows yet.') }}
		</p>

		<ul v-else class="flow-picker__list">
			<li v-for="flow in flows" :key="flow.id">
				<button class="flow-picker__row" @click="$emit('pick', String(flow.id))">
					<strong>{{ flow.name }}</strong>
					<span v-if="flow.description" class="flow-picker__hint">{{ flow.description }}</span>
				</button>
			</li>
		</ul>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('pick', 'new')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('openbuild', 'New flow') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'FlowPickerDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		Plus,
	},

	props: {
		/**
		 * The owning app id whose flows are offered. Null lists none.
		 */
		app: {
			type: String,
			default: null,
		},
	},

	emits: [
		/**
		 * @event pick A flow was chosen. Payload is its id, or the literal
		 *   `new` for a blank one.
		 */
		'pick',
		/**
		 * @event close The dialog was dismissed without a choice.
		 */
		'close',
	],

	data() {
		return {
			flows: [],
			loading: true,
			error: null,
		}
	},

	async mounted() {
		try {
			const response = await axios.get(generateUrl('/apps/openregister/api/flows'), {
				params: this.app ? { app: this.app } : {},
			})
			this.flows = response.data?.results || []
		} catch (error) {
			this.error = error
		} finally {
			this.loading = false
		}
	},
}
</script>

<style scoped>
.flow-picker__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.flow-picker__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	inline-size: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: none;
	text-align: start;
	cursor: pointer;
}

.flow-picker__row:hover {
	background: var(--color-background-hover);
}

.flow-picker__hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
