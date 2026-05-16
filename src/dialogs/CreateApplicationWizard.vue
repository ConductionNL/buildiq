<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CreateApplicationWizard — four-step NcModal-based app creation wizard.

  Step 1: Basics   — name, slug, description, optional icons
  Step 2: Preset   — single / dev-prod / dev-staging-prod / custom
  Step 3: Custom   — only shown when preset === 'custom'; admin-defined chain
  Step 4: Review   — read-only summary + Create button

  On Create: POSTs to /apps/openbuilt/api/applications/wizard.
  On success: emits `created(applicationUuid)` so the parent can navigate.

  spec: openbuilt-app-creation-wizard REQ-OBWIZ-001 through REQ-OBWIZ-010
  ADR-004: NcModal must live in its own file. No inline NcModal in parent.
-->
<template>
	<NcModal
		:show="show"
		:name="t('openbuilt', 'Create application')"
		:can-close="!submitting"
		size="normal"
		@update:show="onModalShowUpdate"
		@close="onClose">

		<!-- Step indicator -->
		<div class="wizard__step-indicator">
			<span
				v-for="n in visibleStepCount"
				:key="n"
				class="wizard__step-dot"
				:class="{ 'wizard__step-dot--active': n === displayStep }">
				{{ n }}
			</span>
		</div>

		<!-- Step content -->
		<div class="wizard__body">
			<Step1Basics
				v-if="step === 1"
				:payload="payload"
				@update:payload="mergePayload" />

			<Step2Preset
				v-if="step === 2"
				:payload="payload"
				@update:payload="mergePayload" />

			<Step3Custom
				v-if="step === 3"
				:payload="payload"
				@update:payload="mergePayload" />

			<Step4Review
				v-if="step === 4"
				:payload="payload" />
		</div>

		<!-- Error banner -->
		<div v-if="errorMessage" class="wizard__error-banner" role="alert">
			<p>{{ errorMessage }}</p>
			<details v-if="orphanedResources.length > 0">
				<summary>{{ t('openbuilt', 'Orphaned resources that need manual cleanup:') }}</summary>
				<ul>
					<li v-for="r in orphanedResources" :key="r">
						<code>{{ r }}</code>
					</li>
				</ul>
			</details>
		</div>

		<!-- Footer navigation (NcModal has no #actions slot — render inline). -->
		<div class="wizard__footer">
			<NcButton
				v-if="step > 1"
				type="tertiary"
				:disabled="submitting"
				@click="goBack">
				{{ t('openbuilt', 'Back') }}
			</NcButton>
			<span class="wizard__footer-spacer" />
			<NcButton
				v-if="step < 4"
				type="primary"
				:disabled="!currentStepValid"
				@click="goNext">
				{{ t('openbuilt', 'Next') }}
			</NcButton>

			<NcButton
				v-if="step === 4"
				type="primary"
				:disabled="!allStepsValid || submitting"
				@click="onSubmit">
				<template #icon>
					<span v-if="submitting" class="wizard__spinner" aria-hidden="true" />
				</template>
				{{ submitting ? t('openbuilt', 'Creating…') : t('openbuilt', 'Create') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'

import Step1Basics from './CreateApplicationWizard/Step1Basics.vue'
import Step2Preset from './CreateApplicationWizard/Step2Preset.vue'
import Step3Custom from './CreateApplicationWizard/Step3Custom.vue'
import Step4Review from './CreateApplicationWizard/Step4Review.vue'

export default {
	name: 'CreateApplicationWizard',

	components: {
		NcModal,
		NcButton,
		Step1Basics,
		Step2Preset,
		Step3Custom,
		Step4Review,
	},

	props: {
		/**
		 * Control the visibility of the wizard modal.
		 */
		show: {
			type: Boolean,
			required: true,
		},
	},

	emits: ['update:show', 'created'],

	data() {
		return {
			step: 1,

			/**
			 * Merged wizard payload — accumulates all step inputs.
			 */
			payload: {
				name: '',
				slug: '',
				description: '',
				icon: null,
				iconDark: null,
				preset: '',
				versions: [],
				// Step-validity flags merged by child steps.
				_step1Valid: false,
				_step2Valid: false,
				_step3Valid: true, // true when preset !== custom
			},

			submitting: false,
			errorMessage: null,
			orphanedResources: [],
		}
	},

	computed: {
		isCustomPreset() {
			return this.payload.preset === 'custom'
		},

		/**
		 * The visual step number (1–4). When not on custom, step 3 is skipped
		 * so the display stays sequential: 1, 2, [skip 3], 4 → shows as 1/3, 2/3, 3/3.
		 */
		displayStep() {
			if (!this.isCustomPreset && this.step === 4) return 3
			return this.step
		},

		visibleStepCount() {
			return this.isCustomPreset ? 4 : 3
		},

		currentStepValid() {
			if (this.step === 1) return Boolean(this.payload._step1Valid)
			if (this.step === 2) return Boolean(this.payload._step2Valid)
			if (this.step === 3) return Boolean(this.payload._step3Valid)
			return true
		},

		allStepsValid() {
			const step3ok = !this.isCustomPreset || Boolean(this.payload._step3Valid)
			return (
				Boolean(this.payload._step1Valid)
				&& Boolean(this.payload._step2Valid)
				&& step3ok
			)
		},
	},

	methods: {
		onModalShowUpdate(value) {
			// Proxy NcModal's update:show event to the parent without mutating the prop.
			if (!value && !this.submitting) {
				this.$emit('update:show', false)
				this.resetState()
			}
		},

		mergePayload(partial) {
			this.payload = { ...this.payload, ...partial }
		},

		goNext() {
			if (this.step === 2 && !this.isCustomPreset) {
				// Skip step 3 for canned presets.
				this.step = 4
			} else if (this.step < 4) {
				this.step++
			}
		},

		goBack() {
			if (this.step === 4 && !this.isCustomPreset) {
				// Jump back to step 2 (step 3 was skipped).
				this.step = 2
			} else if (this.step > 1) {
				this.step--
			}
		},

		async onSubmit() {
			this.submitting = true
			this.errorMessage = null
			this.orphanedResources = []

			const body = {
				name: this.payload.name,
				slug: this.payload.slug,
				description: this.payload.description,
				preset: this.payload.preset,
				versions: this.payload.versions,
			}

			try {
				const url = generateUrl('/apps/openbuilt/api/applications/wizard')
				const { data, status } = await axios.post(url, body)

				if (status === 201 && data.applicationUuid) {
					this.$emit('created', data.applicationUuid)
					this.$emit('update:show', false)
					this.resetState()
				} else {
					this.errorMessage = data.message || t('openbuilt', 'An unexpected error occurred.')
					if (data.orphanedResources) {
						this.orphanedResources = data.orphanedResources
					}
				}
			} catch (err) {
				const data = err.response?.data || {}
				this.errorMessage = data.message || err.message || t('openbuilt', 'Failed to create the application.')
				if (data.orphanedResources) {
					this.orphanedResources = data.orphanedResources
				}
			} finally {
				this.submitting = false
			}
		},

		onClose() {
			if (!this.submitting) {
				this.$emit('update:show', false)
				this.resetState()
			}
		},

		resetState() {
			this.step = 1
			this.payload = {
				name: '',
				slug: '',
				description: '',
				icon: null,
				iconDark: null,
				preset: '',
				versions: [],
				_step1Valid: false,
				_step2Valid: false,
				_step3Valid: true,
			}
			this.submitting = false
			this.errorMessage = null
			this.orphanedResources = []
		},
	},
}
</script>

<style scoped>
.wizard__step-indicator {
	display: flex;
	justify-content: center;
	gap: 8px;
	padding: 12px 0 8px;
}

.wizard__step-dot {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid var(--color-border, #ddd);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #aaa);
}

.wizard__step-dot--active {
	border-color: var(--color-primary, #4376fc);
	background: var(--color-primary, #4376fc);
	color: #fff;
}

.wizard__body {
	padding: 8px 0;
	min-height: 240px;
}

.wizard__footer {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px 0 4px;
	border-top: 1px solid var(--color-border, #ddd);
	margin-top: 16px;
}

.wizard__footer-spacer {
	flex: 1 1 auto;
}

.wizard__error-banner {
	margin: 12px 0 0;
	padding: 10px 14px;
	background: var(--color-error-soft, #fdecea);
	border: 1px solid var(--color-error, #e9322d);
	border-radius: var(--border-radius, 4px);
	color: var(--color-error, #e9322d);
	font-size: 0.875rem;
}

.wizard__error-banner p {
	margin: 0 0 6px;
}

.wizard__error-banner code {
	word-break: break-all;
}

.wizard__spinner {
	display: inline-block;
	width: 16px;
	height: 16px;
	border: 2px solid rgba(255, 255, 255, 0.4);
	border-top-color: #fff;
	border-radius: 50%;
	animation: wizard-spin 0.7s linear infinite;
}

@keyframes wizard-spin {
	to { transform: rotate(360deg); }
}
</style>
