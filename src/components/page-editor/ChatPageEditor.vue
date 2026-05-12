<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ChatPageEditor — structured editor for `type: "chat"` pages (task 4.6).
  -
  - Manifest contract: `{ conversationSource?, postUrl?, schema? }` where
  - EXACTLY ONE of `conversationSource` (an SSE / message-list endpoint)
  - OR `postUrl` (the endpoint new messages are POSTed to) MUST be set;
  - `schema` is the optional message-record schema slug.
  -
  - The one-of is rendered as a radio pair; switching branches clears the
  - inactive key. `update(key, value)` clones `config` and only mutates
  - the one key so externally-authored extra keys round-trip losslessly.
  -->
<template>
	<div class="chat-page-editor">
		<h3 class="chat-page-editor__title">
			{{ t('openbuilt', 'Chat page') }}
		</h3>

		<fieldset class="chat-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Conversation transport') }}</legend>
			<div class="chat-page-editor__shape">
				<label class="chat-page-editor__inline">
					<input
						type="radio"
						:checked="transportShape === 'conversationSource'"
						value="conversationSource"
						@change="setTransportShape('conversationSource')">
					{{ t('openbuilt', 'conversationSource (message stream)') }}
				</label>
				<label class="chat-page-editor__inline">
					<input
						type="radio"
						:checked="transportShape === 'postUrl'"
						value="postUrl"
						@change="setTransportShape('postUrl')">
					{{ t('openbuilt', 'postUrl (send endpoint)') }}
				</label>
			</div>
			<label v-if="transportShape === 'conversationSource'" class="chat-page-editor__group-row">
				{{ t('openbuilt', 'conversationSource') }}
				<input
					type="text"
					:value="config.conversationSource || ''"
					:placeholder="t('openbuilt', '/api/objects/:slug/messages or a stream URL')"
					:aria-invalid="isInvalid('conversationSource')"
					@input="setTransport('conversationSource', $event.target.value)">
				<InlineFieldMark :error="markFor('conversationSource')" />
			</label>
			<label v-else class="chat-page-editor__group-row">
				{{ t('openbuilt', 'postUrl') }}
				<input
					type="text"
					:value="config.postUrl || ''"
					:placeholder="t('openbuilt', '/api/objects/:slug/messages')"
					:aria-invalid="isInvalid('postUrl')"
					@input="setTransport('postUrl', $event.target.value)">
				<InlineFieldMark :error="markFor('postUrl')" />
			</label>
			<p class="chat-page-editor__hint">
				{{ t('openbuilt', 'Exactly one of conversationSource or postUrl must be set.') }}
			</p>
		</fieldset>

		<fieldset class="chat-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Message schema (optional)') }}</legend>
			<label class="chat-page-editor__group-row">
				{{ t('openbuilt', 'Schema slug') }}
				<input
					type="text"
					:value="config.schema || ''"
					:placeholder="t('openbuilt', 'e.g. message')"
					:aria-invalid="isInvalid('schema')"
					@input="update('schema', $event.target.value)">
				<InlineFieldMark :error="markFor('schema')" />
			</label>
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'ChatPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'chat',
		},
		appSlug: {
			type: String,
			default: '',
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	computed: {
		validatedConfigKeys() {
			return ['conversationSource', 'postUrl', 'schema']
		},
		transportShape() {
			if (this.config.postUrl && !this.config.conversationSource) {
				return 'postUrl'
			}
			return 'conversationSource'
		},
	},
	methods: {
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		setTransportShape(shape) {
			const next = { ...this.config }
			if (shape === 'postUrl') {
				delete next.conversationSource
			} else {
				delete next.postUrl
			}
			this.$emit('update:config', next)
		},
		setTransport(key, value) {
			const partner = key === 'postUrl' ? 'conversationSource' : 'postUrl'
			const next = { ...this.config }
			delete next[partner]
			if (value === '') {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped>
.chat-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}
.chat-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}
.chat-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.chat-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}
.chat-page-editor__shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.chat-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}
.chat-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}
.chat-page-editor__group-row input {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.chat-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
