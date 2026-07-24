<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PermissionGroupField — group-scoped `permission` picker for a single
  - menu item or page (spec `runtime-group-scoped-access` REQ-1/REQ-2).
  -
  - Writes a SINGLE `group:<gid>` string (the shared `@conduction/nextcloud-vue`
  - manifest schema currently types `menu[].permission` / `pages[].permission`
  - as `string`, not `string | string[]` — the server-side filter in
  - ManifestResolverService accepts either shape defensively, but this editor
  - only ever authors the single-string form so `validateManifest()` never
  - rejects an author's edit). Taggable (free-type any group id) rather than a
  - full Nextcloud group-directory listing — same "no full directory listing"
  - choice `AccessEditor.vue` makes for schema-level authorization scopes, so
  - this picker never needs an admin-only "list all groups" endpoint.
  -
  - Emits `update:permission` with either a `group:<gid>` string or `null`
  - (cleared — the entry is then visible to everyone, the default-open case).
  -->
<template>
	<div class="permission-group-field">
		<NcSelect
			:input-label="t('openbuild', 'Visible only to group (optional)')"
			:value="selectedOption"
			:options="options"
			:taggable="true"
			:clearable="true"
			:placeholder="t('openbuild', 'Everyone with app access')"
			label="label"
			track-by="value"
			@input="onChange"
			@tag="onTag" />
		<p class="permission-group-field__hint">
			{{ t('openbuild', 'Hides this entry from members outside the group. This is navigation only — set OpenRegister schema authorization to actually restrict the underlying data.') }}
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'

/**
 * Strip the `group:` prefix for display, if present.
 *
 * @param {string} permission Raw `permission` value (`"group:vets"` or a bare gid).
 * @return {string} The bare group id.
 */
function gidFromPermission(permission) {
	if (typeof permission !== 'string') {
		return ''
	}
	return permission.startsWith('group:') ? permission.slice('group:'.length) : permission
}

export default {
	name: 'PermissionGroupField',
	components: { NcSelect },
	props: {
		/**
		 * The entry's current `permission` value — a `group:<gid>` string,
		 * a bare gid (back-compat), or undefined/null when unset.
		 */
		permission: {
			type: String,
			default: '',
		},
		/**
		 * Group ids already referenced elsewhere in this manifest (other
		 * menu/page `permission` entries), offered as quick-pick options
		 * alongside free-typing a new one.
		 */
		knownGroups: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:permission'],
	computed: {
		/**
		 * Quick-pick options from `knownGroups`, deduplicated.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		options() {
			const seen = new Set()
			const opts = []
			for (const gid of this.knownGroups) {
				if (gid && !seen.has(gid)) {
					seen.add(gid)
					opts.push({ value: gid, label: gid })
				}
			}
			return opts
		},
		/**
		 * The currently-selected option, derived from `permission`.
		 *
		 * @return {{value: string, label: string}|null}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		selectedOption() {
			const gid = gidFromPermission(this.permission)
			return gid ? { value: gid, label: gid } : null
		},
	},
	methods: {
		/**
		 * Handle a picked or cleared option.
		 *
		 * @param {{value: string}|null} option Selected option, or null when cleared.
		 * @return {void}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		onChange(option) {
			if (!option || !option.value) {
				this.$emit('update:permission', null)
				return
			}
			this.$emit('update:permission', `group:${option.value}`)
		},
		/**
		 * Handle a free-typed (taggable) group id.
		 *
		 * @param {string} tag Free-typed group id.
		 * @return {void}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		onTag(tag) {
			if (!tag) {
				return
			}
			this.$emit('update:permission', `group:${tag}`)
		},
	},
}
</script>

<style scoped>
.permission-group-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 220px;
}

.permission-group-field__hint {
	margin: 0;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}
</style>
