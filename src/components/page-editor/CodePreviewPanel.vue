<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CodePreviewPanel — runs the code components and expressions of the
  - manifest under edit through the sandbox, so a maker sees them work, and
  - sees their errors, before publishing (pages-custom-code D4, D5, T07).
  -
  - Each code component gets its own preview frame (`sandbox="allow-scripts"`,
  - the preview document of SandboxController); expressions go to one evaluator
  - frame. Nothing runs in the designer itself. A change to the code reloads
  - the frames after a short pause, so typing does not run half-written code on
  - every key.
  -->
<template>
	<section
		v-if="components.length > 0 || expressions.length > 0"
		class="code-preview-panel"
		:aria-label="t('buildiq', 'Code preview')">
		<h4 class="code-preview-panel__title">
			{{ t('buildiq', 'Code preview') }}
		</h4>
		<p class="code-preview-panel__hint">
			{{ t('buildiq', 'Code runs here in the same sandbox as in the app, with sample inputs: an empty record and no rows.') }}
		</p>
		<p v-if="disabled" class="code-preview-panel__notice" role="status">
			{{ t('buildiq', 'Code on pages is switched off for this Nextcloud: code components show a placeholder and expressions are skipped.') }}
		</p>

		<div
			v-for="item in components"
			:key="item.key"
			class="code-preview-panel__component"
			data-testid="code-preview-component">
			<span class="code-preview-panel__label">{{ item.label }}</span>
			<iframe
				:key="item.key + ':' + generation"
				class="code-preview-panel__frame"
				sandbox="allow-scripts"
				:src="previewUrl"
				:title="t('buildiq', 'Preview of {name}', { name: item.label })"
				@load="renderInto(item, $event.target)" />
			<ul
				v-if="(errors[item.key] || []).length > 0"
				class="code-preview-panel__errors"
				role="alert">
				<li v-for="(message, index) in errors[item.key]" :key="index">
					{{ message }}
				</li>
			</ul>
		</div>

		<div v-if="expressions.length > 0" class="code-preview-panel__expressions">
			<iframe
				ref="evaluator"
				class="code-preview-panel__evaluator"
				sandbox="allow-scripts"
				aria-hidden="true"
				tabindex="-1"
				:src="evaluatorUrl"
				:title="t('buildiq', 'Expression evaluator')"
				@load="evaluatorLoaded" />
			<p v-if="expressionErrors.length === 0 && evaluated" class="code-preview-panel__ok">
				{{ n('buildiq', '%n expression runs without errors', '%n expressions run without errors', expressions.length) }}
			</p>
			<ul v-if="expressionErrors.length > 0" class="code-preview-panel__errors" role="alert">
				<li v-for="item in expressionErrors" :key="item.key">
					<code>{{ item.expression }}</code>: {{ item.error }}
				</li>
			</ul>
		</div>
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	codeComponentsOf,
	createEvaluatorClient,
	expressionsOf,
	previewInputs,
	sandboxDocumentUrl,
} from '../../services/sandboxFrame.js'

/** How long the code must stay unchanged before the frames reload, in milliseconds. */
export const RERUN_DELAY_MS = 400

