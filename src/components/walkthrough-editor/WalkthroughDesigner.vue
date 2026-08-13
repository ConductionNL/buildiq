<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="wt-designer">
		<div class="wt-designer__bar">
			<div class="wt-designer__modes" role="tablist">
				<NcButton
					:type="mode === 'walkthrough' ? 'primary' : 'tertiary'"
					@click="mode = 'walkthrough'">
					{{ t('openbuild', 'Walkthrough') }}
				</NcButton>
				<NcButton
					:type="mode === 'setup' ? 'primary' : 'tertiary'"
					@click="mode = 'setup'">
					{{ t('openbuild', 'Setup wizard') }}
				</NcButton>
			</div>
			<span class="wt-designer__spacer" />
			<NcCheckboxRadioSwitch
				v-if="mode === 'walkthrough'"
				type="switch"
				:model-value="enabled"
				@update:modelValue="setEnabled">
				{{ t('openbuild', 'Enabled') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-else
				type="switch"
				:model-value="setupEnabled"
				@update:modelValue="setSetupEnabled">
				{{ t('openbuild', 'Enabled') }}
			</NcCheckboxRadioSwitch>
			<NcButton
				type="primary"
				:disabled="!valid"
				@click="$emit('save-and-preview')">
				{{ t('openbuild', 'Save & preview') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="!valid" type="error">
			{{ t('openbuild', 'Fix these before saving:') }}
			<ul class="wt-designer__errors">
				<li v-for="(e, i) in errors" :key="i">
					{{ e }}
				</li>
			</ul>
		</NcNoteCard>

		<!-- Setup wizard editor (manifest.setup) -->
		<div v-if="mode === 'setup'" class="wt-designer__setup">
			<div class="wt-designer__steps-head">
				<strong>{{ t('openbuild', 'Setup steps') }}</strong>
				<NcButton type="secondary" @click="addSetupStep">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openbuild', 'Add step') }}
				</NcButton>
			</div>
			<ol class="wt-designer__steps">
				<li
					v-for="(step, si) in setupSteps"
					:key="step.id || si"
					class="wt-designer__step">
					<div class="wt-designer__step-head">
						<span class="wt-designer__step-num">{{ si + 1 }}</span>
						<NcTextField
							:label="t('openbuild', 'Step id')"
							:model-value="step.id || ''"
							@update:modelValue="(v) => setSetupStep(si, 'id', v)" />
						<NcButton
							type="tertiary"
							:disabled="si === 0"
							:aria-label="t('openbuild', 'Move up')"
							@click="moveSetupStep(si, -1)">
							<template #icon>
								<ArrowUp :size="20" />
							</template>
						</NcButton>
						<NcButton
							type="tertiary"
							:disabled="si === setupSteps.length - 1"
							:aria-label="t('openbuild', 'Move down')"
							@click="moveSetupStep(si, 1)">
							<template #icon>
								<ArrowDown :size="20" />
							</template>
						</NcButton>
						<NcButton
							type="tertiary"
							:aria-label="t('openbuild', 'Delete step')"
							@click="deleteSetupStep(si)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
					<div class="wt-designer__step-grid">
						<NcSelect
							:input-label="t('openbuild', 'Type')"
							:options="SETUP_TYPES"
							:model-value="step.type || 'info'"
							@update:modelValue="
								(v) => setSetupStep(si, 'type', v)
							" />
						<NcTextField
							:label="t('openbuild', 'Title')"
							:model-value="step.title || ''"
							@update:modelValue="
								(v) => setSetupStep(si, 'title', v)
							" />
						<NcTextField
							:label="t('openbuild', 'Body')"
							:model-value="step.body || ''"
							@update:modelValue="
								(v) => setSetupStep(si, 'body', v)
							" />
						<NcTextField
							v-if="
								step.type === 'choice'
								|| step.type === 'config-fields'
							"
							:label="t('openbuild', 'Config key')"
							:model-value="step.configKey || ''"
							@update:modelValue="
								(v) => setSetupStep(si, 'configKey', v)
							" />
						<NcTextField
							v-if="step.type === 'run-action'"
							:label="t('openbuild', 'Action id')"
							:model-value="step.action || ''"
							@update:modelValue="
								(v) => setSetupStep(si, 'action', v)
							" />
						<NcTextField
							v-if="step.type === 'component'"
							:label="t('openbuild', 'Component')"
							:model-value="step.component || ''"
							@update:modelValue="
								(v) => setSetupStep(si, 'component', v)
							" />
					</div>
					<div v-if="step.type === 'choice'" class="wt-designer__options">
						<div class="wt-designer__options-head">
							<span>{{ t('openbuild', 'Options') }}</span>
							<NcButton
								type="tertiary"
								:aria-label="t('openbuild', 'Add option')"
								@click="addSetupOption(si)">
								<template #icon>
									<Plus :size="20" />
								</template>
							</NcButton>
						</div>
						<div
							v-for="(opt, oi) in step.options || []"
							:key="oi"
							class="wt-designer__option-row">
							<NcTextField
								:label="t('openbuild', 'Value')"
								:model-value="
									opt.value != null ? String(opt.value) : ''
								"
								@update:modelValue="
									(v) => setSetupOption(si, oi, 'value', v)
								" />
							<NcTextField
								:label="t('openbuild', 'Label')"
								:model-value="opt.label || ''"
								@update:modelValue="
									(v) => setSetupOption(si, oi, 'label', v)
								" />
							<NcButton
								type="tertiary"
								:aria-label="t('openbuild', 'Remove option')"
								@click="deleteSetupOption(si, oi)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</div>
					</div>
					<div class="wt-designer__step-switches">
						<NcCheckboxRadioSwitch
							type="switch"
							:model-value="step.required === true"
							@update:modelValue="
								(v) => setSetupStep(si, 'required', v)
							">
							{{ t('openbuild', 'Required (gates the app)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-if="step.type === 'choice'"
							type="switch"
							:model-value="step.multiple === true"
							@update:modelValue="
								(v) => setSetupStep(si, 'multiple', v)
							">
							{{ t('openbuild', 'Multi-select') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-if="step.type === 'summary'"
							type="switch"
							:model-value="step.healthCheck === true"
							@update:modelValue="
								(v) => setSetupStep(si, 'healthCheck', v)
							">
							{{ t('openbuild', 'Health recap') }}
						</NcCheckboxRadioSwitch>
					</div>
				</li>
				<li v-if="setupSteps.length === 0" class="wt-designer__empty">
					{{ t('openbuild', 'No setup steps yet — add one.') }}
				</li>
			</ol>
		</div>

		<WalkthroughRecorder
			v-else-if="recording"
			:app-slug="appSlug"
			:version-slug="versionSlug"
			@pick="onRecorderPick"
			@close="recording = false" />

		<div v-else class="wt-designer__body">
			<!-- Tours rail -->
			<aside class="wt-designer__rail">
				<div class="wt-designer__rail-head">
					<strong>{{ t('openbuild', 'Tours') }}</strong>
					<NcButton
						type="tertiary"
						:aria-label="t('openbuild', 'Add tour')"
						@click="addTour">
						<template #icon>
							<Plus :size="20" />
						</template>
					</NcButton>
				</div>
				<ul class="wt-designer__tours">
					<!--
						The tour rail is a list of choosers. The <li> carries no
						nested interactive content, so `role="button"` is correct
						here and the Enter/Space handlers give it the keyboard
						behaviour a real <button> would have had. `aria-current`
						announces which tour is open rather than leaving it to the
						visual `--active` class alone.
					-->
					<li
						v-for="(tour, ti) in tours"
						:key="tour.id || ti"
						class="wt-designer__tour"
						:class="{
							'wt-designer__tour--active': ti === activeTourIndex,
						}"
						role="button"
						tabindex="0"
						:aria-current="ti === activeTourIndex ? 'true' : undefined"
						@click="activeTourIndex = ti"
						@keydown.enter.prevent="activeTourIndex = ti"
						@keydown.space.prevent="activeTourIndex = ti">
						<span class="wt-designer__tour-name">{{
							tour.title || tour.id || t('openbuild', '(untitled)')
						}}</span>
						<span class="wt-designer__tour-count">{{
							(tour.steps || []).length
						}}</span>
					</li>
					<li v-if="tours.length === 0" class="wt-designer__empty">
						{{ t('openbuild', 'No tours yet — add one.') }}
					</li>
				</ul>
			</aside>

			<!-- Active tour -->
			<section v-if="activeTour" class="wt-designer__main">
				<div class="wt-designer__fields">
					<NcTextField
						:label="t('openbuild', 'Tour id')"
						:model-value="activeTour.id || ''"
						@update:modelValue="(v) => setTour('id', v)" />
					<NcTextField
						:label="t('openbuild', 'Title')"
						:model-value="activeTour.title || ''"
						@update:modelValue="(v) => setTour('title', v)" />
					<NcSelect
						:input-label="t('openbuild', 'Trigger')"
						:options="TRIGGERS"
						:model-value="activeTour.trigger || 'manual'"
						@update:modelValue="(v) => setTour('trigger', v)" />
					<NcTextField
						:label="t('openbuild', 'Min app version')"
						:model-value="activeTour.minAppVersion || ''"
						@update:modelValue="(v) => setTour('minAppVersion', v)" />
					<NcButton type="error" @click="deleteTour">
						{{ t('openbuild', 'Delete tour') }}
					</NcButton>
				</div>

				<div class="wt-designer__steps-head">
					<strong>{{ t('openbuild', 'Steps') }}</strong>
					<span class="wt-designer__spacer" />
					<NcButton
						v-if="appSlug"
						type="secondary"
						@click="recording = true">
						<template #icon>
							<RecordCircleOutline :size="20" />
						</template>
						{{ t('openbuild', 'Record from app') }}
					</NcButton>
					<NcButton type="secondary" @click="addStep">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('openbuild', 'Add step') }}
					</NcButton>
				</div>

				<ol class="wt-designer__steps">
					<li
						v-for="(step, si) in activeTour.steps || []"
						:key="step.id || si"
						class="wt-designer__step">
						<div class="wt-designer__step-head">
							<span class="wt-designer__step-num">{{ si + 1 }}</span>
							<NcTextField
								:label="t('openbuild', 'Step id')"
								:model-value="step.id || ''"
								@update:modelValue="(v) => setStep(si, 'id', v)" />
							<NcButton
								type="tertiary"
								:disabled="si === 0"
								:aria-label="t('openbuild', 'Move up')"
								@click="moveStep(si, -1)">
								<template #icon>
									<ArrowUp :size="20" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								:disabled="si === activeTour.steps.length - 1"
								:aria-label="t('openbuild', 'Move down')"
								@click="moveStep(si, 1)">
								<template #icon>
									<ArrowDown :size="20" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								:aria-label="t('openbuild', 'Delete step')"
								@click="deleteStep(si)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</div>
						<div class="wt-designer__step-grid">
							<NcTextField
								:label="t('openbuild', 'Title')"
								:model-value="step.title || ''"
								@update:modelValue="
									(v) => setStep(si, 'title', v)
								" />
							<NcTextField
								:label="t('openbuild', 'Body')"
								:model-value="step.body || ''"
								@update:modelValue="(v) => setStep(si, 'body', v)" />
							<NcTextField
								:label="t('openbuild', 'Task (optional)')"
								:model-value="step.task || ''"
								@update:modelValue="(v) => setStep(si, 'task', v)" />
							<NcTextField
								:label="t('openbuild', 'Since version')"
								:model-value="step.sinceVersion || ''"
								@update:modelValue="
									(v) => setStep(si, 'sinceVersion', v)
								" />
							<NcSelect
								:input-label="t('openbuild', 'Placement')"
								:options="PLACEMENTS"
								:model-value="step.placement || 'auto'"
								@update:modelValue="
									(v) => setStep(si, 'placement', v)
								" />
							<NcSelect
								:input-label="t('openbuild', 'Target kind')"
								:options="TARGET_KINDS"
								:model-value="
									(step.target && step.target.kind) || 'nav-item'
								"
								@update:modelValue="
									(v) => setTarget(si, 'kind', v)
								" />
							<NcTextField
								:label="
									t(
										'openbuild',
										'Target ref (route / widgetKey / id)',
									)
								"
								:model-value="(step.target && step.target.ref) || ''"
								@update:modelValue="
									(v) => setTarget(si, 'ref', v)
								" />
							<NcSelect
								:input-label="t('openbuild', 'Advance on')"
								:options="ADVANCE_TYPES"
								:model-value="
									(step.advanceOn && step.advanceOn.type)
									|| 'manual'
								"
								@update:modelValue="
									(v) => setAdvance(si, 'type', v)
								" />
							<NcTextField
								v-if="(step.advanceOn || {}).type === 'route-match'"
								:label="t('openbuild', 'Route')"
								:model-value="
									(step.advanceOn && step.advanceOn.route) || ''
								"
								@update:modelValue="
									(v) => setAdvance(si, 'route', v)
								" />
							<template
								v-if="
									(step.advanceOn || {}).type === 'object-created'
								">
								<NcTextField
									:label="t('openbuild', 'Register')"
									:model-value="
										(step.advanceOn && step.advanceOn.register)
										|| ''
									"
									@update:modelValue="
										(v) => setAdvance(si, 'register', v)
									" />
								<NcTextField
									:label="t('openbuild', 'Schema')"
									:model-value="
										(step.advanceOn && step.advanceOn.schema)
										|| ''
									"
									@update:modelValue="
										(v) => setAdvance(si, 'schema', v)
									" />
							</template>
						</div>
						<div class="wt-designer__step-switches">
							<NcCheckboxRadioSwitch
								type="switch"
								:model-value="step.optional === true"
								@update:modelValue="
									(v) => setStep(si, 'optional', v)
								">
								{{ t('openbuild', 'Optional (skip if absent)') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								type="switch"
								:model-value="step.allowManualNext === true"
								@update:modelValue="
									(v) => setStep(si, 'allowManualNext', v)
								">
								{{ t('openbuild', 'Allow manual Next') }}
							</NcCheckboxRadioSwitch>
						</div>
					</li>
					<li
						v-if="(activeTour.steps || []).length === 0"
						class="wt-designer__empty">
						{{ t('openbuild', 'No steps yet — add one.') }}
					</li>
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
import {
	NcButton,
	NcTextField,
	NcSelect,
	NcCheckboxRadioSwitch,
	NcNoteCard,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import RecordCircleOutline from 'vue-material-design-icons/RecordCircleOutline.vue'
import { validateManifest } from '@conduction/nextcloud-vue'
import WalkthroughRecorder from './WalkthroughRecorder.vue'

const TRIGGERS = ['first-visit', 'version-bump', 'empty-index', 'manual']
const PLACEMENTS = ['auto', 'top', 'bottom', 'left', 'right', 'center']
const TARGET_KINDS = ['nav-item', 'widget', 'action', 'page', 'element', 'selector']
const ADVANCE_TYPES = [
	'manual',
	'click-target',
	'route-match',
	'element-appears',
	'object-created',
	'delay',
]
const SETUP_TYPES = [
	'info',
	'choice',
	'config-fields',
	'run-action',
	'summary',
	'component',
]

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

	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		Plus,
		Delete,
		ArrowUp,
		ArrowDown,
		RecordCircleOutline,
		WalkthroughRecorder,
	},

	props: {
		/** The full app manifest being edited. */
		manifest: { type: Object, default: () => ({}) },
		/** The virtual app slug — when set, enables the live click-to-record recorder. */
		appSlug: { type: String, default: '' },
		/** The optional version slug to embed in the recorder iframe. */
		versionSlug: { type: String, default: '' },
	},

	emits: ['update:manifest', 'save-and-preview'],

	data() {
		return {
			mode: 'walkthrough',
			activeTourIndex: 0,
			recording: false,
			TRIGGERS,
			PLACEMENTS,
			TARGET_KINDS,
			ADVANCE_TYPES,
			SETUP_TYPES,
		}
	},

	computed: {
		walkthrough() {
			return (
				(this.manifest && this.manifest.walkthrough) || {
					enabled: true,
					tours: [],
				}
			)
		},
		enabled() {
			return this.walkthrough.enabled !== false
		},
		tours() {
			return Array.isArray(this.walkthrough.tours)
				? this.walkthrough.tours
				: []
		},
		activeTour() {
			return this.tours[this.activeTourIndex] || null
		},
		errors() {
			const { errors } = validateManifest(this.manifest || {})
			const scope = this.mode === 'setup' ? 'setup' : 'walkthrough'
			return (errors || []).filter((e) => String(e).includes(scope))
		},
		valid() {
			return this.errors.length === 0
		},
		setup() {
			return (
				(this.manifest && this.manifest.setup) || {
					enabled: true,
					steps: [],
				}
			)
		},
		setupEnabled() {
			return this.setup.enabled !== false
		},
		setupSteps() {
			return Array.isArray(this.setup.steps) ? this.setup.steps : []
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
			w.tours.push({
				id: `tour-${w.tours.length + 1}`,
				title: t('openbuild', 'New tour'),
				trigger: 'manual',
				steps: [],
			})
			this.commit(w)
			this.$nextTick(() => {
				this.activeTourIndex = w.tours.length - 1
			})
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
		/**
		 * Append a step from a recorder pick. The resolved target drives a
		 * sensible default advance: clicking an instrumented control records a
		 * `click-target` advance (the user clicked it to progress); a bare
		 * selector/page falls back to `manual`.
		 *
		 * @param {{ kind: string, ref?: string, selector?: string }} target The resolved target.
		 * @return {void}
		 */
		onRecorderPick(target) {
			if (!target) return
			const w = this.clone()
			const tour = w.tours[this.activeTourIndex]
			if (!tour) return
			if (!Array.isArray(tour.steps)) tour.steps = []
			const advanceType =
				target.kind === 'page' || target.kind === 'selector'
					? 'manual'
					: 'click-target'
			tour.steps.push({
				id: `step-${tour.steps.length + 1}`,
				title: '',
				sinceVersion: this.manifest.version || '1.0.0',
				target,
				advanceOn: { type: advanceType },
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

		// --- Setup wizard (manifest.setup) editing ---
		/**
		 * Emit a new manifest with the given setup block merged in.
		 *
		 * @param {object} setup The next setup block.
		 * @return {void}
		 */
		commitSetup(setup) {
			this.$emit('update:manifest', { ...(this.manifest || {}), setup })
		},
		cloneSetup() {
			return JSON.parse(JSON.stringify(this.setup))
		},
		setSetupEnabled(v) {
			const s = this.cloneSetup()
			s.enabled = v
			this.commitSetup(s)
		},
		addSetupStep() {
			const s = this.cloneSetup()
			if (!Array.isArray(s.steps)) s.steps = []
			s.steps.push({
				id: `step-${s.steps.length + 1}`,
				type: 'info',
				required: false,
			})
			this.commitSetup(s)
		},
		deleteSetupStep(si) {
			const s = this.cloneSetup()
			s.steps.splice(si, 1)
			this.commitSetup(s)
		},
		moveSetupStep(si, dir) {
			const s = this.cloneSetup()
			const j = si + dir
			if (j < 0 || j >= s.steps.length) return
			const [moved] = s.steps.splice(si, 1)
			s.steps.splice(j, 0, moved)
			this.commitSetup(s)
		},
		setSetupStep(si, key, value) {
			const s = this.cloneSetup()
			s.steps[si][key] = value
			this.commitSetup(s)
		},
		addSetupOption(si) {
			const s = this.cloneSetup()
			const step = s.steps[si]
			if (!Array.isArray(step.options)) step.options = []
			step.options.push({ value: '', label: '' })
			this.commitSetup(s)
		},
		setSetupOption(si, oi, key, value) {
			const s = this.cloneSetup()
			s.steps[si].options[oi][key] = value
			this.commitSetup(s)
		},
		deleteSetupOption(si, oi) {
			const s = this.cloneSetup()
			s.steps[si].options.splice(oi, 1)
			this.commitSetup(s)
		},
	},
}
</script>

<style scoped>
.wt-designer {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.wt-designer__bar {
	display: flex;
	align-items: center;
	gap: 12px;
}

.wt-designer__heading {
	margin: 0;
}

.wt-designer__spacer {
	flex: 1 1 auto;
}

.wt-designer__errors {
	margin: 4px 0 0;
	padding-inline-start: 18px;
}

.wt-designer__body {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.wt-designer__rail {
	flex: 0 0 220px;
	border-inline-end: 1px solid var(--color-border);
	padding-inline-end: 12px;
}

.wt-designer__rail-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.wt-designer__tours {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.wt-designer__tour {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.wt-designer__tour:hover {
	background: var(--color-background-hover);
}

.wt-designer__tour--active {
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.wt-designer__tour-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.wt-designer__main {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 0;
}

.wt-designer__main--empty {
	color: var(--color-text-maxcontrast);
	padding: 24px;
}

.wt-designer__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	align-items: end;
}

.wt-designer__steps-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.wt-designer__steps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.wt-designer__step {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.wt-designer__step-head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.wt-designer__step-num {
	flex: 0 0 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
}

.wt-designer__step-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin-top: 12px;
}

.wt-designer__step-switches {
	display: flex;
	gap: 16px;
	margin-top: 12px;
}

.wt-designer__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px;
}

.wt-designer__modes {
	display: flex;
	gap: 4px;
}

.wt-designer__setup {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.wt-designer__options {
	margin-top: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.wt-designer__options-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.wt-designer__option-row {
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	gap: 8px;
	align-items: end;
	margin-top: 6px;
}
</style>
