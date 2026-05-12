<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	Tier-4 manifest consumer per ADR-024.

	The exported app does NOT scaffold its own navigation, settings shells,
	or page wiring. It mounts CnAppRoot from @conduction/nextcloud-vue and
	hands it the bundled manifest (src/manifest.json, baked in by OpenBuilt's
	PlaceholderResolver at export time). CnAppRoot owns:
	  - the NcContent + NcAppNavigation + NcAppContent skeleton,
	  - the router instance derived from manifest.pages,
	  - the deep-link registration,
	  - the optional NL Design system theme overlay.

	No OpenBuilt runtime dependency. The unzipped tree builds and installs
	standalone — manifest changes ship via this file, not via OR records.

	This file pairs with the chain spec #2 overload of useAppManifest:
	useAppManifest({ manifest }) lets us pass an in-process JS object
	(loaded by main.js) instead of forcing a network round-trip.
-->
<template>
	<CnAppRoot :manifest="manifest" :app-id="appId" />
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import manifest from './manifest.json'

export default {
	name: 'App',
	components: {
		CnAppRoot,
	},
	data() {
		return {
			manifest,
			appId: manifest.id,
		}
	},
}
</script>
