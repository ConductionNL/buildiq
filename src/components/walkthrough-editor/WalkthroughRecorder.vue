<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="wt-recorder">
		<div class="wt-recorder__bar">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="recording"
				@update:modelValue="setRecording">
				{{
					recording
						? t(
								'buildiq',
								'Recording — click an element to capture its target',
							)
						: t(
								'buildiq',
								'Paused — navigate the app, then resume recording',
							)
				}}
			</NcCheckboxRadioSwitch>
			<span class="wt-recorder__spacer" />
			<span v-if="lastPick" class="wt-recorder__last">
				{{ t('buildiq', 'Last:') }}
				<code
					>{{ lastPick.kind
					}}{{ lastPick.ref ? ' · ' + lastPick.ref : '' }}</code
				>
			</span>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('buildiq', 'Done recording') }}
			</NcButton>
		</div>
		<div
			class="wt-recorder__frame-wrap"
			:class="{ 'wt-recorder__frame-wrap--armed': recording }">
			<iframe
				ref="frame"
				class="wt-recorder__frame"
				:src="iframeSrc"
				:title="t('buildiq', 'App preview')"
				@load="onIframeLoad" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { resolveTargetFromElement } from './recorderTargetResolver.js'

/**
 * WalkthroughRecorder — live click-to-record surface (ADR-043). Embeds the
 * running virtual app (its own CnAppRoot runtime at `/apps/buildiq/builder/
 * {slug}`) in a same-origin iframe and, while armed, captures clicks inside it:
 * each click is resolved to the most stable walkthrough target via
 * {@link resolveTargetFromElement} and emitted as `pick` (the designer turns it
 * into a step) instead of triggering the app's own action. Toggle recording off
 * to navigate the app to the page where the next target lives, then back on.
 */
export default {
	name: 'WalkthroughRecorder',

	components: { NcButton, NcCheckboxRadioSwitch },

	props: {
		/** The virtual app slug whose runtime to embed. */
		appSlug: { type: String, required: true },
		/** Optional version slug (`?_version=`). */
		versionSlug: { type: String, default: '' },
	},

	emits: ['pick', 'close'],

	data() {
		return { recording: true, lastPick: null }
	},

	computed: {
		iframeSrc() {
			const base = generateUrl(`/apps/buildiq/builder/${this.appSlug}`)
			return this.versionSlug
				? `${base}?_version=${encodeURIComponent(this.versionSlug)}`
				: base
		},
	},

	// Non-reactive instance state for the bound iframe document + listener: a
	// DOM document must never be made reactive (Vue would deep-walk it).
	created() {
		this.boundDoc = null
		this.boundClick = null
	},

	beforeUnmount() {
		this.detach()
	},

	methods: {
		t,
		setRecording(v) {
			this.recording = v
		},

		/**
		 * Attach the capture-phase click listener to the (same-origin) iframe
		 * document once it loads. SPA navigation inside the iframe keeps the same
		 * document, so a single listener covers the whole session.
		 *
		 * @return {void}
		 */
		onIframeLoad() {
			this.detach()
			try {
				const doc =
					this.$refs.frame
					&& this.$refs.frame.contentWindow
					&& this.$refs.frame.contentWindow.document
				if (!doc) return
				this.boundDoc = doc
				this.boundClick = (e) => this.handleDocClick(e)
				doc.addEventListener('click', this.boundClick, true)
			} catch (e) {
				// Cross-origin (shouldn't happen for the same-origin runtime) — ignore.
			}
		},

		/**
		 * Handle a click inside the iframe: while recording, swallow it and emit
		 * the resolved target instead of letting the app act on it.
		 *
		 * @param {Event} e The click event from the iframe document.
		 * @return {void}
		 */
		handleDocClick(e) {
			if (!this.recording) return
			e.preventDefault()
			e.stopPropagation()
			this.handlePick(e.target)
		},

		/**
		 * Resolve an element to a target and emit it.
		 *
		 * @param {Element} el The clicked element.
		 * @return {void}
		 */
		handlePick(el) {
			const target = resolveTargetFromElement(el)
			if (!target) return
			this.lastPick = target
			/**
			 * @event pick Emitted when the owner clicks an element while recording.
			 * @type {{ kind: string, ref?: string, selector?: string }}
			 */
			this.$emit('pick', target)
		},

		/**
		 * Remove the iframe click listener.
		 *
		 * @return {void}
		 */
		detach() {
			if (this.boundDoc && this.boundClick) {
				try {
					this.boundDoc.removeEventListener('click', this.boundClick, true)
				} catch (e) {
					/* noop */
				}
			}
			this.boundDoc = null
			this.boundClick = null
		},
	},
}
</script>

<style scoped>
.wt-recorder {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wt-recorder__bar {
	display: flex;
	align-items: center;
	gap: 12px;
}

.wt-recorder__spacer {
	flex: 1 1 auto;
}

.wt-recorder__last code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: var(--border-radius);
}

.wt-recorder__frame-wrap {
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	height: 70vh;
}

.wt-recorder__frame-wrap--armed {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.wt-recorder__frame {
	width: 100%;
	height: 100%;
	border: 0;
}
</style>
