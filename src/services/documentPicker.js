// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * documentPicker — pick one requirements document from the maker's Files for
 * "Generate with AI" (ai-copilot-documents-and-code-help D6). Only the file id
 * and name leave this module: the server opens the file as the maker, so a
 * file they cannot read is never read.
 */
import { getFilePickerBuilder } from '@nextcloud/dialogs'

/**
 * Open the Nextcloud file picker for one file.
 *
 * @return {Promise<{id: number, name: string}|null>} The picked file, or null when the picker was closed.
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-wizard-reads-a-document-from-files-req-bqch-005
 */
export async function pickRequirementsDocument() {
	let picked = null
	const picker = getFilePickerBuilder(t('buildiq', 'Pick a requirements document'))
		.setMultiSelect(false)
		.allowDirectories(false)
		.addButton({
			label: t('buildiq', 'Add document'),
			variant: 'primary',
			callback: (nodes) => {
				const node = nodes && nodes[0]
				if (node && node.fileid) {
					picked = { id: Number(node.fileid), name: node.basename }
				}
			},
		})
		.build()

	try {
		await picker.pick()
	} catch {
		// Closing the picker rejects with FilePickerClosed: nothing was picked.
		return null
	}
	return picked
}
