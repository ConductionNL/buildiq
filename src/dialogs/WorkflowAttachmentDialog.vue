<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WorkflowAttachmentDialog — standalone dialog (modal-isolation rule) to
  - attach a Procest case type to a virtual-app schema (REQ-PWA-002).
  -
  - Case-type picker fed by Procest's ZTC list endpoint; schema picker (the
  - app's own schemas, excluding already-attached ones); link-property picker
  - (string-typed properties of the chosen schema) with a one-click "create
  - zaakUrl property" delegation; optional description template; optional
  - "add a case-status tab to the detail page" toggle. Emits the assembled
  - `runtime.workflows[]` entry on save.
  -->
<template>
	<NcDialog
		:open="open"
		:name="editing ? t('openbuild', 'Edit workflow attachment') : t('openbuild', 'Attach a Procest case type')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-workflow-attach">
			<p v-if="!procestAvailable" class="ob-workflow-attach__warn">
				{{ t('openbuild', 'Procest is not installed or enabled on this instance. The case-type list cannot be loaded.') }}
			</p>

			<NcSelect
				v-model="caseTypeOption"
				:input-label="t('openbuild', 'Case type')"
				:options="caseTypeOptions"
				:loading="loadingCaseTypes"
				:disabled="!procestAvailable"
				label="label" />

			<NcSelect
				v-model="schemaOption"
				:input-label="t('openbuild', 'Schema')"
				:options="schemaOptions"
				label="label" />

			<div class="ob-workflow-attach__link">
				<NcSelect
					v-model="linkPropertyOption"
					:input-label="t('openbuild', 'Link property')"
					:options="linkPropertyOptions"
					label="label" />
				<NcButton type="tertiary" @click="$emit('create-link-property', selectedSchemaSlug)">
					{{ t('openbuild', 'Create zaakUrl property') }}
				</NcButton>
			</div>

			<NcTextField
				:value="descriptionTemplate"
				:label="t('openbuild', 'Description template (optional)')"
				:placeholder="t('openbuild', 'e.g. Application for {{title}}')"
				@update:value="descriptionTemplate = $event" />

			<label class="ob-workflow-attach__toggle">
				<input v-model="addStatusTab" type="checkbox">
				{{ t('openbuild', 'Add a case-status tab to this schema\'s detail page') }}
			</label>

			<p v-if="error" class="ob-workflow-attach__error" role="alert">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSave" @click="onSave">
				{{ t('openbuild', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'WorkflowAttachmentDialog',
	components: { NcDialog, NcButton, NcSelect, NcTextField },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		// The app's schemas as `[{ slug, title, properties }]`.
		schemas: {
			type: Array,
			default: () => ([]),
		},
		// Schema slugs already attached (excluded from the schema picker).
		attachedSchemas: {
			type: Array,
			default: () => ([]),
		},
		// Existing attachment when editing (null when adding).
		attachment: {
			type: Object,
			default: null,
		},
		// Soft capability flag for Procest.
		procestAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:open', 'save', 'create-link-property'],
	data() {
		return {
			caseTypes: [],
			loadingCaseTypes: false,
			caseTypeOption: null,
			schemaOption: null,
			linkPropertyOption: null,
			descriptionTemplate: '',
			addStatusTab: false,
			error: '',
		}
	},
	computed: {
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		editing() {
			return !!this.attachment
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		caseTypeOptions() {
			return this.caseTypes.map((ct) => ({
				label: ct.identificatie ? `${ct.omschrijving} (${ct.identificatie})` : ct.omschrijving,
				uuid: ct.uuid || ct.url || ct.identificatie,
				name: ct.omschrijving || ct.identificatie || '',
			}))
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		schemaOptions() {
			const exclude = this.editing ? [] : this.attachedSchemas
			return this.schemas
				.filter((s) => !exclude.includes(s.slug))
				.map((s) => ({ label: s.title || s.slug, slug: s.slug }))
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		selectedSchemaSlug() {
			return this.schemaOption ? this.schemaOption.slug : ''
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		linkPropertyOptions() {
			const schema = this.schemas.find((s) => s.slug === this.selectedSchemaSlug)
			const props = (schema && schema.properties) || {}
			return Object.keys(props)
				.filter((name) => {
					const p = props[name]
					const type = p && (p.type || (p.format ? 'string' : undefined))
					return type === 'string' || (p && (p.format === 'uri' || p.format === 'url'))
				})
				.map((name) => ({ label: name, name }))
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		canSave() {
			return !!(this.caseTypeOption && this.schemaOption && this.linkPropertyOption)
		},
	},
	watch: {
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				if (this.procestAvailable) {
					this.fetchCaseTypes()
				}
			}
		},
	},
	methods: {
		/**
		 * Seed the form from an existing attachment when editing.
		 *
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		hydrate() {
			this.error = ''
			if (this.attachment) {
				this.caseTypeOption = { label: this.attachment.caseTypeName, uuid: this.attachment.caseTypeUuid, name: this.attachment.caseTypeName }
				this.schemaOption = { label: this.attachment.schema, slug: this.attachment.schema }
				this.linkPropertyOption = { label: this.attachment.linkProperty, name: this.attachment.linkProperty }
				this.descriptionTemplate = this.attachment.descriptionTemplate || ''
			} else {
				this.caseTypeOption = null
				this.schemaOption = null
				this.linkPropertyOption = null
				this.descriptionTemplate = ''
				this.addStatusTab = false
			}
		},
		/**
		 * Load published case types from Procest's ZTC list endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		async fetchCaseTypes() {
			this.loadingCaseTypes = true
			try {
				const url = generateUrl('/apps/procest/api/zgw/catalogi/v1/zaaktypen')
				const { data } = await axios.get(url)
				const list = (data && (data.results || data)) || []
				this.caseTypes = Array.isArray(list) ? list : []
			} catch {
				this.caseTypes = []
			} finally {
				this.loadingCaseTypes = false
			}
		},
		/**
		 * Assemble + emit the workflow attachment entry.
		 *
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		onSave() {
			if (!this.canSave) {
				return
			}
			const id = (this.attachment && this.attachment.id)
				|| `wf-${this.schemaOption.slug}-${Date.now()}`
			const entry = {
				id,
				schema: this.schemaOption.slug,
				caseTypeUuid: this.caseTypeOption.uuid,
				caseTypeName: this.caseTypeOption.name,
				trigger: 'on-create',
				linkProperty: this.linkPropertyOption.name,
			}
			if (this.descriptionTemplate) {
				entry.descriptionTemplate = this.descriptionTemplate
			}
			this.$emit('save', { entry, addStatusTab: this.addStatusTab })
			this.$emit('update:open', false)
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-workflow-attach {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.ob-workflow-attach__link {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
.ob-workflow-attach__warn {
	color: var(--color-warning-text, var(--color-warning));
}
.ob-workflow-attach__error {
	color: var(--color-error);
}
.ob-workflow-attach__toggle {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
