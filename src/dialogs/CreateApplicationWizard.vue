<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CreateApplicationWizard — creates a VIRTUAL app via the shared
  @conduction/nextcloud-vue CnWizardDialog.

  Created apps are virtual by definition (built from scratch in Buildiq).
  Hybrid apps are NOT created here — they are installed manifest-supporting
  apps from the App Store that Buildiq layers customization on top of, so
  this wizard has no app-type choice.

  Steps: Basics → Preset → (Custom, only when preset === 'custom') → Review.
  On Create: POSTs to /apps/buildiq/api/applications/wizard and emits
  `created(applicationUuid)` so the parent can navigate.

  spec: buildiq-app-creation-wizard REQ-OBWIZ-001 through REQ-OBWIZ-010
  ADR-004: a modal/dialog lives in its own file under src/dialogs/.
-->
<template>
	<CnWizardDialog
		v-if="show"
		ref="wizard"
		:dialogTitle="t('buildiq', 'Create app')"
		:steps="wizardSteps"
		:defaults="defaults"
		:validate="validateStep"
		:cancelLabel="t('buildiq', 'Cancel')"
		:backLabel="t('buildiq', 'Back')"
		:nextLabel="t('buildiq', 'Next')"
		:submitLabel="t('buildiq', 'Create')"
		:closeLabel="t('buildiq', 'Close')"
		:successText="t('buildiq', 'App created.')"
		@submit="onSubmit"
		@close="onClose">
		<template #step-basics="{ stepData, setStepData }">
			<Step1Basics
				:payload="stepData"
				@update:payload="setStepData"
				@aiAppCreated="onAiAppCreated" />
		</template>

		<template #step-preset="{ stepData, setStepData }">
			<Step2Preset
				:payload="stepData"
				@update:payload="
					(partial) => onPresetUpdate(partial, setStepData)
				" />
		</template>

		<template #step-custom="{ stepData, setStepData }">
			<Step3Custom :payload="stepData" @update:payload="setStepData" />
		</template>

		<template #step-review="{ stepData }">
			<Step4Review :payload="stepData" />
		</template>
	</CnWizardDialog>
</template>

