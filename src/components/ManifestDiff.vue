<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Side-by-side manifest diff component. Fetches both blobs via the
  - thin-glue diff endpoint (REQ-OBV-005) in a single round-trip,
  - pretty-prints both deterministically (sorted keys, stable indent),
  - and runs the `diff` npm library client-side per design.md
  - Decision 5. Colours use Nextcloud CSS variables only (ADR-010 +
  - NL Design); no hardcoded colour literals.
  -->
<template>
	<div class="manifest-diff">
		<header class="manifest-diff__header">
			<h3>{{ t('buildiq', 'Manifest diff') }}</h3>
			<small class="manifest-diff__pair">
				{{ t('buildiq', 'From') }}: <code>{{ fromLabel }}</code> →
				{{ t('buildiq', 'To') }}: <code>{{ toLabel }}</code>
			</small>
		</header>
		<p v-if="loading" class="manifest-diff__loading">
			{{ t('buildiq', 'Loading diff…') }}
		</p>
		<p v-else-if="error" class="manifest-diff__error">
			{{ error }}
		</p>
		<p v-else-if="!hasAnyContent" class="manifest-diff__empty">
			{{ t('buildiq', 'Nothing to diff — publish the app first.') }}
		</p>
		<pre v-else class="manifest-diff__pane"><span
			v-for="(part, idx) in diffParts"
			:key="idx"
		:class="partClass(part)">{{ part.value }}</span></pre>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { diffLines } from 'diff'

