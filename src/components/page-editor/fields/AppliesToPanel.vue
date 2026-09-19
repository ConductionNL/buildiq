<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AppliesToPanel: say which cases this detail page is for, and who sees it.
  -
  - THIS PANEL DOES NOT AUTHOR TABS, ON PURPOSE
  - A sidebar tab in a buildiq manifest is `{id, label, icon, component}`. A
  - pageLayout tab is `{kind, ref, fields, widgets}`. They are two vocabularies,
  - and translating one into the other here would be a second page model,
  - invented in an editor, that nothing else agrees with. The manifest's model
  - belongs to nextcloud-vue and is consumed as it is. The pageLayout tab picker
  - is task 6.1 of case-page-layout-per-case-type, and until it lands this panel
  - saves the binding and leaves `tabs` exactly as it found them.
  -
  - So this panel adds only the binding: the type value the screen is for, the
  - audience it is for, and a name to tell three screens for one case type
  - apart.
  -
  - The base fingerprint is not on this panel and is never sent. The server
  - stamps it from the layouts actually published, so an override saved here
  - cannot pin itself to a base nobody checked.
  -
  - @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
  - @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004)
  -->
<template>
	<fieldset class="applies-to">
		<legend>{{ t('buildiq', 'Applies to') }}</legend>

		<p v-if="!canPublish" class="applies-to__note">
			{{
				t(
					'buildiq',
					'Pick a register and a schema first. A layout is published for one schema.',
				)
			}}
		</p>

		<template v-else>
			<label class="applies-to__field">
				{{ t('buildiq', 'Name') }}
				<input
					type="text"
					:value="binding.name || ''"
					:placeholder="t('buildiq', 'Handler screen')"
					@input="write('name', $event.target.value)" />
			</label>
			<p class="applies-to__hint">
				{{
					t(
						'buildiq',
						'Three screens for one case type is the point. The name is how you tell them apart.',
					)
				}}
			</p>

			<div class="applies-to__row">
				<label class="applies-to__field">
					{{ t('buildiq', 'Type property') }}
					<input
						type="text"
						:value="binding.typeProperty || ''"
						:placeholder="t('buildiq', 'caseType')"
						@input="write('typeProperty', $event.target.value)" />
				</label>
				<label class="applies-to__field">
					{{ t('buildiq', 'Type value') }}
					<input
						type="text"
						:value="binding.typeValue || ''"
						:placeholder="t('buildiq', 'bouwvergunning')"
						@input="write('typeValue', $event.target.value)" />
				</label>
			</div>
			<p class="applies-to__hint">
				{{
					t(
						'buildiq',
						'Leave both empty and this layout covers every case in the schema.',
					)
				}}
			</p>

			<label class="applies-to__field">
				{{ t('buildiq', 'Audience') }}
				<select
					:value="audienceKind"
					@change="writeAudienceKind($event.target.value)">
					<option v-for="kind in audienceKinds" :key="kind" :value="kind">
						{{ audienceLabel(kind) }}
					</option>
				</select>
			</label>

			<label v-if="audienceNeedsRef" class="applies-to__field">
				{{ audienceRefLabel }}
				<input
					type="text"
					:value="audienceRef"
					:aria-invalid="audienceRefMissing"
					@input="writeAudienceRef($event.target.value)" />
			</label>
			<p v-if="audienceRefMissing" class="applies-to__warn" role="alert">
				{{
					t(
						'buildiq',
						'Say which one. Without it this screen matches nobody and nobody sees it.',
					)
				}}
			</p>

			<label class="applies-to__field">
				{{ t('buildiq', 'State') }}
				<select
					:value="binding.status || 'draft'"
					@change="write('status', $event.target.value)">
					<option value="draft">
						{{ t('buildiq', 'Draft') }}
					</option>
					<option value="published">
						{{ t('buildiq', 'Published') }}
					</option>
				</select>
			</label>

			<p class="applies-to__hint">
				{{
					t(
						'buildiq',
						'The tabs stay as the app manifest declares them. A tab picker for this screen comes later.',
					)
				}}
			</p>

			<button
				type="button"
				class="applies-to__save"
				:disabled="saving || audienceRefMissing"
				@click="publish">
				{{
					saving
						? t('buildiq', 'Saving')
						: t('buildiq', 'Save this screen')
				}}
			</button>

			<p v-if="refusal" class="applies-to__warn" role="alert">
				{{ refusal }}
			</p>
			<p v-for="warning in warnings" :key="warning" class="applies-to__hint">
				{{ warning }}
			</p>
			<p v-if="saved" class="applies-to__note" role="status">
				{{ t('buildiq', 'Saved.') }}
			</p>
		</template>
	</fieldset>
</template>

<script>
import { savePageLayout } from '../../../services/pageLayouts.js'

