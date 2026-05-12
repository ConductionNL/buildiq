<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('openbuilt', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('openbuilt', 'Schemas')"
				:to="{ name: 'SchemaDesignerList', params: { slug: defaultAppSlug } }">
				<template #icon>
					<DatabaseIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('openbuilt', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('openbuilt', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DatabaseIcon from 'vue-material-design-icons/Database.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

// Default virtual-app slug for the menu's Schemas entry. The seeded
// hello-world app is the canonical example per bootstrap-openbuilt
// REQ-OBR-004. Once chain spec #1's BuilderHost lands the per-app
// secondary nav, this becomes a dynamic slug (REQ-OBR-007).
const DEFAULT_APP_SLUG = 'hello-world'

export default {
	name: 'MainMenu',
	components: {
		BookOpenVariantOutline,
		CogIcon,
		DatabaseIcon,
		HomeIcon,
		NcAppNavigation,
		NcAppNavigationItem,
	},
	data() {
		return {
			defaultAppSlug: DEFAULT_APP_SLUG,
		}
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
