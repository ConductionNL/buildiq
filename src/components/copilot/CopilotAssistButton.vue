<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CopilotAssistButton — the "Ask AI" button beside a builder expression
  - field (ai-copilot-documents-and-code-help D5, REQ-BQCH-001). It renders
  - nothing while the copilot health probe (the same session-cached probe the
  - wizard uses) says the copilot is unavailable, so an instance without a
  - text2text provider sees the editor exactly as before. It opens
  - CopilotAssistDialog and re-emits an accepted value as `accept`.
  -->
<template>
	<span v-if="available" class="copilot-assist-button">
		<NcButton
			variant="tertiary"
			:aria-label="t('buildiq', 'Ask AI')"
			:title="t('buildiq', 'Ask AI')"
			data-testid="copilot-assist-button"
			@click="dialogOpen = true">
			<template #icon>
				<CreationIcon :size="20" />
			</template>
		</NcButton>
		<CopilotAssistDialog
			v-model:open="dialogOpen"
			:target="target"
			:appSlug="appSlug"
			:current="current"
			:context="context"
			@accept="$emit('accept', $event)" />
	</span>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import CreationIcon from 'vue-material-design-icons/Creation.vue'
import CopilotAssistDialog from '../../dialogs/CopilotAssistDialog.vue'
import { useCopilot } from '../../composables/useCopilot.js'

export default {
	name: 'CopilotAssistButton',
	components: { CopilotAssistDialog, CreationIcon, NcButton },
	props: {
		/** `feel-condition` | `decision-rule` | `field-mapping` | `payload-template`. */
		target: { type: String, required: true },
		/** The app being edited; without one there is nothing to scope the help to. */
		appSlug: { type: String, default: '' },
		/** The editor's current value. */
		current: { type: [String, Object], default: '' },
		/** Names only: columns, paths, register, targetSchema, triggerSchema. */
		context: { type: Object, default: () => ({}) },
	},

	emits: ['accept'],
	/**
	 * Expose the copilot health probe to the template.
	 *
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-expression-editors-offer-an-ask-ai-button-req-bqch-001
	 */
	setup() {
		return { copilot: useCopilot() }
	},

	data() {
		return { dialogOpen: false }
	},

	computed: {
		/**
		 * Whether the button shows: a provider is there and the field belongs to an app.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-expression-editors-offer-an-ask-ai-button-req-bqch-001
		 */
		available() {
			return this.copilot.isAvailable.value && this.appSlug !== ''
		},
	},

	mounted() {
		this.copilot.checkHealth()
	},
}
</script>

<style scoped>
.copilot-assist-button {
	display: inline-flex;
	align-items: center;
}
</style>
