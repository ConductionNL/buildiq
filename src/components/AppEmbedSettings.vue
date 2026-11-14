<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppEmbedSettings — the Embed section of the app settings
  - (apps-embed-in-other-sites): switch embedding on, list the sites allowed to
  - frame the app, and copy an iframe snippet for the whole app or one page.
  - Emits the setting; the settings modal's parent saves it. A list with a
  - site that cannot be saved is not emitted: the row says why instead.
  -->
<template>
	<div class="app-embed" data-test="app-embed-settings">
		<h4 class="app-embed__title">
			{{ t('buildiq', 'Embed') }}
		</h4>
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="setting.enabled"
			:disabled="busy"
			@update:modelValue="setEnabled">
			{{ t('buildiq', 'Allow other sites to show this app') }}
		</NcCheckboxRadioSwitch>
		<p class="app-embed__hint">
			{{ t('buildiq', 'Only people who can already sign in to Nextcloud can use the embedded app; embedding never makes it public. It works on sites that share this Nextcloud\'s site, such as an intranet on a sibling subdomain.') }}
		</p>

		<template v-if="setting.enabled">
			<div
				v-for="(row, index) in rows"
				:key="index"
				class="app-embed__origin">
				<NcTextField
					:modelValue="row"
					:label="t('buildiq', 'Allowed site, e.g. https://intranet.example.nl')"
					:error="!!problems[index]"
					:helperText="problems[index]"
					:disabled="busy"
					@update:modelValue="updateRow(index, $event)" />
				<NcButton
					variant="tertiary"
					:disabled="busy"
					:aria-label="t('buildiq', 'Remove site')"
					@click="removeRow(index)">
					{{ t('buildiq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton variant="secondary" :disabled="busy" @click="addRow">
				{{ t('buildiq', 'Add site') }}
			</NcButton>

			<div v-if="setting.origins.length" class="app-embed__snippet">
				<NcSelect
					v-model="page"
					:options="pageOptions"
					:clearable="false"
					:inputLabel="t('buildiq', 'What to embed')" />
				<label class="app-embed__code-label" for="app-embed-snippet">
					{{ t('buildiq', 'Snippet to paste into the other site') }}
				</label>
				<textarea
					id="app-embed-snippet"
					class="app-embed__code"
					readonly
					rows="3"
					:value="snippet" />
				<NcButton variant="primary" @click="copy">
					{{ copied ? t('buildiq', 'Copied') : t('buildiq', 'Copy snippet') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { embedSnippet, normaliseEmbed, originProblem } from '../utils/appEmbed.js'

export default {
	name: 'AppEmbedSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The stored `Application.embed`. */
		embed: { type: Object, default: null },
		/** The app slug, for the snippet. */
		slug: { type: String, default: '' },
		/** The app name, the frame's title. */
		appName: { type: String, default: '' },
		busy: { type: Boolean, default: false },
	},

	emits: ['update:embed'],

	data() {
		const setting = normaliseEmbed(this.embed)
		return {
			rows: [...setting.origins],
			pages: [],
			page: null,
			copied: false,
		}
	},

	computed: {
		setting() {
			return normaliseEmbed(this.embed)
		},

		problems() {
			return this.rows.map((row) => (String(row).trim() === '' ? '' : originProblem(row)))
		},

		pageOptions() {
			const whole = { label: this.t('buildiq', 'The whole app'), route: '' }
			const pages = (this.pages || [])
				.filter((page) => page && typeof page.route === 'string' && !page.route.includes(':'))
				.map((page) => ({ label: page.title || page.route, route: page.route }))
			return [whole, ...pages]
		},

		snippet() {
			const choice = this.page || this.pageOptions[0]
			return embedSnippet({
				origin: window.location.origin,
				slug: this.slug,
				title: choice.route ? `${this.appName} – ${choice.label}` : this.appName,
				pageRoute: choice.route,
			})
		},
	},

	watch: {
		embed: {
			deep: true,
			handler(next) {
				this.rows = [...normaliseEmbed(next).origins]
			},
		},

		'setting.enabled': function(enabled) {
			if (enabled) {
				this.loadPages()
			}
		},
	},

	mounted() {
		if (this.setting.enabled) {
			this.loadPages()
		}
	},

	methods: {
		/**
		 * The app's pages for the one-page snippet, read from the app's own
		 * manifest endpoint once embedding is on. Without them the
		 * whole-app snippet still works.
		 *
		 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
		 */
		async loadPages() {
			if (!this.slug || this.pages.length) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(this.slug)}/manifest`),
				)
				this.pages = Array.isArray(data?.pages) ? data.pages : []
			} catch {
				this.pages = []
			}
		},

		setEnabled(enabled) {
			this.$emit('update:embed', { ...this.setting, enabled: enabled === true })
		},

		addRow() {
			this.rows = [...this.rows, '']
		},

		removeRow(index) {
			const next = this.rows.slice()
			next.splice(index, 1)
			this.rows = next
			this.emitOrigins()
		},

		updateRow(index, value) {
			const next = this.rows.slice()
			next[index] = value
			this.rows = next
			this.emitOrigins()
		},

		/**
		 * Emit the listed sites once every one of them can be saved; an empty
		 * row is skipped, a refused one keeps the setting as it was.
		 *
		 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
		 */
		emitOrigins() {
			if (this.problems.some((problem) => problem !== '')) {
				return
			}
			const origins = [...new Set(this.rows.map((row) => String(row).trim()).filter((row) => row !== ''))]
			this.$emit('update:embed', { ...this.setting, origins })
		},

		async copy() {
			try {
				await navigator.clipboard.writeText(this.snippet)
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped>
.app-embed {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.app-embed__title {
	margin: 0;
}

.app-embed__hint {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.app-embed__origin {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.app-embed__snippet {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.app-embed__code {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85rem;
	color: var(--color-main-text);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
</style>
