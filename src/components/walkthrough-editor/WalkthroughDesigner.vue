<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="wt-designer">
		<div class="wt-designer__bar">
			<h2 class="wt-designer__heading">{{ t('openbuild', 'Walkthrough designer') }}</h2>
			<span class="wt-designer__spacer" />
			<NcCheckboxRadioSwitch type="switch" :checked="enabled" @update:checked="setEnabled">
				{{ t('openbuild', 'Enabled') }}
			</NcCheckboxRadioSwitch>
			<NcButton type="primary" :disabled="!valid" @click="$emit('save-and-preview')">
				{{ t('openbuild', 'Save & preview') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="!valid" type="error">
			{{ t('openbuild', 'Fix these before saving:') }}
			<ul class="wt-designer__errors"><li v-for="(e, i) in errors" :key="i">{{ e }}</li></ul>
		</NcNoteCard>

		<div class="wt-designer__body">
			<!-- Tours rail -->
			<aside class="wt-designer__rail">
				<div class="wt-designer__rail-head">
					<strong>{{ t('openbuild', 'Tours') }}</strong>
					<NcButton type="tertiary" :aria-label="t('openbuild', 'Add tour')" @click="addTour">
						<template #icon><Plus :size="20" /></template>
					</NcButton>
				</div>
				<ul class="wt-designer__tours">
					<li v-for="(tour, ti) in tours"
						:key="tour.id || ti"
						class="wt-designer__tour"
						:class="{ 'wt-designer__tour--active': ti === activeTourIndex }"
						@click="activeTourIndex = ti">
						<span class="wt-designer__tour-name">{{ tour.title || tour.id || t('openbuild', '(untitled)') }}</span>
						<span class="wt-designer__tour-count">{{ (tour.steps || []).length }}</span>
					</li>
					<li v-if="tours.length === 0" class="wt-designer__empty">{{ t('openbuild', 'No tours yet — add one.') }}</li>
				</ul>
			</aside>

			<!-- Active tour -->
			<section v-if="activeTour" class="wt-designer__main">
				<div class="wt-designer__fields">
					<NcTextField :label="t('openbuild', 'Tour id')" :value="activeTour.id || ''" @update:value="v => setTour('id', v)" />
					<NcTextField :label="t('openbuild', 'Title')" :value="activeTour.title || ''" @update:value="v => setTour('title', v)" />
					<NcSelect :input-label="t('openbuild', 'Trigger')" :options="TRIGGERS" :value="activeTour.trigger || 'manual'" @input="v => setTour('trigger', v)" />
					<NcTextField :label="t('openbuild', 'Min app version')" :value="activeTour.minAppVersion || ''" @update:value="v => setTour('minAppVersion', v)" />
					<NcButton type="error" @click="deleteTour">{{ t('openbuild', 'Delete tour') }}</NcButton>
				</div>

				<div class="wt-designer__steps-head">
					<strong>{{ t('openbuild', 'Steps') }}</strong>
					<NcButton type="secondary" @click="addStep">
						<template #icon><Plus :size="20" /></template>
						{{ t('openbuild', 'Add step') }}
					</NcButton>
				</div>

				<ol class="wt-designer__steps">
					<li v-for="(step, si) in (activeTour.steps || [])" :key="step.id || si" class="wt-designer__step">
						<div class="wt-designer__step-head">
							<span class="wt-designer__step-num">{{ si + 1 }}</span>
							<NcTextField :label="t('openbuild', 'Step id')" :value="step.id || ''" @update:value="v => setStep(si, 'id', v)" />
							<NcButton type="tertiary" :disabled="si === 0" :aria-label="t('openbuild', 'Move up')" @click="moveStep(si, -1)">
								<template #icon><ArrowUp :size="20" /></template>
							</NcButton>
							<NcButton type="tertiary" :disabled="si === (activeTour.steps.length - 1)" :aria-label="t('openbuild', 'Move down')" @click="moveStep(si, 1)">
								<template #icon><ArrowDown :size="20" /></template>
							</NcButton>
							<NcButton type="tertiary" :aria-label="t('openbuild', 'Delete step')" @click="deleteStep(si)">
								<template #icon><Delete :size="20" /></template>
							</NcButton>
						</div>
						<div class="wt-designer__step-grid">
							<NcTextField :label="t('openbuild', 'Title')" :value="step.title || ''" @update:value="v => setStep(si, 'title', v)" />
							<NcTextField :label="t('openbuild', 'Body')" :value="step.body || ''" @update:value="v => setStep(si, 'body', v)" />
							<NcTextField :label="t('openbuild', 'Task (optional)')" :value="step.task || ''" @update:value="v => setStep(si, 'task', v)" />
							<NcTextField :label="t('openbuild', 'Since version')" :value="step.sinceVersion || ''" @update:value="v => setStep(si, 'sinceVersion', v)" />
							<NcSelect :input-label="t('openbuild', 'Placement')" :options="PLACEMENTS" :value="step.placement || 'auto'" @input="v => setStep(si, 'placement', v)" />
							<NcSelect :input-label="t('openbuild', 'Target kind')" :options="TARGET_KINDS" :value="(step.target && step.target.kind) || 'nav-item'" @input="v => setTarget(si, 'kind', v)" />
							<NcTextField :label="t('openbuild', 'Target ref (route / widgetKey / id)')" :value="(step.target && step.target.ref) || ''" @update:value="v => setTarget(si, 'ref', v)" />
							<NcSelect :input-label="t('openbuild', 'Advance on')" :options="ADVANCE_TYPES" :value="(step.advanceOn && step.advanceOn.type) || 'manual'" @input="v => setAdvance(si, 'type', v)" />
							<NcTextField v-if="(step.advanceOn || {}).type === 'route-match'" :label="t('openbuild', 'Route')" :value="(step.advanceOn && step.advanceOn.route) || ''" @update:value="v => setAdvance(si, 'route', v)" />
							<template v-if="(step.advanceOn || {}).type === 'object-created'">
								<NcTextField :label="t('openbuild', 'Register')" :value="(step.advanceOn && step.advanceOn.register) || ''" @update:value="v => setAdvance(si, 'register', v)" />
								<NcTextField :label="t('openbuild', 'Schema')" :value="(step.advanceOn && step.advanceOn.schema) || ''" @update:value="v => setAdvance(si, 'schema', v)" />
							</template>
						</div>
						<div class="wt-designer__step-switches">
							<NcCheckboxRadioSwitch type="switch" :checked="step.optional === true" @update:checked="v => setStep(si, 'optional', v)">{{ t('openbuild', 'Optional (skip if absent)') }}</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch type="switch" :checked="step.allowManualNext === true" @update:checked="v => setStep(si, 'allowManualNext', v)">{{ t('openbuild', 'Allow manual Next') }}</NcCheckboxRadioSwitch>
						</div>
					</li>
					<li v-if="(activeTour.steps || []).length === 0" class="wt-designer__empty">{{ t('openbuild', 'No steps yet — add one.') }}</li>
				</ol>
			</section>
			<section v-else class="wt-designer__main wt-designer__main--empty">
				{{ t('openbuild', 'Select or add a tour to begin.') }}
			</section>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import { validateManifest } from '@conduction/nextcloud-vue'

const TRIGGERS = ['first-visit', 'version-bump', 'empty-index', 'manual']
const PLACEMENTS = ['auto', 'top', 'bottom', 'left', 'right', 'center']
const TARGET_KINDS = ['nav-item', 'widget', 'action', 'page', 'element', 'selector']
const ADVANCE_TYPES = ['manual', 'click-target', 'route-match', 'element-appears', 'object-created', 'delay']

/**
 * WalkthroughDesigner — visual editor for an app's `manifest.walkthrough` block
 * (ADR-043). A controlled component: takes the full `manifest` and emits
 * `update:manifest` on every edit and `save-and-preview` when the user saves —
 * the same contract as PageDesigner, so it reuses the designer host's load +
 * versioned-delta persistence. Form-based authoring of tours and their
 * spotlight steps; the live click-to-record recorder is a follow-up.
 */
export default {
	name: 'WalkthroughDesigner',

	components: { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard, Plus, Delete, ArrowUp, ArrowDown },

	props: {
		/** The full app manifest being edited. */
		manifest: { type: Object, default: () => ({}) },
	},

	emits: ['update:manifest', 'save-and-preview'],

	data() {
		return { activeTourIndex: 0, TRIGGERS, PLACEMENTS, TARGET_KINDS, ADVANCE_TYPES }
	},

	computed: {
		walkthrough() {
			return (this.manifest && this.manifest.walkthrough) || { enabled: true, tours: [] }
		},
		enabled() {
			return this.walkthrough.enabled !== false
		},
		tours() {
			return Array.isArray(this.walkthrough.tours) ? this.walkthrough.tours : []
		},
		activeTour() {
			return this.tours[this.activeTourIndex] || null
		},
		errors() {
			const { errors } = validateManifest(this.manifest || {})
			return (errors || []).filter((e) => String(e).includes('walkthrough'))
		},
		valid() {
			return this.errors.length === 0
		},
	},

	methods: {
		t,
		/**
		 * Emit a new manifest with the given walkthrough block merged in.
		 *
		 * @param {object} walkthrough The next walkthrough block.
		 * @return {void}
		 */
		commit(walkthrough) {
			this.$emit('update:manifest', { ...(this.manifest || {}), walkthrough })
		},
		clone() {
			return JSON.parse(JSON.stringify(this.walkthrough))
		},
		setEnabled(v) {
			const w = this.clone()
			w.enabled = v
			this.commit(w)
		},
		addTour() {
			const w = this.clone()
			if (!Array.isArray(w.tours)) w.tours = []
			w.tours.push({ id: `tour-${w.tours.length + 1}`, title: t('openbuild', 'New tour'), trigger: 'manual', steps: [] })
			this.commit(w)
			this.$nextTick(() => { this.activeTourIndex = w.tours.length - 1 })
		},
		deleteTour() {
			const w = this.clone()
			w.tours.splice(this.activeTourIndex, 1)
			this.commit(w)
			this.activeTourIndex = Math.max(0, this.activeTourIndex - 1)
		},
		setTour(key, value) {
			const w = this.clone()
			w.tours[this.activeTourIndex][key] = value
			this.commit(w)
		},
		addStep() {
			const w = this.clone()
			const tour = w.tours[this.activeTourIndex]
			if (!Array.isArray(tour.steps)) tour.steps = []
			tour.steps.push({
				id: `step-${tour.steps.length + 1}`,
				sinceVersion: this.manifest.version || '1.0.0',
				target: { kind: 'nav-item', ref: '' },
				advanceOn: { type: 'manual' },
			})
			this.commit(w)
		},
		deleteStep(si) {
			const w = this.clone()
			w.tours[this.activeTourIndex].steps.splice(si, 1)
			this.commit(w)
		},
		moveStep(si, dir) {
			const w = this.clone()
			const steps = w.tours[this.activeTourIndex].steps
			const j = si + dir
			if (j < 0 || j >= steps.length) return
			const [moved] = steps.splice(si, 1)
			steps.splice(j, 0, moved)
			this.commit(w)
		},
		setStep(si, key, value) {
			const w = this.clone()
			w.tours[this.activeTourIndex].steps[si][key] = value
			this.commit(w)
		},
		setTarget(si, key, value) {
			const w = this.clone()
			const step = w.tours[this.activeTourIndex].steps[si]
			step.target = { ...(step.target || {}), [key]: value }
			this.commit(w)
		},
		setAdvance(si, key, value) {
			const w = this.clone()
			const step = w.tours[this.activeTourIndex].steps[si]
			step.advanceOn = { ...(step.advanceOn || {}), [key]: value }
			this.commit(w)
		},
	},
}
</script>

<style scoped>
.wt-designer { display: flex; flex-direction: column; gap: 12px; }
.wt-designer__bar { display: flex; align-items: center; gap: 12px; }
.wt-designer__heading { margin: 0; }
.wt-designer__spacer { flex: 1 1 auto; }
.wt-designer__errors { margin: 4px 0 0; padding-inline-start: 18px; }
.wt-designer__body { display: flex; gap: 16px; align-items: flex-start; }
.wt-designer__rail { flex: 0 0 220px; border-inline-end: 1px solid var(--color-border); padding-inline-end: 12px; }
.wt-designer__rail-head { display: flex; align-items: center; justify-content: space-between; }
.wt-designer__tours { list-style: none; margin: 8px 0 0; padding: 0; }
.wt-designer__tour { display: flex; justify-content: space-between; align-items: center; padding: 8px; border-radius: var(--border-radius); cursor: pointer; }
.wt-designer__tour:hover { background: var(--color-background-hover); }
.wt-designer__tour--active { background: var(--color-primary-element-light); font-weight: 600; }
.wt-designer__tour-count { color: var(--color-text-maxcontrast); font-size: 0.85rem; }
.wt-designer__main { flex: 1 1 auto; display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.wt-designer__main--empty { color: var(--color-text-maxcontrast); padding: 24px; }
.wt-designer__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end; }
.wt-designer__steps-head { display: flex; align-items: center; justify-content: space-between; }
.wt-designer__steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.wt-designer__step { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 12px; }
.wt-designer__step-head { display: flex; align-items: center; gap: 8px; }
.wt-designer__step-num { flex: 0 0 24px; height: 24px; border-radius: 50%; background: var(--color-primary-element); color: var(--color-primary-element-text); display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; }
.wt-designer__step-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 12px; }
.wt-designer__step-switches { display: flex; gap: 16px; margin-top: 12px; }
.wt-designer__empty { color: var(--color-text-maxcontrast); padding: 12px; }
</style>
