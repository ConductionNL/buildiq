<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CopilotAssistDialog — the Ask AI dialog of the builder assist
  - (ai-copilot-documents-and-code-help D5). The maker types a plain request
  - and gets a proposal the server has already checked, with a one-line
  - explanation, then Accepts or Rejects it; or asks what the current value
  - does. Accept only emits the value: the editor stages it and its own Save
  - decides whether anything persists. Reject emits nothing but the close.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('buildiq', 'Ask AI')"
		size="normal"
		@closing="close">
		<div class="copilot-assist" data-testid="copilot-assist-dialog">
			<NcTextArea
				v-model="request"
				:label="t('buildiq', 'What should it do?')"
				:disabled="busy"
				resize="vertical"
				data-testid="copilot-assist-request" />

			<p class="copilot-assist__hint">
				{{ t('buildiq', 'Check the proposal before you accept it. Nothing is saved until you save the editor.') }}
			</p>

			<div v-if="busy" class="copilot-assist__busy">
				<NcLoadingIcon :size="20" />
				<span>{{ t('buildiq', 'Asking the AI') }}</span>
			</div>

			<NcNoteCard
				v-if="errorMessage"
				type="error"
				data-testid="copilot-assist-error">
				{{ errorMessage }}
			</NcNoteCard>

			<div
				v-if="answer"
				class="copilot-assist__answer"
				data-testid="copilot-assist-answer"
				aria-live="polite">
				<template v-if="answer.mode === 'write'">
					<h3 class="copilot-assist__heading">
						{{ t('buildiq', 'Proposal') }}
					</h3>
					<pre class="copilot-assist__value" data-testid="copilot-assist-value">{{ displayValue }}</pre>
				</template>
				<p class="copilot-assist__explanation" data-testid="copilot-assist-explanation">
					{{ answer.explanation }}
				</p>
			</div>
		</div>

		<template #actions>
			<template v-if="answer && answer.mode === 'write'">
				<NcButton data-testid="copilot-assist-reject" @click="reject">
					{{ t('buildiq', 'Reject') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="copilot-assist-accept"
					@click="accept">
					{{ t('buildiq', 'Accept') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton
					:disabled="busy || !hasCurrent"
					data-testid="copilot-assist-explain"
					@click="send('explain')">
					{{ t('buildiq', 'Explain') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !request.trim()"
					data-testid="copilot-assist-write"
					@click="send('write')">
					{{ t('buildiq', 'Write it') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { assist } from '../services/copilot.js'

export default {
	name: 'CopilotAssistDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextArea },
	props: {
		/** Whether the dialog is shown (bind with `v-model:open`). */
		open: { type: Boolean, default: false },
		/** `feel-condition` | `decision-rule` | `field-mapping` | `payload-template`. */
		target: { type: String, required: true },
		/** The app being edited. */
		appSlug: { type: String, required: true },
		/** The editor's current value, explained in explain mode. */
		current: { type: [String, Object], default: '' },
		/** Names only: columns, paths, register, targetSchema, triggerSchema. */
		context: { type: Object, default: () => ({}) },
	},

	emits: ['update:open', 'accept'],
	data() {
		return {
			request: '',
			busy: false,
			answer: null,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * The current value as the text sent to the server.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
		 */
		currentText() {
			if (this.current && typeof this.current === 'object') {
				return JSON.stringify(this.current)
			}
			return String(this.current || '')
		},

		/**
		 * Whether there is a current value to explain.
		 *
		 * @return {boolean}
		 */
		hasCurrent() {
			return this.currentText.trim() !== ''
		},

		/**
		 * The proposed value as shown to the maker.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
		 */
		displayValue() {
			const value = this.answer ? this.answer.value : ''
			if (value && typeof value === 'object') {
				return JSON.stringify(value, null, 2)
			}
			return String(value ?? '')
		},
	},

	watch: {
		/**
		 * Start every opening from an empty request and no answer.
		 *
		 * @param {boolean} shown Whether the dialog just opened.
		 * @return {void}
		 *
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
		 */
		open(shown) {
			if (shown) {
				this.reset()
			}
		},
	},

	methods: {
		/**
		 * Clear the request, the answer and the error.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
		 */
		reset() {
			this.request = ''
			this.answer = null
			this.errorMessage = ''
			this.busy = false
		},

		/**
		 * Ask the server to write a value or explain the current one.
		 *
		 * @param {string} mode `write` or `explain`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
		 */
		async send(mode) {
			this.busy = true
			this.errorMessage = ''
			this.answer = null
			try {
				this.answer = await assist({
					target: this.target,
					mode,
					request: mode === 'write' ? this.request.trim() : '',
					current: this.currentText,
					appSlug: this.appSlug,
					context: this.context,
				})
			} catch (err) {
				this.errorMessage = (err && err.message) || t('buildiq', 'The AI help failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Hand the proposed value to the editor and close.
		 *
		 * @return {void}
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
		 */
		accept() {
			if (!this.answer || this.answer.mode !== 'write') {
				return
			}
			this.$emit('accept', this.answer.value)
			this.close()
		},

		/**
		 * Drop the proposal and close, leaving the editor untouched.
		 *
		 * @return {void}
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
		 */
		reject() {
			this.answer = null
			this.close()
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
		 */
		close() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.copilot-assist {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.copilot-assist__hint {
	color: var(--color-text-maxcontrast);
}

.copilot-assist__busy {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.copilot-assist__heading {
	margin: 0;
	font-size: var(--default-font-size);
	font-weight: bold;
}

.copilot-assist__value {
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	white-space: pre-wrap;
	word-break: break-word;
}
</style>
