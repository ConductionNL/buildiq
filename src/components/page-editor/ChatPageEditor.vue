<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ChatPageEditor — structured editor for `type: "chat"` pages (task 4.6).
  -
  - Manifest contract: `{ conversationSource?, postUrl?, schema? }` where
  - EXACTLY ONE of `conversationSource` (an SSE / message-list endpoint)
  - OR `postUrl` (the endpoint new messages are POSTed to) MUST be set;
  - `schema` is the optional message-record schema slug.
  -
  - A third transport keeps the conversation as the records of a schema
  - (REQ-BQCV-009): `{ register, schema, messageField?, postUrl }`, where
  - `postUrl` is the schema's records address so a new message is a new
  - record; the running app's BuildiqChatPage reads the thread from them.
  -
  - The one-of is rendered as a radio pair; switching branches clears the
  - inactive key. `update(key, value)` clones `config` and only mutates
  - the one key so externally-authored extra keys round-trip losslessly.
  -->
<template>
	<div class="chat-page-editor">
		<h3 class="chat-page-editor__title">
			{{ t('buildiq', 'Chat page') }}
		</h3>

		<fieldset class="chat-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Conversation transport') }}</legend>
			<div class="chat-page-editor__shape">
				<label class="chat-page-editor__inline">
					<input
						type="radio"
						:checked="transportShape === 'conversationSource'"
						value="conversationSource"
						@change="setTransportShape('conversationSource')" />
					{{ t('buildiq', 'conversationSource (message stream)') }}
				</label>
				<label class="chat-page-editor__inline">
					<input
						type="radio"
						:checked="transportShape === 'postUrl'"
						value="postUrl"
						@change="setTransportShape('postUrl')" />
					{{ t('buildiq', 'postUrl (send endpoint)') }}
				</label>
				<label class="chat-page-editor__inline">
					<input
						type="radio"
						:checked="transportShape === 'records'"
						value="records"
						@change="setTransportShape('records')" />
					{{ t('buildiq', 'Messages kept as records of a schema') }}
				</label>
			</div>
			<template v-if="transportShape === 'records'">
				<label class="chat-page-editor__group-row">
					{{ t('buildiq', 'Register slug') }}
					<input
						type="text"
						class="chat-page-editor__register"
						:value="config.register || ''"
						:placeholder="t('buildiq', 'e.g. permits')"
						@input="setRecords('register', $event.target.value)" />
				</label>
				<label class="chat-page-editor__group-row">
					{{ t('buildiq', 'Message field') }}
					<input
						type="text"
						class="chat-page-editor__message-field"
						:value="config.messageField || ''"
						:placeholder="t('buildiq', 'message')"
						@input="setRecords('messageField', $event.target.value)" />
				</label>
			</template>
			<label
				v-else-if="transportShape === 'conversationSource'"
				class="chat-page-editor__group-row">
				{{ t('buildiq', 'conversationSource') }}
				<input
					type="text"
					:value="config.conversationSource || ''"
					:placeholder="
						t('buildiq', '/api/objects/:slug/messages or a stream URL')
					"
					:aria-invalid="isInvalid('conversationSource')"
					@input="
						setTransport('conversationSource', $event.target.value)
					" />
				<InlineFieldMark :error="markFor('conversationSource')" />
			</label>
			<label v-else-if="transportShape === 'postUrl'" class="chat-page-editor__group-row">
				{{ t('buildiq', 'postUrl') }}
				<input
					type="text"
					:value="config.postUrl || ''"
					:placeholder="t('buildiq', '/api/objects/:slug/messages')"
					:aria-invalid="isInvalid('postUrl')"
					@input="setTransport('postUrl', $event.target.value)" />
				<InlineFieldMark :error="markFor('postUrl')" />
			</label>
			<p class="chat-page-editor__hint">
				{{
					t(
						'buildiq',
						'Exactly one of conversationSource or postUrl must be set.',
					)
				}}
			</p>
		</fieldset>

		<fieldset class="chat-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Message schema (optional)') }}</legend>
			<label class="chat-page-editor__group-row">
				{{ t('buildiq', 'Schema slug') }}
				<input
					type="text"
					class="chat-page-editor__schema"
					:value="config.schema || ''"
					:placeholder="t('buildiq', 'e.g. message')"
					:aria-invalid="isInvalid('schema')"
					@input="transportShape === 'records' ? setRecords('schema', $event.target.value) : update('schema', $event.target.value)" />
				<InlineFieldMark :error="markFor('schema')" />
			</label>
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorBaseMixin } from '../../mixins/pageEditorBase.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'
import { messagesPath } from '../../services/messageThread.js'

/**
 * The records transport with `postUrl` on the schema's records address, or
 * without one while the register or schema is not set.
 *
 * @param {object} config The page config.
 * @return {object} The config.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
 */
function withRecordsPostUrl(config) {
	const next = { ...config }
	if (next.register && next.schema) {
		next.postUrl = messagesPath(next.register, next.schema)
	} else {
		delete next.postUrl
	}
	return next
}

export default {
	name: 'ChatPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorBaseMixin, pageEditorValidationMixin],
	props: {
		pageType: {
			type: String,
			default: 'chat',
		},
	},

	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['conversationSource', 'postUrl', 'schema']
		},

		/**
		 * Observed behaviour of `transportShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		transportShape() {
			// A present `register` (empty while the maker types) keeps the
			// records branch open.
			if (Object.hasOwn(this.config, 'register') && !this.config.conversationSource) {
				return 'records'
			}
			if (this.config.postUrl && !this.config.conversationSource) {
				return 'postUrl'
			}
			return 'conversationSource'
		},
	},

	methods: {
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, so config keys this editor does not surface round-trip
		 * losslessly. Transport keys go through `setTransport` instead, which
		 * also enforces the one-of.
		 *
		 * @param {string} key - the config key being written; from the template only `schema`, the optional message-record schema slug.
		 * @param {string} value - the new value from the bound input; `''` (or `null`) deletes the key.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Switch between the two mutually exclusive transports by deleting
		 * the key of the branch being left, so the emitted config never
		 * carries both halves of the one-of. The value of the abandoned key
		 * is lost.
		 *
		 * @param {'conversationSource'|'postUrl'} shape - the radio's value: `postUrl` drops `conversationSource`, anything else drops `postUrl`.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setTransportShape(shape) {
			const next = { ...this.config }
			if (shape === 'records') {
				delete next.conversationSource
				next.register = next.register || ''
				this.$emit('update:config', withRecordsPostUrl(next))
				return
			}
			delete next.register
			delete next.messageField
			if (shape === 'postUrl') {
				delete next.conversationSource
			} else {
				delete next.postUrl
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write one key of the records transport and keep `postUrl` on the
		 * schema's records address, so a new message is a new record.
		 *
		 * @param {'register'|'schema'|'messageField'} key - the key being typed into.
		 * @param {string} value - the new value; `''` clears `schema` and `messageField`, and leaves `register` empty but present so the records branch stays open.
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
		 */
		setRecords(key, value) {
			const next = { ...this.config }
			delete next.conversationSource
			if (value === '' && key !== 'register') {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', withRecordsPostUrl(next))
		},

		/**
		 * Write the active transport endpoint and clear its partner in the
		 * same emit, so typing in one branch can never leave a stale value in
		 * the other half of the one-of.
		 *
		 * @param {'conversationSource'|'postUrl'} key - which transport is being typed into; the other of the two is deleted.
		 * @param {string} value - the endpoint / stream URL from the bound input; `''` deletes the key too, leaving neither branch set.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
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

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

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
	@include c.fieldset;
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
	@include c.control;
}

.chat-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