export default {
	name: 'CodePreviewPanel',

	props: {
		/** The manifest under edit. */
		manifest: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			// Bumped to reload every preview frame with the current code.
			generation: 0,
			// Errors a preview frame reported, by component key.
			errors: {},
			// The evaluator's last answer, by expression key.
			results: {},
			evaluated: false,
			evaluatorDisabled: false,
			previewUrl: sandboxDocumentUrl('preview'),
			evaluatorUrl: sandboxDocumentUrl('evaluator'),
		}
	},

	computed: {
		/**
		 * The code components of the manifest.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		components() {
			return codeComponentsOf(this.manifest)
		},

		/**
		 * The expressions of the manifest.
		 *
		 * @return {Array<{key: string, expression: string}>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		expressions() {
			return expressionsOf(this.manifest)
		},

		/**
		 * What the code is: a change here reruns it.
		 *
		 * @return {string}
		 */
		codeSignature() {
			return JSON.stringify(this.components.map((item) => [item.key, item.code]))
		},

		/**
		 * What the expressions are: a change here re-evaluates them.
		 *
		 * @return {string}
		 */
		expressionSignature() {
			return JSON.stringify(this.expressions)
		},

		/**
		 * The expressions that failed, with their message.
		 *
		 * @return {Array<{key: string, expression: string, error: string}>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		expressionErrors() {
			return this.expressions
				.filter((item) => this.results[item.key] && this.results[item.key].error !== undefined)
				.map((item) => ({ ...item, error: this.errorText(this.results[item.key].error) }))
		},

		/**
		 * Whether the evaluator answered that code on pages is switched off.
		 * The preview frames then show the server's placeholder themselves.
		 *
		 * @return {boolean}
		 */
		disabled() {
			return this.evaluatorDisabled
		},
	},

	watch: {
		codeSignature() {
			this.schedule('_codeTimer', () => {
				this.errors = {}
				this.generation += 1
			})
		},

		expressionSignature() {
			this.schedule('_expressionTimer', () => this.evaluateAll())
		},
	},

	created() {
		this._frames = new Map()
		this._codeTimer = null
		this._expressionTimer = null
		this._client = createEvaluatorClient(() => this.$refs.evaluator?.contentWindow || null)
	},

	mounted() {
		window.addEventListener('message', this.onFrameMessage)
	},

	beforeUnmount() {
		window.removeEventListener('message', this.onFrameMessage)
		clearTimeout(this._codeTimer)
		clearTimeout(this._expressionTimer)
		this._client.dispose()
	},

	methods: {
		t,
		n,

		/**
		 * Run `run` once the input has been still for RERUN_DELAY_MS.
		 *
		 * @param {string} timer - the instance field holding the timer.
		 * @param {() => void} run - what to run.
		 */
		schedule(timer, run) {
			clearTimeout(this[timer])
			this[timer] = setTimeout(run, RERUN_DELAY_MS)
		},

		/**
		 * The signed-in user, as a code component or expression sees it.
		 *
		 * @return {{id: string, name: string}}
		 */
		currentUser() {
			const user = getCurrentUser()
			return { id: user?.uid || '', name: user?.displayName || '' }
		},

		/**
		 * Post a code component into its freshly loaded preview frame.
		 *
		 * @param {object} item - one entry of `components`.
		 * @param {HTMLIFrameElement} frame - its frame.
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		renderInto(item, frame) {
			const target = frame && frame.contentWindow
			if (!target) {
				return
			}
			this._frames.set(target, item.key)
			target.postMessage({ type: 'render', code: item.code, inputs: previewInputs(item.code, this.currentUser()) }, '*')
		},

		/**
		 * Record an error a preview frame reports. Messages from any other
		 * window, and any other message type, are ignored.
		 *
		 * @param {MessageEvent} event - the message.
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		onFrameMessage(event) {
			const key = this._frames.get(event.source)
			const message = event.data
			if (key === undefined || !message || message.type !== 'error') {
				return
			}
			const list = this.errors[key] || []
			this.errors = { ...this.errors, [key]: [...list, String(message.message || '')] }
		},

		/** Evaluate once the evaluator frame has loaded. */
		evaluatorLoaded() {
			this.evaluateAll()
		},

		/**
		 * Evaluate every expression over an empty sample scope.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t07
		 */
		async evaluateAll() {
			const scope = { row: {}, values: {}, user: this.currentUser(), route: {} }
			const results = await this._client.evaluate(this.expressions, scope)
			this.results = results
			this.evaluatorDisabled = Object.values(results).some((result) => result && result.disabled === true)
			this.evaluated = true
		},

		/**
		 * A readable error: a timeout gets a sentence, anything else is the
		 * evaluator's message.
		 *
		 * @param {string} error - the evaluator's error.
		 * @return {string}
		 */
		errorText(error) {
			if (error === 'timeout') {
				return t('buildiq', 'The expression took too long.')
			}
			return String(error)
		},
	},
}
</script>

<style scoped lang="scss">
.code-preview-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
}

.code-preview-panel__hint,
.code-preview-panel__ok {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.code-preview-panel__notice {
	padding: 6px 8px;
	border-radius: var(--border-radius);
	background: var(--color-warning-hover, var(--color-background-dark));
}

.code-preview-panel__component {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.code-preview-panel__label {
	font-weight: 600;
	font-size: 13px;
}

.code-preview-panel__frame {
	width: 100%;
	min-height: 160px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.code-preview-panel__evaluator {
	position: absolute;
	width: 0;
	height: 0;
	border: 0;
}

.code-preview-panel__errors {
	margin: 0;
	padding-inline-start: 16px;
	color: var(--color-error-text, var(--color-error));
	font-size: 13px;
}
</style>
