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
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.3
 */

// Runtime widget — renders an OpenConnector-bound data source for a manifest
// page/widget that declares `dataSource.connector` (REQ-OCAS-006).
import ConnectorDataView from './components/runtime/ConnectorDataView.vue'

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
	// Manifest widgetKey "connector-data" for a connector-bound page/widget.
	'connector-data': widget(ConnectorDataView, ['body', 'sidebar']),
}
