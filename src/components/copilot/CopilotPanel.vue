<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CopilotPanel — chat-style builder side panel (spec `ai-copilot`
  REQ-OBAIC-007), scoped to the currently edited virtual application +
  version. Every user message produces one assistant turn whose proposed
  operations render as a CopilotProposal card (step list + manifest diff).
  The panel sends an execute request ONLY on Approve — no request is ever
  sent before an explicit approval, and a bare-copilot Discarded proposal
  leaves no trace: the input is simply re-enabled for the next message.

  Optional agent-scoping props (spec `agent-workspace` design.md Decision 3)
  — `agentId`/`instructions`/`enabledTools` — let this SAME component serve
  as the per-agent chat surface on AgentsPage.vue. Omitted entirely (the
  bare copilot's page-designer usage), the panel behaves exactly as before
  this change: `enabledTools` is a client-side HINT only (e.g. a future
  greyed-out affordance) — never the security boundary, which is always the
  server-side allow-list check in CopilotService.
-->
<template>
	<div data-testid="copilot-panel" class="copilot-panel">
		<div v-if="agentId" data-testid="copilot-acting-as" class="copilot-panel__acting-as">
			<strong>{{ t('openbuild', 'Acting as:') }} {{ name || agentId }}</strong>
			<span v-if="instructions" class="copilot-panel__acting-as-instructions">{{ instructions }}</span>
		</div>

		<div class="copilot-panel__messages">
			<div
				v-for="message in messages"
				:key="message.id"
				class="copilot-panel__message"
				:class="`copilot-panel__message--${message.role}`">
				<p v-if="message.role === 'user'" class="copilot-panel__bubble copilot-panel__bubble--user">
					{{ message.text }}
				</p>
				<CopilotProposal
					v-else-if="message.role === 'assistant' && message.plan"
					:plan="message.plan"
					:can-approve="isPendingProposal(message) ? canApprove : false"
					:busy="isPendingProposal(message) && state === 'executing'"
					@approve="onApprove(message)"
					@discard="onDiscard(message)" />
				<p v-else-if="message.role === 'assistant'" class="copilot-panel__bubble copilot-panel__bubble--error">
					{{ message.error }}
				</p>
			</div>
		</div>

		<div class="copilot-panel__input-row">
			<textarea
				v-model="draft"
				data-testid="copilot-message-input"
				class="copilot-panel__input"
				:disabled="inputDisabled"
				:placeholder="t('openbuild', 'Ask the copilot to add a page, widget, or menu item…')"
				:aria-label="t('openbuild', 'Ask the copilot to add a page, widget, or menu item…')"
				rows="2"
				@keydown.enter.exact.prevent="onSend" />
			<NcButton type="primary" :disabled="inputDisabled || !draft.trim()" @click="onSend">
				{{ t('openbuild', 'Send') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useCopilot } from '../../composables/useCopilot.js'
import CopilotProposal from './CopilotProposal.vue'

export default {
	name: 'CopilotPanel',

	components: { NcButton, CopilotProposal },

	props: {
		/** The virtual app slug this panel is scoped to. */
		appSlug: {
			type: String,
			required: true,
		},
		/**
		 * Optional Agent id (spec `agent-workspace` design.md Decision 3).
		 * Omitted entirely, this panel behaves exactly as the bare copilot
		 * does today — this is the ONLY prop that changes server-side
		 * behaviour; `name`/`instructions`/`enabledTools` below are display-only.
		 */
		agentId: {
			type: String,
			default: '',
		},
		/** The agent's name, shown in the "Acting as" header when `agentId` is set. */
		name: {
			type: String,
			default: '',
		},
		/** The agent's instructions, shown as a hint under the "Acting as" header. */
		instructions: {
			type: String,
			default: '',
		},
		/**
		 * The agent's effective enabled tools — a client-side HINT only
		 * (e.g. future greyed-out affordance). Never the security boundary,
		 * which is always the server-side allow-list check in CopilotService.
		 */
		enabledTools: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['executed'],

	setup() {
		return { copilot: useCopilot() }
	},

	data() {
		return {
			draft: '',
			messages: [],
			nextMessageId: 1,
			pendingMessageId: null,
		}
	},

	computed: {
		/**
		 * The copilot state machine's current state.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		state() {
			return this.copilot.state.value
		},
		/**
		 * Whether the pending proposal's predicted manifests all pass the
		 * canonical manifest v2 validator.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		canApprove() {
			return this.copilot.canApprove.value
		},
		/**
		 * The input is disabled while a plan is in flight or a proposal is
		 * pending review/execution — the user must Approve or Discard first
		 * (single-pending-proposal-at-a-time keeps the state machine simple
		 * and matches "no silent mutations": exactly one reviewable proposal
		 * exists at a time).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		inputDisabled() {
			return this.state === 'planning' || this.state === 'review' || this.state === 'executing'
		},
	},

	methods: {
		/**
		 * Whether a rendered assistant message is the one the current copilot
		 * state machine instance still tracks (i.e. not yet approved/discarded).
		 *
		 * @param {object} message - a message list entry.
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		isPendingProposal(message) {
			return message.id === this.pendingMessageId
		},

		/**
		 * Send the draft as a new user turn and request a plan.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		async onSend() {
			const text = this.draft.trim()
			if (!text || this.inputDisabled) {
				return
			}
			this.messages.push({ id: this.nextMessageId++, role: 'user', text })
			this.draft = ''

			await this.copilot.generatePlan(text, this.appSlug, this.agentId || undefined)

			const assistantId = this.nextMessageId++
			if (this.copilot.state.value === 'review') {
				this.pendingMessageId = assistantId
				this.messages.push({ id: assistantId, role: 'assistant', plan: this.copilot.plan.value })
			} else {
				this.messages.push({ id: assistantId, role: 'assistant', error: this.copilot.errorMessage.value })
			}
		},

		/**
		 * Approve the pending proposal — the ONLY path that sends an execute request.
		 *
		 * @param {object} message - the assistant message carrying the plan.
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		async onApprove(message) {
			if (!this.isPendingProposal(message)) {
				return
			}
			await this.copilot.approve(this.agentId || undefined)
			if (this.copilot.state.value === 'done') {
				this.pendingMessageId = null
				this.copilot.discard()
				this.$emit('executed')
			}
		},

		/**
		 * Discard the pending proposal — sends no request; the app's manifest is unchanged.
		 *
		 * @param {object} message - the assistant message carrying the plan.
		 * @return {void}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		onDiscard(message) {
			if (!this.isPendingProposal(message)) {
				return
			}
			this.pendingMessageId = null
			this.copilot.discard(this.agentId || undefined)
		},
	},
}
</script>

<style scoped>
.copilot-panel {
	display: flex;
	flex-direction: column;
	height: 100%;
	gap: 8px;
}

.copilot-panel__messages {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.copilot-panel__acting-as {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	font-size: 0.85em;
}

.copilot-panel__acting-as-instructions {
	color: var(--color-text-maxcontrast);
}

.copilot-panel__bubble {
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin: 0;
	max-width: 90%;
}

.copilot-panel__bubble--user {
	background: var(--color-primary-element-light);
	align-self: flex-end;
}

.copilot-panel__bubble--error {
	color: var(--color-error);
}

.copilot-panel__input-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.copilot-panel__input {
	flex: 1;
	resize: vertical;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	padding: 8px;
	font: inherit;
}
</style>