export default {
	name: 'AppliesToPanel',

	props: {
		// The `pageLayout` block on the page config: everything that decides
		// which cases this page is for and who sees it.
		modelValue: {
			type: Object,
			default: () => ({}),
		},

		// The register and schema the page is already bound to, read from the
		// page config rather than asked for twice.
		register: {
			type: String,
			default: '',
		},

		schema: {
			type: String,
			default: '',
		},

		// The app these cases belong to, stored on the layout so the leaf can
		// tell two apps' screens apart.
		targetApp: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue', 'saved'],

	data() {
		return {
			saving: false,
			saved: false,
			refusal: '',
			warnings: [],
		}
	},

	computed: {
		/**
		 * The binding, defaulted so an unset block still renders.
		 *
		 * @return {object} The pageLayout block.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		binding() {
			return this.modelValue || {}
		},

		/**
		 * The audience kinds the resolver knows. Anything else is refused on
		 * save, so offering it here would be offering a screen nobody sees.
		 *
		 * @return {Array<string>} The kinds.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceKinds() {
			return ['everyone', 'group', 'team', 'portal', 'user']
		},

		/**
		 * The selected audience kind. Unset reads as everyone, which is what
		 * every layout stored before overrides existed means.
		 *
		 * @return {string} The kind.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceKind() {
			return (
				(this.binding.audience && this.binding.audience.kind) || 'everyone'
			)
		},

		/**
		 * Which group, team or user the audience names.
		 *
		 * @return {string} The ref.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceRef() {
			return (this.binding.audience && this.binding.audience.ref) || ''
		},

		/**
		 * Whether this kind has to name one.
		 *
		 * @return {boolean} True for group, team and user.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceNeedsRef() {
			return ['group', 'team', 'user'].includes(this.audienceKind)
		},

		/**
		 * Whether it has to and does not. The server refuses this, and saying so
		 * here saves a round trip.
		 *
		 * @return {boolean} True when the ref is missing.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceRefMissing() {
			return this.audienceNeedsRef && this.audienceRef === ''
		},

		/**
		 * The label on the ref field, named after what it holds.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceRefLabel() {
			if (this.audienceKind === 'group') {
				return t('buildiq', 'Which group')
			}
			if (this.audienceKind === 'team') {
				return t('buildiq', 'Which team')
			}
			return t('buildiq', 'Which user')
		},

		/**
		 * A layout is published for one schema, so both have to be picked.
		 *
		 * @return {boolean} True when the page names a register and a schema.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		canPublish() {
			return this.register !== '' && this.schema !== ''
		},
	},

	methods: {
		/**
		 * The label for one audience kind.
		 *
		 * @param {string} kind - the audience kind.
		 * @return {string} The label.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		audienceLabel(kind) {
			const labels = {
				everyone: t('buildiq', 'Everyone'),
				group: t('buildiq', 'One group'),
				team: t('buildiq', 'One team'),
				portal: t('buildiq', 'Portal visitors'),
				user: t('buildiq', 'One person'),
			}
			return labels[kind] || kind
		},

		/**
		 * Write one key on the binding and emit the whole block back, so keys
		 * this panel does not surface round-trip untouched.
		 *
		 * @param {string} key - the key to write.
		 * @param {string} value - the new value; an empty string deletes it.
		 * @return {void}
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		write(key, value) {
			const next = { ...this.binding }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Change the audience kind. Moving back to everyone drops the ref, so a
		 * stale group name cannot travel on a screen meant for everybody.
		 *
		 * @param {string} kind - the new kind.
		 * @return {void}
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		writeAudienceKind(kind) {
			const next = { ...this.binding }
			if (kind === 'everyone') {
				delete next.audience
			} else if (['group', 'team', 'user'].includes(kind)) {
				next.audience = { kind, ref: this.audienceRef }
			} else {
				next.audience = { kind, ref: '' }
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Name the group, team or person the audience is bound to.
		 *
		 * @param {string} ref - the name.
		 * @return {void}
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		writeAudienceRef(ref) {
			const next = { ...this.binding }
			next.audience = { kind: this.audienceKind, ref }
			this.$emit('update:modelValue', next)
		},

		/**
		 * An id built from the scope this screen is for.
		 *
		 * Deterministic, so saving the same screen twice edits it instead of
		 * publishing a second one that then collides with the first under the
		 * uniqueness rule.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		derivedId() {
			const parts = [
				'layout',
				this.register,
				this.schema,
				this.binding.typeValue || 'all',
				this.audienceKind,
				this.audienceRef || 'all',
			]
			return parts
				.join('-')
				.toLowerCase()
				.replace(/[^a-z0-9-]+/g, '-')
				.replace(/-+/g, '-')
		},

		/**
		 * Publish the binding as a pageLayout. The tabs are left as they are.
		 *
		 * A refusal keeps the sentence the rule wrote: every rule on this
		 * endpoint refuses for a reason an administrator can act on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-002)
		 */
		async publish() {
			this.saving = true
			this.refusal = ''
			this.warnings = []
			this.saved = false

			const id = this.binding.id || this.derivedId()

			try {
				const result = await savePageLayout({
					...this.binding,
					id,
					targetApp: this.targetApp,
					register: this.register,
					schema: this.schema,
				})
				// Write the id back so the next save edits this screen rather
				// than publishing a second one that collides with it.
				if (!this.binding.id) {
					this.$emit('update:modelValue', { ...this.binding, id })
				}
				this.warnings = result.warnings
				this.saved = true
				this.$emit('saved', result.layout)
			} catch (refusal) {
				this.refusal = refusal.message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.applies-to__field {
	display: block;
	margin-block-end: 8px;
}

.applies-to__row {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.applies-to__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.applies-to__warn {
	color: var(--color-error);
}
</style>
