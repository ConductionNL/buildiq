// SPDX-License-Identifier: EUPL-1.2
/**
 * Column helpers shared by the "Grid" page editor and the runtime
 * `record-grid` page.
 *
 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t04
 */

/**
 * The property key of a column entry, which is either the string shorthand
 * or `{ key, label }` (the shape ColumnBuilder authors).
 *
 * @param {string|{key?: string}} column - one entry of `columns`.
 * @return {string} the key, or '' for an unusable entry.
 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t01
 */
export function columnKey(column) {
	if (typeof column === 'string') {
		return column
	}
	return (column && typeof column.key === 'string' && column.key) || ''
}
