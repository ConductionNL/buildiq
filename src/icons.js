// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for openbuild (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import AppsBox from 'vue-material-design-icons/AppsBox.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClipboardText from 'vue-material-design-icons/ClipboardText.vue'
import Flash from 'vue-material-design-icons/Flash.vue'
import History from 'vue-material-design-icons/History.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import PackageVariant from 'vue-material-design-icons/PackageVariant.vue'
import PuzzleOutline from 'vue-material-design-icons/PuzzleOutline.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import RouterNetwork from 'vue-material-design-icons/RouterNetwork.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import Table from 'vue-material-design-icons/Table.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'

export default {
	AppsBox,
	BookOpenVariantOutline,
	CheckCircle,
	ClipboardText,
	Flash,
	History,
	MapMarkerPath,
	MessageTextOutline,
	PackageVariant,
	PuzzleOutline,
	Robot,
	RouterNetwork,
	Sitemap,
	SourceBranch,
	StoreOutline,
	Table,
	ViewDashboardOutline,
	ViewGridOutline,
}
