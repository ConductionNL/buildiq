<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	The page designer's error log (board BqPaginaOntwerperFouten): the live
	preview's failed requests, render errors, unknown components and binding
	problems, each counted once. It lives in this browser tab only.
-->
<template>
	<section class="ob-debug-log" data-testid="debug-log">
		<header class="ob-debug-log__header">
			<h4>
				{{ t('buildiq', 'Error log') }}
				<span v-if="entries.length" class="ob-debug-log__count">{{ entries.length }}</span>
			</h4>
			<div class="ob-debug-log__tools">
				<label class="ob-debug-log__filter">
					<span class="hidden-visually">{{ t('buildiq', 'Show') }}</span>
					<select v-model="filter" data-testid="debug-log-filter">
						<option v-for="option in filterOptions" :key="option.value" :value="option.value">
							{{ option.label }}
						</option>
					</select>
				</label>
				<NcButton variant="tertiary" data-testid="debug-log-reload" @click="$emit('reload')">
					{{ t('buildiq', 'Reload preview') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="!entries.length" data-testid="debug-log-clear" @click="$emit('clear')">
					{{ t('buildiq', 'Clear') }}
				</NcButton>
			</div>
		</header>
		<p class="ob-debug-log__hint">
			{{ t('buildiq', 'Failed data requests and broken bindings of the preview. They stay in this browser.') }}
		</p>

		<p v-if="!shown.length" class="ob-debug-log__hint" data-testid="debug-log-empty">
			{{ t('buildiq', 'No problems in the preview.') }}
		</p>
		<table v-else class="ob-debug-log__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('buildiq', 'Page') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'What went wrong') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Request') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'When') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<template v-for="entry in shown" :key="entry.key">
					<tr class="ob-debug-log__row" data-testid="debug-log-row">
						<td>
							<button
								type="button"
								class="ob-debug-log__toggle"
								:aria-expanded="open === entry.key ? 'true' : 'false'"
								@click="toggle(entry.key)">
								{{ pageLabel(entry.pageId) }}
							</button>
						</td>
						<td>{{ describe(entry) }}</td>
						<td class="ob-debug-log__request">
							{{ entry.request || '' }}
						</td>
						<td>{{ when(entry) }}</td>
					</tr>
					<tr v-if="open === entry.key" class="ob-debug-log__detail">
						<td colspan="4">
							<pre>{{ detail(entry) }}</pre>
							<NcButton v-if="entry.pageId" variant="secondary" @click="$emit('selectPage', entry.pageId)">
								{{ t('buildiq', 'Go to the page') }}
							</NcButton>
							<NcButton variant="tertiary" @click="copy(entry)">
								{{ t('buildiq', 'Copy') }}
							</NcButton>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'DebugLogPanel',

	components: { NcButton },

	props: {
		/**
		 * The log's entries, newest first, from useDebugLog.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 */
		entries: { type: Array, default: () => [] },

		/**
		 * Page titles by page id.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 */
		pageTitles: { type: Object, default: () => ({}) },
	},

	emits: ['clear', 'reload', 'selectPage'],

	data() {
		return {
			filter: 'all',
			open: null,
		}
	},

	computed: {
		/**
		 * The filter choices.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @return {Array<{value: string, label: string}>} Options.
		 */
		filterOptions() {
			return [
				{ value: 'all', label: this.t('buildiq', 'Everything') },
				{ value: 'request', label: this.t('buildiq', 'Failed requests') },
				{ value: 'binding', label: this.t('buildiq', 'Broken bindings') },
				{ value: 'error', label: this.t('buildiq', 'Render errors') },
			]
		},

		/**
		 * The entries the filter keeps.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @return {Array<object>} Entries.
		 */
		shown() {
			if (this.filter === 'all') {
				return this.entries
			}
			if (this.filter === 'error') {
				return this.entries.filter((e) => e.kind === 'render-error' || e.kind === 'unknown-component')
			}
			return this.entries.filter((e) => e.kind === this.filter)
		},
	},

	methods: {
		/**
		 * Open or close an entry's detail.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @param {string} key The entry key.
		 * @return {void}
		 */
		toggle(key) {
			this.open = this.open === key ? null : key
		},

		/**
		 * A page's title, or its id.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @param {string} pageId The page id.
		 * @return {string} The label.
		 */
		pageLabel(pageId) {
			return this.pageTitles[pageId] || pageId || this.t('buildiq', 'Unknown page')
		},

		/**
		 * What went wrong, in a sentence.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-log-names-binding-problems-req-bqdm-002
		 * @param {object} entry A log entry.
		 * @return {string} The sentence.
		 */
		describe(entry) {
			if (entry.kind === 'request') {
				return entry.status === 0
					? this.t('buildiq', 'The request did not complete')
					: this.t('buildiq', 'The server answered {status}', { status: entry.status })
			}
			if (entry.kind === 'binding') {
				if (entry.problem === 'missing-schema') {
					return this.t('buildiq', 'Schema {schema} does not exist', { schema: entry.schema })
				}
				if (entry.problem === 'missing-register') {
					return this.t('buildiq', 'Register {register} does not exist', { register: entry.register })
				}
				return this.t('buildiq', '{property} is not a field of schema {schema}', { property: entry.property, schema: entry.schema })
			}
			if (entry.kind === 'unknown-component') {
				return this.t('buildiq', 'Unknown component {name}', { name: entry.component })
			}
			return this.t('buildiq', '{component} failed to draw: {message}', { component: entry.component, message: entry.what })
		},

		/**
		 * When it last happened and how often.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @param {object} entry A log entry.
		 * @return {string} For example "10:41, 12 times".
		 */
		when(entry) {
			const time = new Date(entry.lastAt).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
			return entry.count > 1 ? this.t('buildiq', '{time}, {count} times', { time, count: entry.count }) : time
		},

		/**
		 * The entry's detail as text.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @param {object} entry A log entry.
		 * @return {string} The detail.
		 */
		detail(entry) {
			const lines = [this.describe(entry)]
			if (entry.request) {
				lines.push(`${entry.request}  ${entry.status || '-'}  ${entry.durationMs} ms`)
			}
			if (entry.where) {
				lines.push(this.t('buildiq', 'Declared in: {where}', { where: entry.where }))
			}
			return lines.join('\n')
		},

		/**
		 * Copy the entry's detail to the clipboard.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
		 * @param {object} entry A log entry.
		 * @return {void}
		 */
		copy(entry) {
			navigator.clipboard?.writeText(this.detail(entry)).catch(() => {})
		},
	},
}
</script>

<style scoped>
.ob-debug-log {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
}

.ob-debug-log__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.ob-debug-log__header h4 {
	margin: 0;
}

.ob-debug-log__count {
	margin-inline-start: var(--default-grid-baseline, 4px);
	padding: 0 calc(var(--default-grid-baseline, 4px) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-error);
	color: var(--color-error-text, #fff);
	font-size: 0.85em;
}

.ob-debug-log__tools {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
}

.ob-debug-log__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ob-debug-log__table {
	width: 100%;
	border-collapse: collapse;
}

.ob-debug-log__table th,
.ob-debug-log__table td {
	text-align: start;
	vertical-align: top;
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2) var(--default-grid-baseline, 4px) 0;
	border-bottom: 1px solid var(--color-border);
}

.ob-debug-log__toggle {
	padding: 0;
	border: 0;
	background: none;
	color: var(--color-main-text);
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
}

.ob-debug-log__request {
	font-family: var(--font-face-monospace, monospace);
	word-break: break-all;
}

.ob-debug-log__detail pre {
	white-space: pre-wrap;
	margin: 0 0 var(--default-grid-baseline, 4px);
}
</style>
