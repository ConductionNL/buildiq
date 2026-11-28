<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - BuildiqChatPage: the chat page of a running virtual app (REQ-BQCV-009).
  -
  - A chat page bound to a register and schema is a conversation kept as that
  - schema's records: nextcloud-vue's CnConversationThread shows them oldest
  - first and posts a new message as a new record (its text under the page's
  - `messageField`), then reads the thread again so it appears.
  -
  - A page with a `conversationSource` (an embedded Talk conversation) or only
  - a `postUrl` renders exactly as CnChatPage.
  -->
<template>
	<div v-if="bound" class="buildiq-chat-page" data-testid="buildiq-chat-page">
		<CnConversationThread
			:title="title"
			:messages="messages"
			:loading="loading"
			:endpoint="endpoint"
			:messageKey="field"
			:placeholder="t('buildiq', 'Write a message')"
			@sent="load" />
		<p v-if="failed" class="buildiq-chat-page__error" role="alert">
			{{ t('buildiq', 'The conversation could not be loaded.') }}
		</p>
	</div>
	<CnChatPage
		v-else
		v-bind="$attrs"
		:title="title"
		:conversationSource="conversationSource"
		:postUrl="postUrl"
		:schema="schema">
		<template v-for="(_, name) in $slots" #[name]="slotProps">
			<slot :name="name" v-bind="slotProps || {}" />
		</template>
	</CnChatPage>
</template>

<script>
import { CnChatPage, CnConversationThread } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import {
	DEFAULT_MESSAGE_FIELD,
	fetchMessages,
	messagesPath,
} from '../../services/messageThread.js'

export default {
	name: 'BuildiqChatPage',

	components: { CnChatPage, CnConversationThread },

	inheritAttrs: false,

	props: {
		/** The page title. */
		title: {
			type: String,
			default: '',
		},

		/** The register the messages are kept in. */
		register: {
			type: String,
			default: '',
		},

		/** The schema of a message. */
		schema: {
			type: String,
			default: '',
		},

		/** The field a message's text is kept in. */
		messageField: {
			type: String,
			default: '',
		},

		/** An embedded conversation (Talk) URL. */
		conversationSource: {
			type: String,
			default: '',
		},

		/** A send endpoint for a custom chat UI. */
		postUrl: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			messages: [],
			loading: false,
			failed: false,
		}
	},

	computed: {
		/**
		 * Whether the conversation is kept as records of a schema.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		bound() {
			return this.conversationSource === '' && this.register !== '' && this.schema !== ''
		},

		/**
		 * The field a message's text is kept in.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		field() {
			return this.messageField || DEFAULT_MESSAGE_FIELD
		},

		/**
		 * Where a new message is posted: a new record of the schema.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		endpoint() {
			return messagesPath(this.register, this.schema)
		},

		/**
		 * What the thread depends on.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		threadKey() {
			return this.bound ? [this.register, this.schema, this.field].join('|') : ''
		},
	},

	watch: {
		threadKey: {
			/**
			 * Read the thread again when its binding changes.
			 *
			 * @return {void}
			 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
			 */
			handler() {
				this.load()
			},

			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Read the messages of the thread.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		async load() {
			if (!this.bound) {
				return
			}
			this.loading = true
			this.failed = false
			try {
				const user = getCurrentUser()
				this.messages = await fetchMessages({
					register: this.register,
					schema: this.schema,
					field: this.field,
					me: user ? user.uid : '',
				})
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.buildiq-chat-page {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.buildiq-chat-page__error {
	margin: 0;
	color: var(--color-error-text);
}
</style>
