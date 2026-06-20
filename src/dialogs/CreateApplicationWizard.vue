<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CreateApplicationWizard — four-step NcModal-based app creation wizard.

  Step 1: Basics   — name, slug, description, optional icons
  Step 2: Preset   — single / dev-prod / dev-staging-prod / custom
  Step 3: Custom   — only shown when preset === 'custom'; admin-defined chain
  Step 4: Review   — read-only summary + Create button

  On Create: POSTs to /apps/openbuild/api/applications/wizard.
  On success: emits `created(applicationUuid)` so the parent can navigate.

  spec: openbuild-app-creation-wizard REQ-OBWIZ-001 through REQ-OBWIZ-010
  ADR-004: NcModal must live in its own file. No inline NcModal in parent.
-->
<template>
	<NcModal
		:show="show"
		:name="t('openbuild', 'Create app')"
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
			<!-- Step 1 opens with the app-type choice (unify-apps-with-app-type). -->
			<div v-if="step === 1" class="wizard__type-select">
				<span class="wizard__type-label">{{ t('openbuild', 'App type') }}</span>
				<div class="wizard__type-toggle" role="group" :aria-label="t('openbuild', 'App type')">
					<NcButton
						:type="payload.appType === 'virtual' ? 'primary' : 'secondary'"
						:pressed="payload.appType === 'virtual'"
						@click="setAppType('virtual')">
						{{ t('openbuild', 'Virtual') }}
					</NcButton>
					<NcButton
						:type="payload.appType === 'hybrid' ? 'primary' : 'secondary'"
						:pressed="payload.appType === 'hybrid'"
						@click="setAppType('hybrid')">
						{{ t('openbuild', 'Hybrid') }}
					</NcButton>
				</div>
				<p class="wizard__type-hint">{{ appTypeHint }}</p>
			</div>

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
				<summary>{{ t('openbuild', 'Orphaned resources that need manual cleanup:') }}</summary>
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
				{{ t('openbuild', 'Back') }}
			</NcButton>
			<span class="wizard__footer-spacer" />
			<NcButton
				v-if="step < 4"
				type="primary"
				:disabled="!currentStepValid"
				@click="goNext">
				{{ t('openbuild', 'Next') }}
			</NcButton>

			<NcButton
				v-if="step === 4"
				type="primary"
				:disabled="!allStepsValid || submitting"
				@click="onSubmit">
				<template #icon>
					<span v-if="submitting" class="wizard__spinner" aria-hidden="true" />
				</template>
				{{ submitting ? t('openbuild', 'Creating…') : t('openbuild', 'Create') }}
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
				// unify-apps-with-app-type: a virtual app is built from scratch
				// (the version-preset flow); a hybrid app customizes an installed
				// Nextcloud fleet app (a single delta-only version, no presets).
				appType: 'virtual',
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
		/**
		 * Whether the wizard is creating a hybrid (fleet-app override) app.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		isHybrid() {
			return this.payload.appType === 'hybrid'
		},

		/**
		 * Helper text under the app-type toggle.
		 *
		 * @return {string}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		appTypeHint() {
			return this.isHybrid
				? t('openbuild', 'A hybrid app customizes an installed Nextcloud app. Enter that app\'s id as the slug; its name and pages come from the installed app and you layer your changes on top.')
				: t('openbuild', 'A virtual app is built from scratch in OpenBuild — you define its pages, schemas, and versions.')
		},

		isCustomPreset() {
			return this.payload.preset === 'custom'
		},

		/**
		 * The visual step number (1–4). When not on custom, step 3 is skipped
		 * so the display stays sequential: 1, 2, [skip 3], 4 → shows as 1/3, 2/3, 3/3.
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		displayStep() {
			// Hybrid skips the version-preset steps entirely: 1 (basics) → 2 (review).
			if (this.isHybrid) return this.step === 4 ? 2 : 1
			if (!this.isCustomPreset && this.step === 4) return 3
			return this.step
		},

		/**
		 * Observed behaviour of `visibleStepCount` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		visibleStepCount() {
			if (this.isHybrid) return 2
			return this.isCustomPreset ? 4 : 3
		},

		/**
		 * Observed behaviour of `currentStepValid` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		currentStepValid() {
			if (this.step === 1) return Boolean(this.payload._step1Valid)
			if (this.step === 2) return Boolean(this.payload._step2Valid)
			if (this.step === 3) return Boolean(this.payload._step3Valid)
			return true
		},

		/**
		 * Observed behaviour of `allStepsValid` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		allStepsValid() {
			// A hybrid app needs only the basics (its single delta-only version
			// is created automatically) — no preset/custom-chain validity.
			if (this.isHybrid) return Boolean(this.payload._step1Valid)
			const step3ok = !this.isCustomPreset || Boolean(this.payload._step3Valid)
			return (
				Boolean(this.payload._step1Valid)
				&& Boolean(this.payload._step2Valid)
				&& step3ok
			)
		},
	},

	methods: {
		/**
		 * Observed behaviour of `onModalShowUpdate` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		onModalShowUpdate(value) {
			// Proxy NcModal's update:show event to the parent without mutating the prop.
			if (!value && !this.submitting) {
				this.$emit('update:show', false)
				this.resetState()
			}
		},

		/**
		 * Observed behaviour of `mergePayload` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		mergePayload(partial) {
			this.payload = { ...this.payload, ...partial }
		},

		/**
		 * Set the app type and reset preset state so a switch can't leave a
		 * stale preset selection behind.
		 *
		 * @param {string} value 'virtual' | 'hybrid'
		 *
		 * @return {void}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		setAppType(value) {
			this.mergePayload({ appType: value, preset: value === 'hybrid' ? '' : this.payload.preset })
		},

		/**
		 * Advance to the next step; hybrid apps skip the preset/custom steps and
		 * jump from basics straight to review.
		 *
		 * @return {void}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		goNext() {
			if (this.isHybrid && this.step === 1) {
				// Hybrid skips the preset/custom steps — go straight to review.
				this.step = 4
			} else if (this.step === 2 && !this.isCustomPreset) {
				// Skip step 3 for canned presets.
				this.step = 4
			} else if (this.step < 4) {
				this.step++
			}
		},

		/**
		 * Observed behaviour of `goBack` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		goBack() {
			if (this.step === 4 && this.isHybrid) {
				// Jump back to basics (preset/custom steps were skipped).
				this.step = 1
			} else if (this.step === 4 && !this.isCustomPreset) {
				// Jump back to step 2 (step 3 was skipped).
				this.step = 2
			} else if (this.step > 1) {
				this.step--
			}
		},

		/**
		 * Observed behaviour of `onSubmit` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		async onSubmit() {
			this.submitting = true
			this.errorMessage = null
			this.orphanedResources = []

			if (this.isHybrid) {
				await this.submitHybrid()
				return
			}

			const body = {
				name: this.payload.name,
				slug: this.payload.slug,
				description: this.payload.description,
				preset: this.payload.preset,
				versions: this.payload.versions,
			}

			try {
				const url = generateUrl('/apps/openbuild/api/applications/wizard')
				const { data, status } = await axios.post(url, body)

				if (status === 201 && data.applicationUuid) {
					this.$emit('created', data.applicationUuid)
					this.$emit('update:show', false)
					this.resetState()
				} else {
					this.errorMessage = data.message || t('openbuild', 'An unexpected error occurred.')
					if (data.orphanedResources) {
						this.orphanedResources = data.orphanedResources
					}
				}
			} catch (err) {
				const data = err.response?.data || {}
				this.errorMessage = data.message || err.message || t('openbuild', 'Failed to create the application.')
				if (data.orphanedResources) {
					this.orphanedResources = data.orphanedResources
				}
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Create a hybrid app via the app-overrides shim, which upserts the
		 * hybrid Application + a delta-only version. A fresh hybrid starts with
		 * an empty delta (the fleet app renders unchanged until customized).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
		 */
		async submitHybrid() {
			try {
				const appId = this.payload.slug
				const url = generateUrl('/apps/openbuild/api/app-overrides/{appId}', { appId })
				// Empty delta = "no customization yet"; baseRef links the fleet app.
				const { data } = await axios.put(url, { baseRef: { kind: 'fleet-app', id: appId } })

				this.$emit('created', data && data.applicationUuid ? data.applicationUuid : null)
				this.$emit('update:show', false)
				this.resetState()
			} catch (err) {
				const data = err.response?.data || {}
				this.errorMessage = data.detail || data.message || err.message
					|| t('openbuild', 'Failed to create the hybrid app.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Observed behaviour of `onClose` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		onClose() {
			if (!this.submitting) {
				this.$emit('update:show', false)
				this.resetState()
			}
		},

		/**
		 * Observed behaviour of `resetState` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-1
		 */
		resetState() {
			this.step = 1
			this.payload = {
				appType: 'virtual',
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

.wizard__type-select {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.wizard__type-label {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
}

.wizard__type-toggle {
	display: flex;
	gap: 6px;
}

.wizard__type-hint {
	margin: 8px 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #888);
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
