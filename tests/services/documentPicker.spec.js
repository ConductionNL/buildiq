/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * documentPicker (ai-copilot-documents-and-code-help REQ-BQCH-005): one file,
 * no folders, and only its id and name come back; a closed picker is null.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const builder = {
	setMultiSelect: vi.fn(() => builder),
	allowDirectories: vi.fn(() => builder),
	addButton: vi.fn((button) => {
		builder.button = button
		return builder
	}),
	build: vi.fn(() => ({ pick: builder.pick })),
	pick: vi.fn(),
}
const getFilePickerBuilder = vi.fn(() => builder)
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: (...a) => getFilePickerBuilder(...a) }))

const { pickRequirementsDocument } = await import('../../src/services/documentPicker.js')

describe('pickRequirementsDocument', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('returns the picked file id and name, for one file and no folders', async () => {
		builder.pick.mockImplementation(async () => {
			await builder.button.callback([{ fileid: '42', basename: 'Eisen tuinvergunning.md', path: '/Eisen tuinvergunning.md' }])
			return '/Eisen tuinvergunning.md'
		})

		await expect(pickRequirementsDocument()).resolves.toEqual({ id: 42, name: 'Eisen tuinvergunning.md' })
		expect(getFilePickerBuilder).toHaveBeenCalledWith('Pick a requirements document')
		expect(builder.setMultiSelect).toHaveBeenCalledWith(false)
		expect(builder.allowDirectories).toHaveBeenCalledWith(false)
		expect(builder.button).toMatchObject({ label: 'Add document', variant: 'primary' })
	})

	it('returns null when the picker is closed', async () => {
		builder.pick.mockRejectedValue(new Error('FilePicker closed'))
		await expect(pickRequirementsDocument()).resolves.toBeNull()
	})

	it('returns null when nothing usable was selected', async () => {
		builder.pick.mockImplementation(async () => {
			await builder.button.callback([])
			return ''
		})
		await expect(pickRequirementsDocument()).resolves.toBeNull()
	})
})