<script>
import { CnWizardDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import Step1Basics from './CreateApplicationWizard/Step1Basics.vue'
import Step2Preset from './CreateApplicationWizard/Step2Preset.vue'
import Step3Custom from './CreateApplicationWizard/Step3Custom.vue'
import Step4Review from './CreateApplicationWizard/Step4Review.vue'
import { resolveAppIcon } from '../utils/iconCatalogues.js'

export default {
	name: 'CreateApplicationWizard',

	components: {
		CnWizardDialog,
		Step1Basics,
		Step2Preset,
		Step3Custom,
		Step4Review,
	},

	props: {
		/** Whether the wizard dialog is shown (bind with `.sync`). */
		show: {
			type: Boolean,
			required: true,
		},
	},

	emits: ['update:show', 'created'],

	data() {
		return {
			// Shadow of the chosen preset so `wizardSteps` can insert/remove the
			// Custom step reactively (CnWizardDialog owns the rest of stepData).
			presetSelected: '',
		}
	},

	computed: {
		/**
		 * Seed values for the wizard's shared stepData.
		 *
		 * @return {object}
		 */
		defaults() {
			return {
				name: '',
				slug: '',
				description: '',
				iconValue: null,
				iconDarkValue: null,
				preset: '',
				versions: [],
				_step1Valid: false,
				_step2Valid: false,
				_step3Valid: true,
			}
		},

		/**
		 * The wizard steps. The Custom step only appears for the custom preset
		 * (canned presets ship a fixed version chain, so there is nothing to
		 * configure).
		 *
		 * @return {Array<{ id: string, label: string }>}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		wizardSteps() {
			const steps = [
				{ id: 'basics', label: t('buildiq', 'Basics') },
				{ id: 'preset', label: t('buildiq', 'Preset') },
			]
			if (this.presetSelected === 'custom') {
				steps.push({ id: 'custom', label: t('buildiq', 'Custom') })
			}
			steps.push({ id: 'review', label: t('buildiq', 'Review') })
			return steps
		},
	},

	methods: {
		/**
		 * Merge a Step 2 payload update and keep `presetSelected` in sync so the
		 * Custom step appears/disappears as the preset changes.
		 *
		 * @param {object} partial The partial stepData from Step2Preset.
		 * @param {Function} setStepData The wizard's stepData setter.
		 * @return {void}
		 */
		onPresetUpdate(partial, setStepData) {
			setStepData(partial)
			if (partial && Object.hasOwn(partial, 'preset')) {
				this.presetSelected = partial.preset
			}
		},

		/**
		 * Per-step validation for CnWizardDialog. Returns `true` to advance or a
		 * message string to block + surface as an error banner.
		 *
		 * @param {string} stepId The step being left.
		 * @param {object} stepData The accumulated wizard data.
		 * @return {(boolean|string)}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		validateStep(stepId, stepData) {
			if (stepId === 'basics') {
				return stepData._step1Valid
					? true
					: t('buildiq', 'Enter a name and a valid slug.')
			}
			if (stepId === 'preset') {
				return stepData._step2Valid
					? true
					: t('buildiq', 'Choose a version preset.')
			}
			if (stepId === 'custom') {
				return stepData._step3Valid
					? true
					: t('buildiq', 'Complete the custom version chain.')
			}
			return true
		},

		/**
		 * Create the virtual app. On success emit `created` + close so the parent
		 * can navigate; on a recoverable failure surface the error in-place via
		 * the wizard's `setError` so the user can fix and resubmit.
		 *
		 * @param {object} stepData The accumulated wizard data.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		async onSubmit(stepData) {
			const body = {
				name: stepData.name,
				slug: stepData.slug,
				description: stepData.description,
				preset: stepData.preset,
				versions: stepData.versions,
			}

			try {
				const url = generateUrl('/apps/buildiq/api/applications/wizard')
				const { data, status } = await axios.post(url, body)

				if (status === 201 && data.applicationUuid) {
					// Attach the chosen icon (best-effort — the app already exists,
					// so a failure here is recoverable on the detail page).
					await this.uploadIcons(data.applicationUuid, stepData)
					this.$emit('created', data.applicationUuid)
					this.$emit('update:show', false)
				} else {
					this.reportError(data)
				}
			} catch (err) {
				this.reportError(err.response?.data || {}, err)
			}
		},

		/**
		 * Synthesize and attach the app icon(s) to the freshly-created
		 * Application. A catalogue pick yields a white light glyph (for the dark
		 * app header) and a no-fill dark glyph (for light backgrounds); the dark
		 * variant defaults to the primary icon so IconService's dark fallback
		 * (iconDark.ref → icon.ref) never serves a white glyph on light.
		 * Non-fatal: logs and returns on failure so app creation still succeeds.
		 *
		 * @param {string} uuid     The created Application UUID.
		 * @param {object} stepData The accumulated wizard data.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		async uploadIcons(uuid, stepData) {
			const lightSvg = resolveAppIcon(stepData.iconValue, { dark: false })
			const darkSource = stepData.iconDarkValue || stepData.iconValue
			const darkSvg = resolveAppIcon(darkSource, { dark: true })
			if (!lightSvg && !darkSvg) {
				return
			}
			try {
				if (lightSvg) {
					await this.attachIcon(uuid, 'icon', 'app-icon.svg', lightSvg)
				}
				if (darkSvg) {
					await this.attachIcon(
						uuid,
						'iconDark',
						'app-icon-dark.svg',
						darkSvg,
					)
				}
			} catch (err) {
				console.error('Buildiq: failed to attach app icon', err)
			}
		},

		/**
		 * Upload one SVG to the Application object and patch its icon ref, using
		 * the same OpenRegister files endpoints as the detail-page IconUploadSection.
		 *
		 * @param {string} uuid     The Application UUID.
		 * @param {string} field    The record field to patch (`icon` / `iconDark`).
		 * @param {string} filename The attachment filename.
		 * @param {string} svg      The SVG markup to store.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		async attachIcon(uuid, field, filename, svg) {
			// OR's files#create endpoint takes JSON { name, content } and writes
			// the content verbatim — no multipart needed for text SVG.
			const filesUrl = generateUrl(
				`/apps/openregister/api/objects/buildiq/application/${uuid}/files`,
			)
			await axios.post(filesUrl, { name: filename, content: svg })

			// PATCH (partial merge) — a PUT would replace the whole object and fail
			// validation on the now-missing required name/slug.
			const patchUrl = generateUrl(
				`/apps/openregister/api/objects/buildiq/application/${uuid}`,
			)
			await axios.patch(patchUrl, { [field]: { ref: filename } })
		},

		/**
		 * Surface a submit failure on the wizard (recoverable — keeps the dialog
		 * open so the user can correct and retry).
		 *
		 * @param {object} data The error payload from the API.
		 * @param {Error} [err] The original error, for a message fallback.
		 * @return {void}
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		reportError(data, err) {
			let message = data.message || data.detail
			if (!message && err) message = err.message
			if (!message) message = t('buildiq', 'Failed to create the application.')
			if (
				Array.isArray(data.orphanedResources)
				&& data.orphanedResources.length > 0
			) {
				message +=
					' '
					+ t('buildiq', 'Some resources need manual cleanup: {list}', {
						list: data.orphanedResources.join(', '),
					})
			}
			if (this.$refs.wizard) {
				this.$refs.wizard.setError(message)
			}
		},

		/**
		 * Close the wizard. `v-if="show"` unmounts it, so it always reopens fresh.
		 *
		 * @return {void}
		 */
		onClose() {
			this.presetSelected = ''
			this.$emit('update:show', false)
		},

		/**
		 * Handle a copilot-created app (spec ai-copilot REQ-OBAIC-006): close
		 * the wizard and route to the new application's page designer, mirroring
		 * the manual-creation `created` event's "route to it" contract.
		 *
		 * @param {string} appSlug - the newly-created app's slug.
		 * @return {void}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		onAiAppCreated(appSlug) {
			this.onClose()
			if (this.$router && appSlug) {
				this.$router
					.push({ name: 'PageDesigner', params: { slug: appSlug } })
					.catch(() => {})
			}
			// Emit with no uuid so a parent's `created(applicationUuid)` handler
			// (e.g. DashboardIndex.onAppCreated) refreshes its listing instead of
			// navigating a second time with a slug where it expects a UUID.
			this.$emit('created')
		},
	},
}
</script>
