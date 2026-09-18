<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ScreenOverrideList: the screens already published for this schema, and
  - which of them stopped applying.
  -
  - An override is withheld when the layout beneath it moves. The page still
  - renders, so nobody notices from the page. This list is where they notice,
  - and the re-cut button is the way back: it re-pins the override to the base
  - it has now and says which parts it had to drop.
  -
  - @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
  -->
<template>
	<fieldset class="override-list">
		<legend>{{ t('buildiq', 'Screens for this schema') }}</legend>

		<p v-if="loading" class="override-list__note">
			{{ t('buildiq', 'Reading the screens.') }}
		</p>

		<p v-else-if="error" class="override-list__warn" role="alert">
			{{ error }}
		</p>

		<p v-else-if="layouts.length === 0" class="override-list__note">
			{{
				t(
					'buildiq',
					'No screen is published for this schema yet. Cases render the app manifest unchanged.',
				)
			}}
		</p>

		<ul v-else class="override-list__items">
			<li
				v-for="layout in layouts"
				:key="layout.id"
				class="override-list__item">
				<span class="override-list__name">{{
					layout.name || layout.id
				}}</span>
				<span class="override-list__audience">{{
					audienceLabel(layout)
				}}</span>
				<span
					class="override-list__state"
					:class="{
						'override-list__state--drifted': layout.drifted,
					}">
					{{ stateLabel(layout) }}
				</span>

				<button
					v-if="layout.drifted"
					type="button"
					:disabled="recutting === layout.id"
					@click="recut(layout)">
					{{ t('buildiq', 'Re-cut against the screen underneath') }}
				</button>

				<p
					v-if="layout.drifted && (layout.orphanedPaths || []).length"
					class="override-list__warn">
					{{ t('buildiq', 'No longer there:') }}
					{{ (layout.orphanedPaths || []).join(', ') }}
				</p>

				<p
					v-if="droppedFor(layout.id)"
					class="override-list__note"
					role="status">
					{{ t('buildiq', 'Re-cut. Dropped:') }}
					{{ droppedFor(layout.id) }}
				</p>
			</li>
		</ul>
	</fieldset>
</template>

<script>
import { fetchPageLayouts, recutOverride } from '../../../services/pageLayouts.js'

export default {
	name: 'ScreenOverrideList',

	props: {
		register: {
			type: String,
			default: '',
		},

		schema: {
			type: String,
			default: '',
		},
	},

	emits: ['recut'],

	data() {
		return {
			layouts: [],
			loading: false,
			error: '',
			recutting: '',
			dropped: {},
		}
	},

	watch: {
		register: {
			immediate: true,
			/**
			 * Re-read whenever the page binds to another schema.
			 *
			 * @return {void}
			 */
			handler() {
				this.reload()
			},
		},

		schema: {
			/**
			 * The same for the schema, which is the other half of the scope.
			 *
			 * @return {void}
			 */
			handler() {
				this.reload()
			},
		},
	},

	methods: {
		/**
		 * Read the layouts published for this scope.
		 *
		 * @return {Promise<void>}
		 */
		async reload() {
			if (this.register === '' || this.schema === '') {
				this.layouts = []
				return
			}

			this.loading = true
			this.error = ''

			try {
				this.layouts = await fetchPageLayouts({
					register: this.register,
					schema: this.schema,
				})
			} catch (refusal) {
				this.error = refusal.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Who a layout is for.
		 *
		 * @param {object} layout - the layout.
		 * @return {string} The label.
		 */
		audienceLabel(layout) {
			const audience = layout.audience || {}
			const kind = audience.kind || 'everyone'

			if (kind === 'everyone') {
				return t('buildiq', 'Everyone')
			}
			if (kind === 'portal') {
				return t('buildiq', 'Portal visitors')
			}
			return `${kind}: ${audience.ref || ''}`
		},

		/**
		 * Whether a layout still applies.
		 *
		 * A drifted override is neither applied nor lost, and saying only
		 * "needs review" would leave a maintainer guessing which of the two.
		 *
		 * @param {object} layout - the layout.
		 * @return {string} The label.
		 */
		stateLabel(layout) {
			if (layout.drifted) {
				return t('buildiq', 'Withheld, the screen underneath changed')
			}
			if (layout.layoutDelta) {
				return t('buildiq', 'Applies')
			}
			return t('buildiq', 'Base screen')
		},

		/**
		 * What a just-finished re-cut dropped, as one readable line.
		 *
		 * @param {string} layoutId - the layout's id.
		 * @return {string} The paths, or an empty string.
		 */
		droppedFor(layoutId) {
			const paths = this.dropped[layoutId]
			if (!paths) {
				return ''
			}
			return paths.length ? paths.join(', ') : t('buildiq', 'nothing')
		},

		/**
		 * Re-pin a drifted override to the base it has now.
		 *
		 * @param {object} layout - the drifted override.
		 * @return {Promise<void>}
		 */
		async recut(layout) {
			this.recutting = layout.id
			this.error = ''

			try {
				const result = await recutOverride(layout.id)
				this.dropped = { ...this.dropped, [layout.id]: result.dropped }
				this.$emit('recut', result)
				await this.reload()
			} catch (refusal) {
				this.error = refusal.message
			} finally {
				this.recutting = ''
			}
		},
	},
}
</script>

<style scoped>
.override-list__items {
	list-style: none;
	padding: 0;
}

.override-list__item {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	padding-block: 4px;
}

.override-list__audience,
.override-list__state {
	color: var(--color-text-maxcontrast);
}

.override-list__state--drifted {
	color: var(--color-warning-text, var(--color-error));
}

.override-list__warn {
	color: var(--color-error);
}
</style>
