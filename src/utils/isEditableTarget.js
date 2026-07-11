// SPDX-License-Identifier: EUPL-1.2
/**
 * isEditableTarget — shared guard for the designers' document-level
 * undo/redo keydown handlers (design.md D4 / REQ-BUR-003).
 *
 * Returns true when the keydown's target (or, failing that, the active
 * element) is a control whose native text-editing undo should win over
 * draft-level undo/redo: `<input>`, `<textarea>`, `<select>`, or any
 * `contenteditable` element. The page designer and schema designer both
 * import this single helper so the editable-field rule can never drift
 * between the two handlers.
 *
 * @module utils/isEditableTarget
 */

const EDITABLE_TAGS = new Set(['input', 'textarea', 'select'])

/**
 * @param {KeyboardEvent} event - the keydown event.
 * @return {boolean} true when the chord should be left to the native
 *   text-field undo instead of being consumed as a draft-level shortcut.
 */
export function isEditableTarget(event) {
	const target = (event && event.target)
		|| (typeof document !== 'undefined' ? document.activeElement : null)
	if (!target || typeof target.tagName !== 'string') {
		return false
	}
	if (EDITABLE_TAGS.has(target.tagName.toLowerCase())) {
		return true
	}
	return !!target.isContentEditable
}
