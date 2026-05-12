<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Textarea-based manifest editor — v1 of the OpenBuilt authoring UI.
  - Visual editors land in chain spec #5 (openbuilt-page-editor).
  - Per design.md the editor is integrator-only; the in-app help string
  - openbuilt.editor.help documents that.
  -
  - Per ADR-022 the editor reads/writes Application objects via OR's
  - REST API directly — no app-local CRUD wrapper.
  -->
<template>
	<div class="openbuilt-editor">
		<NcAppContent>
			<div class="openbuilt-editor__layout">
				<aside class="openbuilt-editor__list">
					<h3>{{ t('openbuilt', 'Virtual apps') }}</h3>
					<ul>
						<li
							v-for="app in applications"
							:key="app.uuid || app.id"
							:class="{ active: app.uuid === selectedUuid }"
							@click="select(app)">
							{{ app.name || app.slug }}
							<small>{{ app.status }}</small>
						</li>
					</ul>
					<button v-if="applications.length === 0" disabled>
						{{ t('openbuilt', 'No virtual apps yet — seed `hello-world` should appear after install.') }}
					</button>
				</aside>
				<section class="openbuilt-editor__pane">
					<header v-if="selected">
						<h2>{{ selected.name || selected.slug }}</h2>
						<small>{{ t('openbuilt', 'Status') }}: {{ selected.status }} · {{ t('openbuilt', 'Version') }}: {{ selected.version }}</small>
					</header>
					<p class="openbuilt-editor__help">
						{{ t('openbuilt', 'Integrator-only editor: edit the raw JSON manifest below. The visual editor lives in a follow-on release (openbuilt-page-editor).') }}
					</p>
					<textarea
						v-model="manifestText"
						class="openbuilt-editor__textarea"
						data-testid="openbuilt-editor-textarea"
						spellcheck="false"
						:placeholder="t('openbuilt', 'Paste or edit the JSON manifest here. See @conduction/nextcloud-vue/src/schemas/app-manifest.schema.json for the canonical schema.')" />
					<div v-if="validationError" class="openbuilt-editor__error">
						{{ t('openbuilt', 'Invalid manifest') }}: {{ validationError }}
					</div>
					<div class="openbuilt-editor__actions">
						<button :disabled="!selected || saving" data-testid="openbuilt-editor-save" @click="save">
							{{ saving ? t('openbuilt', 'Saving…') : t('openbuilt', 'Save') }}
						</button>
						<a v-if="selected && selected.status === 'published'" :href="builderUrl">
							{{ t('openbuilt', 'Open virtual app') }}
						</a>
					</div>
				</section>
			</div>
		</NcAppContent>
	</div>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { validateManifest } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'

export default {
	name: 'ApplicationEditor',
	components: {
		NcAppContent,
	},
	data() {
		return {
			applications: [],
			selectedUuid: null,
			manifestText: '',
			validationError: '',
			saving: false,
		}
	},
	computed: {
		selected() {
			return this.applications.find(a => (a.uuid || a.id) === this.selectedUuid) || null
		},
		builderUrl() {
			if (!this.selected) {
				return ''
			}
			return generateUrl(`/apps/openbuilt/builder/${this.selected.slug}`)
		},
	},
	async mounted() {
		await this.refresh()
		if (this.applications.length > 0) {
			this.select(this.applications[0])
		}
	},
	methods: {
		async refresh() {
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuilt/application')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				this.applications = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
			} catch (e) {
				this.applications = []
				this.validationError = `Failed to load applications: ${e.message || e}`
			}
		},
		select(app) {
			this.selectedUuid = (app.uuid || app.id)
			this.manifestText = JSON.stringify(app.manifest || {}, null, 2)
			this.validationError = ''
		},
		async save() {
			if (!this.selected) {
				return
			}
			this.validationError = ''
			let parsed
			try {
				parsed = JSON.parse(this.manifestText)
			} catch (e) {
				this.validationError = `JSON parse error: ${e.message}`
				return
			}
			const result = validateManifest ? validateManifest(parsed) : { valid: true, errors: [] }
			if (result && result.valid === false) {
				this.validationError = (result.errors || ['unknown']).join('; ')
				return
			}
			this.saving = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}`)
				await axios.put(url, { ...this.selected, manifest: parsed })
				await this.refresh()
				const updated = this.applications.find(a => (a.uuid || a.id) === this.selectedUuid)
				if (updated) {
					this.select(updated)
				}
			} catch (e) {
				this.validationError = `Save failed: ${e.message || e}`
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-editor__layout {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px);
	height: 100%;
}

.openbuilt-editor__list {
	width: 240px;
	flex-shrink: 0;
	overflow-y: auto;
	border-right: 1px solid var(--color-border, #ddd);
}

.openbuilt-editor__list ul {
	list-style: none;
	padding: 0;
	margin: 0;
}

.openbuilt-editor__list li {
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius, 4px);
}

.openbuilt-editor__list li.active {
	background: var(--color-primary-light, #e6f0fa);
}

.openbuilt-editor__pane {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 16px;
}

.openbuilt-editor__textarea {
	flex: 1 1 auto;
	min-height: 400px;
	font-family: monospace;
	font-size: 13px;
	padding: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.openbuilt-editor__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}

.openbuilt-editor__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.openbuilt-editor__help {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}
</style>
