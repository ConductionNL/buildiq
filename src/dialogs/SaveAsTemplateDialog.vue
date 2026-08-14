<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	SaveAsTemplateDialog — standalone dialog (gate-modal-isolation) opened
	from the "Save as template" action on the application-detail surface.

	Captures the current virtual app's manifest + companion schemas into an
	`ApplicationTemplate` record (isSeeded: false), de-namespaced as the exact
	inverse of clone-time REQ-OBTC-005 so save→clone round-trips to a clean
	rename. Validates the captured manifest before allowing Save (REQ-SAT-003);
	resolves slug collisions to update-in-place / seeded-slug / slug-taken
	(REQ-SAT-004); writes through OR's object REST API — zero new PHP
	(REQ-SAT-006).
-->
<template>
	<NcDialog
		:open="open"
		:name="t('openbuild', 'Save as template')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-save-template">
			<p class="ob-save-template__intro">
				{{
					t(
						'openbuild',
						'Publish this application as a reusable template your organisation can build new apps from.',
					)
				}}
			</p>

			<NcTextField
				:modelValue="form.title"
				:label="t('openbuild', 'Template title')"
				@update:modelValue="onTitleInput" />
			<NcTextField
				:modelValue="form.slug"
				:label="t('openbuild', 'Slug (kebab-case, max 32 chars)')"
				@update:modelValue="form.slug = $event" />
			<NcTextField
				:modelValue="form.useCase"
				:label="t('openbuild', 'Use case (one line)')"
				@update:modelValue="form.useCase = $event" />
			<NcTextArea
				:modelValue="form.description"
				:label="t('openbuild', 'Description')"
				@update:modelValue="form.description = $event" />
			<NcSelect
				v-model="categoryOption"
				:inputLabel="t('openbuild', 'Category')"
				:options="categoryOptions"
				:clearable="false" />
			<NcTextField
				:modelValue="form.sourceUrl"
				:label="t('openbuild', 'Source URL (optional)')"
				@update:modelValue="form.sourceUrl = $event" />

			<!-- Capture summary -->
			<section class="ob-save-template__summary">
				<h3>{{ t('openbuild', 'What will be captured') }}</h3>
				<p>
					{{
						t(
							'openbuild',
							'The application manifest and {count} companion schema(s).',
							{ count: captureSummary.length },
						)
					}}
				</p>
				<ul v-if="captureSummary.length" class="ob-save-template__schemas">
					<li v-for="entry in captureSummary" :key="entry.sourceSlug">
						<code>{{ entry.slug }}</code>
						<span
							v-if="entry.shared"
							class="ob-save-template__shared-flag">
							{{
								t(
									'openbuild',
									'(shared schema — captured unchanged, clones receive an independent copy)',
								)
							}}
						</span>
					</li>
				</ul>
				<p class="ob-save-template__no-rows">
					{{
						t(
							'openbuild',
							'No object data (rows) is captured — a template is a definition, not a dataset.',
						)
					}}
				</p>
			</section>

			<!-- Errors -->
			<p v-if="collisionError" class="ob-save-template__error" role="alert">
				{{
					t(
						'openbuild',
						'Two schemas would collide under the same name: {schemas}. Rename one before saving.',
						{ schemas: collisionError },
					)
				}}
			</p>
			<div
				v-if="validationErrors.length"
				class="ob-save-template__error"
				role="alert">
				<p>
					{{
						t(
							'openbuild',
							'The captured manifest is invalid and cannot be published:',
						)
					}}
				</p>
				<ul>
					<li v-for="(err, idx) in validationErrors" :key="idx">
						{{ err }}
					</li>
				</ul>
			</div>
			<p
				v-if="slugError === 'seeded-slug'"
				class="ob-save-template__error"
				role="alert">
				{{
					t(
						'openbuild',
						'That slug belongs to a Conduction-curated template and cannot be overwritten. Pick another slug.',
					)
				}}
			</p>
			<p
				v-if="slugError === 'slug-taken'"
				class="ob-save-template__error"
				role="alert">
				{{
					t(
						'openbuild',
						'That slug is already used by a template you cannot edit. Pick another slug.',
					)
				}}
			</p>
			<p v-if="saveError" class="ob-save-template__error" role="alert">
				{{ saveError }}
			</p>

			<!-- Update-in-place confirm -->
			<p v-if="updateMode" class="ob-save-template__update-notice">
				{{
					t(
						'openbuild',
						'A template with this slug already exists. Saving will update it and bump its version. Previously cloned applications are not affected.',
					)
				}}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSave || saving" @click="save">
				{{ saveLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { validateManifest } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	bumpMinor,
	captureTemplate,
	resolveSaveTarget,
	SlugCollisionError,
	suggestSlug,
	TEMPLATE_CATEGORIES,
} from '../services/templateCapture.js'

const OR_TEMPLATES = '/apps/openregister/api/objects/openbuild/application-template'

const CATEGORY_LABELS = {
	'government-services': 'Government services',
	'internal-operations': 'Internal operations',
	'citizen-engagement': 'Citizen engagement',
	'field-work': 'Field work',
}

export default {
	name: 'SaveAsTemplateDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField, NcTextArea },
	props: {
		open: { type: Boolean, default: false },
		// The source Application record (carries slug, version, manifest).
		application: { type: Object, default: null },
		// The app's manifest blob (active version manifest).
		manifest: { type: Object, default: null },
		// The app's companion schema definitions (JSON-schema blobs with slug).
		schemas: { type: Array, default: () => [] },
		// Existing org-local + seeded templates the caller can see (for slug
		// collision resolution). The dialog never invents role logic — it reads
		// OR's per-object writability hint off each record.
		existingTemplates: { type: Array, default: () => [] },
	},

	emits: ['update:open', 'saved'],
	data() {
		return {
			form: {
				title: '',
				slug: '',
				useCase: '',
				description: '',
				sourceUrl: '',
			},

			categoryOption: null,
			slugEditedManually: false,
			saving: false,
			saveError: '',
			collisionError: '',
		}
	},

	computed: {
		/**
		 * Category picker options over the REQ-OBTC-001 enum.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		categoryOptions() {
			return TEMPLATE_CATEGORIES.map((value) => ({
				id: value,
				label: t('openbuild', CATEGORY_LABELS[value] || value),
			}))
		},

		/**
		 * The selected category id (or '' when none picked).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		selectedCategory() {
			return this.categoryOption?.id ?? this.categoryOption ?? ''
		},

		/**
		 * The capture result — record + summary — or null when capture
		 * throws a de-namespace collision (surfaced via `collisionError`).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		capture() {
			try {
				const result = captureTemplate(
					this.application || {},
					this.schemas,
					this.manifest,
					{
						title: this.form.title,
						slug: this.form.slug,
						description: this.form.description,
						useCase: this.form.useCase,
						category: this.selectedCategory,
						sourceUrl: this.form.sourceUrl,
					},
				)
				return result
			} catch (e) {
				return null
			}
		},

		/**
		 * Capture summary (companion schema list) for the dialog body.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		captureSummary() {
			return this.capture ? this.capture.summary.companionSchemas : []
		},

		/**
		 * Validation errors of the CAPTURED (de-namespaced) manifest — the
		 * exact blob a clone will consume (REQ-SAT-003).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		validationErrors() {
			if (!this.capture) {
				return []
			}
			try {
				const result = validateManifest
					? validateManifest(this.capture.record.manifest)
					: { valid: true, errors: [] }
				return Array.isArray(result.errors) ? result.errors : []
			} catch (e) {
				return [`validator threw: ${e && e.message ? e.message : e}`]
			}
		},

		/**
		 * Resolve what saving this slug does (create / update / error)
		 * against the visible templates (REQ-SAT-004).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		saveTarget() {
			return resolveSaveTarget(this.form.slug, this.existingTemplates, (tpl) =>
				this.isWritable(tpl),
			)
		},

		/**
		 * The slug-collision error code (`seeded-slug` / `slug-taken`) or ''.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		slugError() {
			return this.saveTarget.error || ''
		},

		/**
		 * True when saving will update an existing own template in place.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		updateMode() {
			return this.saveTarget.mode === 'update'
		},

		/**
		 * Slug well-formedness (mirrors the clone dialog's pattern).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		slugValid() {
			return (
				/^[a-z0-9]+(-[a-z0-9]+)*$/.test(this.form.slug)
				&& this.form.slug.length <= 32
			)
		},

		/**
		 * Whether Save is allowed (REQ-SAT-002/003/004 gates).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		canSave() {
			return (
				this.form.title.trim().length > 0
				&& this.slugValid
				&& !!this.selectedCategory
				&& !!this.capture
				&& !this.collisionError
				&& this.validationErrors.length === 0
				&& !this.slugError
			)
		},

		/**
		 * Save button label — varies for create vs update-in-place.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		saveLabel() {
			if (this.saving) {
				return t('openbuild', 'Saving…')
			}
			return this.updateMode
				? t('openbuild', 'Update template')
				: t('openbuild', 'Save as template')
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog opens, prefilled from the app.
		 *
		 * @param {boolean} value - The dialog's new `open` state. Only the transition
		 *   into "open" is acted on, so a close leaves the form untouched and the next
		 *   open starts from the source Application again rather than from whatever
		 *   the user last typed.
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		open(value) {
			if (value) {
				this.resetForm()
			}
		},

		/**
		 * Keep the de-namespace collision message in sync with the capture
		 * attempt (capture throws when two schemas collide).
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		schemas: {
			/**
			 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
			 */
			handler() {
				this.recomputeCollision()
			},

			immediate: true,
		},

		/**
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		'form.slug': function () {
			this.slugEditedManually = true
		},
	},

	/**
	 * Prefill on mount when already open (the parent renders the dialog
	 * with `v-if="open"`, so `created` fires with `open: true`).
	 *
	 * @return {void}
	 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
	 */
	created() {
		if (this.open) {
			this.resetForm()
		}
	},

	methods: {
		/**
		 * Prefill the form from the source Application.
		 *
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		resetForm() {
			const app = this.application || {}
			this.form = {
				title: app.name || app.slug || '',
				slug: suggestSlug(app.name || app.slug || ''),
				useCase: app.description
					? String(app.description).split('\n')[0].slice(0, 120)
					: '',

				description: app.description || '',
				sourceUrl: '',
			}
			this.categoryOption = this.categoryOptions[0] || null
			this.slugEditedManually = false
			this.saving = false
			this.saveError = ''
			this.recomputeCollision()
		},

		/**
		 * Update the title and auto-suggest the slug until the user edits
		 * the slug field by hand.
		 *
		 * @param {string} value The new title value.
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		onTitleInput(value) {
			this.form.title = value
			if (!this.slugEditedManually) {
				this.form.slug = suggestSlug(value)
				// suggestSlug write triggered the slug watcher; undo the flag.
				this.slugEditedManually = false
			}
		},

		/**
		 * Recompute the de-namespace collision message by attempting a
		 * capture; SlugCollisionError names both colliding schemas.
		 *
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		recomputeCollision() {
			try {
				captureTemplate(
					this.application || {},
					this.schemas,
					this.manifest,
					{
						title: this.form.title,
						slug: this.form.slug,
						category: this.selectedCategory,
					},
				)
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
		 * Read OR's per-object writability for a template record. OR returns
		 * an `@self.permissions` / `permissions` hint; we treat an explicit
		 * `false` as not-writable and anything else (including absent) as
		 * writable, since the OR server is the real gate (REQ-SAT-006).
		 *
		 * @param {object} tpl A template record.
		 * @return {boolean} Whether the caller may write it.
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		isWritable(tpl) {
			const self = (tpl && tpl['@self']) || {}
			const canWrite = self.canWrite ?? tpl?.canWrite
			if (canWrite === false) {
				return false
			}
			return true
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		onClose() {
			if (this.saving) {
				return
			}
			this.$emit('update:open', false)
		},

		/**
		 * Persist the captured template via OR REST — create or
		 * update-in-place per `saveTarget` (REQ-SAT-004, REQ-SAT-006).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		async save() {
			if (!this.canSave || this.saving) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const target = this.saveTarget
				const record = { ...this.capture.record }
				if (target.mode === 'update') {
					const existing = target.record
					const uuid =
						(existing['@self'] && existing['@self'].id)
						|| existing.uuid
						|| existing.id
					record.version = bumpMinor(existing.version)
					const url = generateUrl(
						`${OR_TEMPLATES}/${encodeURIComponent(uuid)}`,
					)
					await axios.put(url, { ...existing, ...record })
				} else {
					await axios.post(generateUrl(OR_TEMPLATES), record)
				}
				this.$emit('saved', { slug: record.slug, mode: target.mode })
				this.$emit('update:open', false)
			} catch (e) {
				const data = e?.response?.data
				this.saveError =
					data?.detail
					|| data?.error
					|| e?.message
					|| t('openbuild', 'Saving the template failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.ob-save-template {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 360px;
}

.ob-save-template__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ob-save-template__summary {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	background: var(--color-background-hover);
}

.ob-save-template__summary h3 {
	margin: 0 0 8px 0;
	font-size: 0.95rem;
}

.ob-save-template__schemas {
	margin: 4px 0;
	padding-left: 18px;
}

.ob-save-template__shared-flag {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-left: 6px;
}

.ob-save-template__no-rows {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.ob-save-template__error {
	color: var(--color-error);
	margin: 0;
}

.ob-save-template__update-notice {
	color: var(--color-warning-text, var(--color-main-text));
	margin: 0;
}
</style>
