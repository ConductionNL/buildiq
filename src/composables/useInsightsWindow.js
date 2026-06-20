// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useInsightsWindow — a process-wide singleton ref holding the currently
// selected insights window (`7d` / `30d` / `90d`).
//
// The app-detail page is now grid-built: the window toggle lives in the page
// header (ApplicationDetailHeader, above the action line) while the KPI /
// activity widgets it drives live in the body dashboard (ApplicationDetailDashboard,
// in CnDetailPage's #before-body slot). Both import this shared ref so the
// toggle in the header re-fetches the KPIs in the body without prop-drilling
// across the CnDetailPage boundary. Mirrors the `?_version=` URL coordination
// used for version selection, but kept in-memory (no URL noise) for the window.

import { ref } from 'vue'

const WINDOW_OPTIONS = ['7d', '30d', '90d']

// Module-level singleton — every importer shares the same reactive ref.
const selectedWindow = ref('7d')

/**
 * Access the shared insights-window ref + the allowed options.
 *
 * @return {{ selectedWindow: import('vue').Ref<string>, windowOptions: string[] }}
 */
export function useInsightsWindow() {
	return { selectedWindow, windowOptions: WINDOW_OPTIONS }
}
