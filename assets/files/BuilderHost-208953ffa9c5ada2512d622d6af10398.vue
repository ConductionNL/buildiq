<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - BuilderHost mounts a NESTED CnAppRoot for the virtual app addressed by
  - the :slug param. Per design.md Decision 4/5, this preserves the
  - OpenBuilt outer chrome and forwards path segments after the slug to
  - the inner router. The :key="slug" prop forces a clean remount when
  - the user navigates between virtual apps.
  -
  - Loader workaround (design.md Decision 4): until the in-memory
  - useAppManifest overload ships in nextcloud-vue (chain spec #2), we
  - point the library's backend fetch at our per-slug manifest endpoint
  - via options.endpoint. The bundled-manifest argument is a placeholder
  - skeleton; the real manifest arrives from the backend merge.
  -->
<template>
	<div class="openbuilt-builder-host" data-testid="openbuilt-builder-host">
		<CnAppRoot
			:key="slug"
			:app-id="appId"
			:bundled-manifest="placeholderManifest"
			:options="manifestOptions" />
	</div>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

import placeholderManifest from '../manifests/placeholder.json'

export default {
	name: 'BuilderHost',
	components: {
		CnAppRoot,
	},
	computed: {
		slug() {
			return this.$route.params.slug
		},
		appId() {
			return `openbuilt-${this.slug}`
		},
		placeholderManifest() {
			return placeholderManifest
		},
		manifestOptions() {
			return {
				endpoint: generateUrl(`/apps/openbuilt/api/applications/${this.slug}/manifest`),
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-builder-host {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
}
</style>