export default {
	name: 'ManifestDiff',
	props: {
		slug: {
			type: String,
			default: '',
		},

		from: {
			type: String,
			default: 'draft',
		},

		to: {
			type: String,
			default: '',
		},

		/**
		 * Static mode (spec ai-copilot REQ-OBAIC-003/007): when either of
		 * `fromManifest`/`toManifest` is provided the component diffs those
		 * blobs directly instead of fetching `slug`'s stored versions —
		 * used by the copilot's proposal card to preview a not-yet-saved
		 * predicted manifest.
		 */
		fromManifest: {
			type: Object,
			default: null,
		},

		toManifest: {
			type: Object,
			default: null,
		},

		/** Label shown for `from` in static mode (ignored otherwise). */
		fromLabelText: {
			type: String,
			default: '',
		},

		/** Label shown for `to` in static mode (ignored otherwise). */
		toLabelText: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			fromBlob: null,
			toBlob: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Static mode (spec ai-copilot REQ-OBAIC-003/007): diff two in-memory
		 * manifest blobs instead of fetching stored versions by slug.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		isStaticMode() {
			return this.fromManifest !== null || this.toManifest !== null
		},

		/**
		 * Observed behaviour of `fromLabel` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		fromLabel() {
			if (this.isStaticMode) {
				return this.fromLabelText || t('buildiq', 'Current')
			}
			return this.from === 'draft'
				? t('buildiq', 'Current draft')
				: this.from.slice(0, 8) + '…'
		},

		/**
		 * Observed behaviour of `toLabel` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		toLabel() {
			if (this.isStaticMode) {
				return this.toLabelText || t('buildiq', 'Predicted')
			}
			return this.to === 'draft'
				? t('buildiq', 'Current draft')
				: this.to
					? this.to.slice(0, 8) + '…'
					: '—'
		},

		hasAnyContent() {
			if (this.isStaticMode) {
				return this.fromManifest !== null || this.toManifest !== null
			}
			return this.fromBlob !== null || this.toBlob !== null
		},

		/**
		 * Observed behaviour of `diffParts` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		diffParts() {
			const fromSource = this.isStaticMode
				? this.fromManifest
				: this.fromBlob?.manifest
			const toSource = this.isStaticMode
				? this.toManifest
				: this.toBlob?.manifest
			const fromText = this.prettyManifest(fromSource)
			const toText = this.prettyManifest(toSource)
			if (!fromText && !toText) {
				return []
			}
			return diffLines(fromText, toText)
		},
	},

	watch: {
		/**
		 * Observed behaviour of `from` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		from() {
			this.fetch()
		},

		/**
		 * Observed behaviour of `to` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		to() {
			this.fetch()
		},

		/**
		 * Observed behaviour of `slug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		slug() {
			this.fetch()
		},
	},

	/**
	 * Observed behaviour of `mounted` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
	 */
	mounted() {
		if (this.isStaticMode) {
			return
		}
		if (this.slug && this.to) {
			this.fetch()
		}
	},

	methods: {
		/**
		 * Observed behaviour of `fetch` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		async fetch() {
			if (this.isStaticMode || !this.slug || !this.to) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${this.slug}/versions/diff`,
				)
				const { data } = await axios.get(url, {
					params: { from: this.from, to: this.to },
				})
				this.fromBlob = data?.from || null
				this.toBlob = data?.to || null
			} catch (e) {
				this.fromBlob = null
				this.toBlob = null
				this.error = `Diff failed: ${e.message || e}`
			} finally {
				this.loading = false
			}
		},

		/**
		 * Observed behaviour of `prettyManifest` (retrofit annotation).
		 *
		 * @param {object|null|undefined} value - One side's manifest blob (the `from`
		 *   or `to` version). `null`/`undefined` — a version with no manifest yet —
		 *   renders as an empty side rather than the string `"null"`.
		 * @return {string} The manifest as 2-space-indented JSON with every object's
		 *   keys sorted, so the line diff reflects real content changes and not
		 *   serialisation order.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		prettyManifest(value) {
			if (value === null || value === undefined) {
				return ''
			}
			return JSON.stringify(value, this.sortReplacer.bind(this), 2)
		},

		/**
		 * Observed behaviour of `sortReplacer` (retrofit annotation).
		 *
		 * `JSON.stringify` replacer that makes serialisation key-order-independent.
		 *
		 * @param {string} _key - The property name being serialised. Unused: the
		 *   rewrite depends only on the value's shape, never on where it sits.
		 * @param {*} val - The value being serialised. Plain objects are returned with
		 *   their keys sorted; arrays (order is meaningful in a manifest) and
		 *   primitives pass through untouched.
		 * @return {*} The value to serialise in place of `val`.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		sortReplacer(_key, val) {
			if (val && typeof val === 'object' && !Array.isArray(val)) {
				const sorted = {}
				for (const k of Object.keys(val).sort()) {
					sorted[k] = val[k]
				}
				return sorted
			}
			return val
		},

		/**
		 * Observed behaviour of `partClass` (retrofit annotation).
		 *
		 * @param {{value: string, added?: boolean, removed?: boolean, count?: number}} part - One
		 *   hunk from jsdiff's `diffLines()` output. `added`/`removed` are `undefined`
		 *   (not `false`) on an unchanged hunk, and are never both set.
		 * @return {string} The `manifest-diff__part` class list for that hunk, with the
		 *   `--added` / `--removed` / `--unchanged` modifier that colours it.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		partClass(part) {
			if (part.added) {
				return 'manifest-diff__part manifest-diff__part--added'
			}
			if (part.removed) {
				return 'manifest-diff__part manifest-diff__part--removed'
			}
			return 'manifest-diff__part manifest-diff__part--unchanged'
		},
	},
}
</script>

<style scoped>
.manifest-diff {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.manifest-diff__header h3 {
	margin: 0;
	font-size: 15px;
}

.manifest-diff__pair {
	color: var(--color-text-maxcontrast, #888);
	font-size: 12px;
}

.manifest-diff__loading,
.manifest-diff__empty,
.manifest-diff__error {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.manifest-diff__error {
	color: var(--color-error, #d63f3f);
}

.manifest-diff__pane {
	font-family: monospace;
	font-size: 12px;
	background: var(--color-background-dark, #f5f5f5);
	padding: 8px;
	border-radius: var(--border-radius, 4px);
	overflow-x: auto;
	white-space: pre;
	max-height: 480px;
	overflow-y: auto;
}

.manifest-diff__part--added {
	background: var(--color-success-default-background, rgba(70, 186, 97, 0.18));
	color: var(--color-success-text, #2d8a3e);
	display: inline-block;
	width: 100%;
}

.manifest-diff__part--removed {
	background: var(--color-error-default-background, rgba(214, 63, 63, 0.18));
	color: var(--color-error-text, #b32d2d);
	display: inline-block;
	width: 100%;
	text-decoration: line-through;
}

.manifest-diff__part--unchanged {
	color: var(--color-main-text, #222);
}
</style>
