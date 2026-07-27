<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	SaveBlockDialog — standalone dialog (gate-modal-isolation) opened from
	the page designer's widget/section selection affordance.

	Captures a selected widget (or a selected multi-widget page section)
	into a `ComponentBlock` record, de-namespaced via the same
	`deNamespaceSlug`/`rewriteSchemaRefs` machinery `save-as-template` uses
	for companion schemas (blockCapture.js), and shows the dependency
	summary before writing through OR's standard object REST API — zero
	new PHP.
-->
<template>
	<NcDialog
		:open="open"
		:name="dialogTitle"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-save-block">
			<p class="ob-save-block__intro">
				{{ isSection
					? t('openbuild', 'Save the selected widgets as a reusable block your organisation can insert into any page.')
					: t('openbuild', 'Save this widget as a reusable block your organisation can insert into any page.') }}
			</p>

			<NcTextField
				:value="form.name"
				:label="t('openbuild', 'Block name')"
				@update:value="onNameInput" />
			<NcTextField
				:value="form.slug"
				:label="t('openbuild', 'Slug (kebab-case, max 48 chars)')"
				@update:value="form.slug = $event" />
			<NcTextArea
				:value="form.description"
				:label="t('openbuild', 'Description')"
				@update:value="form.description = $event" />
			<NcTextField
				:value="form.category"
				:label="t('openbuild', 'Category (e.g. {examples})', { examples: categoryHint })"
				@update:value="form.category = $event" />

			<!-- Capture summary -->
			<section class="ob-save-block__summary">
				<h3>{{ t('openbuild', 'What will be captured') }}</h3>
				<p v-if="dependencySummary.length">
					{{ t('openbuild', 'Bindings reference {count} schema(s).', { count: dependencySummary.length }) }}
				</p>
				<ul v-if="dependencySummary.length" class="ob-save-block__schemas">
					<li v-for="entry in dependencySummary" :key="entry.sourceSlug">
						<code>{{ entry.slug }}</code>
						<span v-if="entry.shared" class="ob-save-block__shared-flag">
							{{ t('openbuild', '(shared schema — captured unchanged)') }}
						</span>
					</li>
				</ul>
				<p class="ob-save-block__no-rows">
					{{ t('openbuild', 'No object data (rows) is captured — only structure.') }}
				</p>
			</section>

			<p v-if="collisionError" class="ob-save-block__error" role="alert">
				{{ t('openbuild', 'Two schemas would collide under the same name: {schemas}. Rename one before saving.', { schemas: collisionError }) }}
			</p>
			<p v-if="slugTakenError" class="ob-save-block__error" role="alert">
				{{ t('openbuild', 'That slug is already used by a block in your organisation. Pick another slug.') }}
			</p>
			<p v-if="saveError" class="ob-save-block__error" role="alert">
				{{ saveError }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSave || saving"
				@click="save">
				{{ saveLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcDialog, NcTextField, NcTextArea } from '@nextcloud/vue'

import {
	BLOCK_CATEGORIES,
	captureBlock,
	isSectionFragment,
	SlugCollisionError,
} from '../services/blockCapture.js'
import { suggestSlug } from '../services/templateCapture.js'

const OR_BLOCKS = '/apps/openregister/api/objects/openbuild/component-block'

export default {
	name: 'SaveBlockDialog',
	components: { NcButton, NcDialog, NcTextField, NcTextArea },
	props: {
		open: { type: Boolean, default: false },
		// The source Application record (carries slug — used to de-namespace).
		application: { type: Object, default: null },
		// The widgetEntry (single-widget capture) or `{ id, widgets: [...] }`
		// section wrapper (multi-widget capture) selected in the designer.
		fragment: { type: Object, default: null },
		// Blocks already visible to the caller, for slug-collision checking.
		existingBlocks: { type: Array, default: () => [] },
	},
	emits: ['update:open', 'saved'],
	data() {
		return {
			form: {
				name: '',
				slug: '',
				description: '',
				category: '',
			},
			slugEditedManually: false,
			saving: false,
			saveError: '',
			collisionError: '',
		}
	},
	computed: {
		/**
		 * Whether the current selection is a multi-widget section capture.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		isSection() {
			return isSectionFragment(this.fragment)
		},
		/**
		 * Dialog title — varies for a single-widget vs a section capture.
		 *
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		dialogTitle() {
			return this.isSection
				? t('openbuild', 'Save section as block')
				: t('openbuild', 'Save widget as block')
		},
		/**
		 * Suggested category examples shown in the field label.
		 *
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		categoryHint() {
			return BLOCK_CATEGORIES.join(', ')
		},
		/**
		 * The capture result, or null when capture throws a de-namespace
		 * collision (surfaced via `collisionError`).
		 *
		 * @return {?{record: object, summary: object}}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		capture() {
			if (!this.fragment) {
				return null
			}
			try {
				return captureBlock(this.fragment, this.appSlug, {
					slug: this.form.slug,
					name: this.form.name,
					description: this.form.description,
					category: this.form.category,
					createdBy: this.currentUserId,
				})
			} catch (e) {
				return null
			}
		},
		/**
		 * The source Application's slug (used for de-namespacing).
		 *
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		appSlug() {
			return (this.application && this.application.slug) || ''
		},
		/**
		 * The current Nextcloud user id, recorded as `createdBy`.
		 *
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		currentUserId() {
			const user = getCurrentUser()
			return (user && user.uid) || ''
		},
		/**
		 * Schema-dependency summary rendered in the capture-summary section.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		dependencySummary() {
			return this.capture ? this.capture.summary.schemaDependencies : []
		},
		/**
		 * Slug well-formedness (mirrors the schema's `^[a-z0-9][a-z0-9-]*[a-z0-9]$`).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		slugValid() {
			return /^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(this.form.slug) && this.form.slug.length <= 48
		},
		/**
		 * Whether the chosen slug already belongs to a visible block (v1:
		 * create-only, no update-in-place — see design.md's "no version
		 * chain" non-goal).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		slugTakenError() {
			return this.existingBlocks.some((b) => b && b.slug === this.form.slug)
		},
		/**
		 * Whether Save is allowed.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		canSave() {
			return this.form.name.trim().length > 0
				&& this.slugValid
				&& this.form.category.trim().length > 0
				&& !!this.capture
				&& !this.collisionError
				&& !this.slugTakenError
		},
		/**
		 * Save button label.
		 *
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		saveLabel() {
			return this.saving ? t('openbuild', 'Saving…') : t('openbuild', 'Save block')
		},
	},
	watch: {
		/**
		 * Reset the form each time the dialog opens.
		 *
		 * @param {boolean} value - the new `open` prop value.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		open(value) {
			if (value) {
				this.resetForm()
			}
		},
		fragment: {
			/**
			 * Re-check the de-namespace collision whenever the selected
			 * fragment changes.
			 *
			 * @return {void}
			 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
			 */
			handler() {
				this.recomputeCollision()
			},
			immediate: true,
		},
		'form.slug'() {
			this.slugEditedManually = true
		},
	},
	/**
	 * Prefill on mount when already open (the parent renders the dialog
	 * with `v-if="open"`, so `created` fires with `open: true`).
	 *
	 * @return {void}
	 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
	 */
	created() {
		if (this.open) {
			this.resetForm()
		}
	},
	methods: {
		/**
		 * Reset the form, prefilled from the selected fragment.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		resetForm() {
			const first = this.isSection
				? ((this.fragment && this.fragment.widgets && this.fragment.widgets[0]) || {})
				: (this.fragment || {})
			const seedName = first.widgetKey || first.id || ''
			this.form = {
				name: seedName,
				slug: suggestSlug(seedName),
				description: '',
				category: '',
			}
			this.slugEditedManually = false
			this.saving = false
			this.saveError = ''
			this.recomputeCollision()
		},
		/**
		 * Update the name and auto-suggest the slug until the user edits
		 * the slug field by hand.
		 *
		 * @param {string} value - the new name value.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onNameInput(value) {
			this.form.name = value
			if (!this.slugEditedManually) {
				this.form.slug = suggestSlug(value)
				this.slugEditedManually = false
			}
		},
		/**
		 * Recompute the de-namespace collision message by attempting a
		 * capture; `SlugCollisionError` names both colliding schemas.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		recomputeCollision() {
			if (!this.fragment) {
				this.collisionError = ''
				return
			}
			try {
				captureBlock(this.fragment, this.appSlug, {
					slug: this.form.slug,
					name: this.form.name,
					category: this.form.category,
				})
				this.collisionError = ''
			} catch (e) {
				if (e instanceof SlugCollisionError) {
					this.collisionError = e.sourceSlugs.join(', ')
				} else {
					this.collisionError = ''
				}
			}
		},
		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onClose() {
			if (this.saving) {
				return
			}
			this.$emit('update:open', false)
		},
		/**
		 * Persist the captured block via OR REST (create-only, v1).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async save() {
			if (!this.canSave || this.saving) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				await axios.post(generateUrl(OR_BLOCKS), this.capture.record)
				this.$emit('saved', { slug: this.capture.record.slug })
				this.$emit('update:open', false)
			} catch (e) {
				const data = e?.response?.data
				this.saveError = data?.detail || data?.error || e?.message || t('openbuild', 'Saving the block failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.ob-save-block {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 360px;
}

.ob-save-block__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ob-save-block__summary {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	background: var(--color-background-hover);
}

.ob-save-block__summary h3 {
	margin: 0 0 8px 0;
	font-size: 0.95rem;
}

.ob-save-block__schemas {
	margin: 4px 0;
	padding-left: 18px;
}

.ob-save-block__shared-flag {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-left: 6px;
}

.ob-save-block__no-rows {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.ob-save-block__error {
	color: var(--color-error);
	margin: 0;
}
</style>
