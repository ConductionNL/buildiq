<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - RecordTestDialog — add or change one record test of an app
  - (lifecycle-release-test-gate REQ-BQRT-003): a name, the schema the record
  - is checked against, the sample record, whether it should be accepted or
  - refused, and for a refusal the fields it should be refused on.
  -
  - The parent saves; this dialog only emits `save` with the test, and never
  - writes anything itself.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="test && test.id ? t('buildiq', 'Edit record test') : t('buildiq', 'Add record test')"
		:noClose="busy"
		@closing="$emit('update:open', false)">
		<div class="record-test-dialog">
			<NcTextField v-model="draft.name" :label="t('buildiq', 'Name')" />
			<NcSelect
				v-model="draft.schema"
				:options="schemas"
				:taggable="true"
				:clearable="false"
				:inputLabel="t('buildiq', 'Schema')" />
			<label class="record-test-dialog__label" for="record-test-dialog-record">
				{{ t('buildiq', 'Sample record (JSON)') }}
			</label>
			<textarea
				id="record-test-dialog-record"
				v-model="draft.recordText"
				class="record-test-dialog__record"
				rows="6" />
			<NcSelect
				v-model="draft.expect"
				:options="expectOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				:inputLabel="t('buildiq', 'Expected outcome')" />
			<NcTextField
				v-if="draft.expect === 'refuse'"
				v-model="draft.fieldsText"
				:label="t('buildiq', 'Refused on (fields, comma separated)')" />
			<p v-if="error" class="record-test-dialog__error" role="alert">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy" @click="submit">
				{{ t('buildiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/**
 * The editable form of a record test.
 *
 * @param {object|null} test The test, or null for a new one.
 * @return {{name: string, schema: string, recordText: string, expect: string, fieldsText: string}}
 */
export function draftOf(test) {
	const t0 = test || {}
	return {
		name: t0.name || '',
		schema: t0.schema || '',
		recordText: JSON.stringify(t0.record || {}, null, 2),
		expect: t0.expect || 'accept',
		fieldsText: (t0.expectedFields || []).join(', '),
	}
}

/**
 * The record test a draft describes, or an error message.
 *
 * @param {object} draft The draft.
 * @return {{test?: object, error?: string}}
 */
export function testOf(draft) {
	if (!draft.name.trim() || !String(draft.schema || '').trim()) {
		return { error: t('buildiq', 'A record test needs a name and a schema.') }
	}
	let record
	try {
		record = JSON.parse(draft.recordText || '{}')
	} catch {
		return { error: t('buildiq', 'The sample record is not valid JSON.') }
	}
	if (record === null || typeof record !== 'object' || Array.isArray(record)) {
		return { error: t('buildiq', 'The sample record must be an object.') }
	}
	const expectedFields = draft.expect === 'refuse'
		? draft.fieldsText.split(',').map((f) => f.trim()).filter(Boolean)
		: []
	return {
		test: {
			name: draft.name.trim(),
			schema: String(draft.schema).trim(),
			record,
			expect: draft.expect,
			expectedFields,
		},
	}
}

export default {
	name: 'RecordTestDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** The test to change, or null to add one. */
		test: { type: Object, default: null },
		/** The schema slugs the app's pages use. */
		schemas: { type: Array, default: () => [] },
		/** Whether the save is in flight. */
		busy: { type: Boolean, default: false },
	},

	emits: ['update:open', 'save'],

	data() {
		return { draft: draftOf(this.test), error: '' }
	},

	computed: {
		/**
		 * The two outcomes a record test can expect.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		expectOptions() {
			return [
				{ id: 'accept', label: t('buildiq', 'Accepted') },
				{ id: 'refuse', label: t('buildiq', 'Refused') },
			]
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.draft = draftOf(this.test)
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * Emit the test, or show why it cannot be saved.
		 *
		 * @return {void}
		 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
		 */
		submit() {
			const { test, error } = testOf(this.draft)
			this.error = error || ''
			if (test) {
				this.$emit('save', test)
			}
		},
	},
}
</script>

<style scoped>
.record-test-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.record-test-dialog__label {
	font-weight: bold;
}

.record-test-dialog__record {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
}

.record-test-dialog__error {
	color: var(--color-error-text);
}
</style>
