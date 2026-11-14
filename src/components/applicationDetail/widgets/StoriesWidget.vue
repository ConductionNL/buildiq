<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	StoriesWidget — the user stories an app keeps from the copilot plan it was
	created from (ai-copilot-documents-and-code-help D7, REQ-BQCH-007): one
	sentence per story, with the schemas and pages the plan built for it, so a
	colleague can read why a page exists. Display only; the stories live on
	the Application's `stories` property.
-->
<template>
	<div class="ob-stories-widget" data-testid="stories-widget">
		<header class="ob-stories-widget__header">
			<h3 class="ob-stories-widget__title">
				{{ t('buildiq', 'User stories') }}
			</h3>
		</header>
		<ul v-if="rows.length > 0" class="ob-stories-widget__list">
			<li
				v-for="(row, index) in rows"
				:key="row.key + '-' + index"
				class="ob-stories-widget__story"
				data-testid="stories-widget-story">
				<p class="ob-stories-widget__sentence">
					{{ row.sentence }}
				</p>
				<ul v-if="row.servedBy.length > 0" class="ob-stories-widget__served-by">
					<li v-for="(ref, refIndex) in row.servedBy" :key="ref.kind + ':' + ref.id + '-' + refIndex">
						<span class="ob-stories-widget__kind">{{ ref.kindLabel }}</span>
						{{ ref.title }}
					</li>
				</ul>
			</li>
		</ul>
		<p v-else class="ob-stories-widget__empty">
			{{ t('buildiq', 'This app has no user stories.') }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'StoriesWidget',

	props: {
		/** The Application's `stories`: `{id, role, goal, benefit?, servedBy: [{kind, id, title}]}`. */
		stories: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * The well-formed stories as display rows.
		 *
		 * @return {Array<{key: string, sentence: string, servedBy: Array<object>}>}
		 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-copilot/spec.md#requirement-the-created-app-keeps-its-stories-req-bqch-007
		 */
		rows() {
			return (Array.isArray(this.stories) ? this.stories : [])
				.filter((story) => story && story.role && story.goal)
				.map((story) => ({
					key: String(story.id || story.goal),
					sentence: this.sentence(story),
					servedBy: (Array.isArray(story.servedBy) ? story.servedBy : [])
						.filter((ref) => ref && (ref.kind === 'page' || ref.kind === 'schema') && ref.id)
						.map((ref) => ({
							kind: ref.kind,
							id: ref.id,
							title: ref.title || ref.id,
							kindLabel: ref.kind === 'page' ? t('buildiq', 'Page') : t('buildiq', 'Schema'),
						})),
				}))
		},
	},

	methods: {
		/**
		 * One story as a sentence.
		 *
		 * @param {{role: string, goal: string, benefit?: string}} story The story.
		 * @return {string}
		 */
		sentence(story) {
			if (story.benefit) {
				return t('buildiq', 'As {role} I want to {goal}, so that {benefit}', { role: story.role, goal: story.goal, benefit: story.benefit })
			}
			return t('buildiq', 'As {role} I want to {goal}', { role: story.role, goal: story.goal })
		},
	},
}
</script>

<style scoped>
.ob-stories-widget {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
}

.ob-stories-widget__header {
	margin-bottom: 8px;
}

.ob-stories-widget__title {
	font-size: 15px;
	font-weight: 600;
	margin: 0;
}

.ob-stories-widget__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.ob-stories-widget__sentence {
	margin: 0;
	font-weight: 500;
}

.ob-stories-widget__served-by {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	list-style: none;
	margin: 4px 0 0;
	padding: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.ob-stories-widget__kind {
	font-weight: 600;
}

.ob-stories-widget__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
