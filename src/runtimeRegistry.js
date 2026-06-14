// SPDX-License-Identifier: EUPL-1.2
/**
 * Runtime registry — components that BUILT (virtual) apps resolve at runtime,
 * passed as the `registry` prop to the NESTED CnAppRoot in BuilderHost.
 *
 * Kept separate from the shell `registry.js` (which the manifest test guards
 * as "every entry referenced by openbuild's own manifest") because these
 * entries are referenced by VIRTUAL-app manifests, not openbuild's own shell.
 * Self-contained (no import from `registry.js`) to avoid the
 * registry → BuilderHost → runtimeRegistry import cycle.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.3
 */

// Runtime panel — renders the linked Procest case for the current object on a
// detail page (sidebar tab `procest-case-status`).
import ProcestCaseStatusPanel from './components/runtime/ProcestCaseStatusPanel.vue'

// Runtime widget — renders an OpenConnector-bound data source for a manifest
// page/widget that declares `dataSource.connector` (REQ-OCAS-006).
import ConnectorDataView from './components/runtime/ConnectorDataView.vue'

/**
 * Build a slot-override registry entry (CnPageRenderer resolves any `kind`
 * with a `component` via the slot-override path; `kind: "tab"` makes the
 * intent explicit for a sidebar-tab widget).
 *
 * @param {object} component - Vue component options.
 * @return {object} - the registry entry.
 */
function tab(component) {
	return { kind: 'tab', component }
}

/**
 * Build a `kind: "widget"` registry entry (mirrors the shape `registry.js`'s
 * `widget()` helper produces).
 *
 * @param {object} component - Vue component options.
 * @param {string[]} allowedSlots - manifest slots this widget may occupy.
 * @return {object} - the registry entry.
 */
function widget(component, allowedSlots) {
	return {
		kind: 'widget',
		component,
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 1, h: 1 },
		maxSize: { w: 12, h: 8 },
		allowedSlots,
		propsSchema: {},
	}
}

export const runtimeRegistry = {
	// Sidebar-tab widgetKey for a detail page's `sidebarProps.tabs`.
	'procest-case-status': tab(ProcestCaseStatusPanel),
	// Manifest widgetKey "connector-data" for a connector-bound page/widget.
	'connector-data': widget(ConnectorDataView, ['body', 'sidebar']),
}
