/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `smartPaste`: buildiq's "Paste to fill" handler over
 * hermiq's form fill. It sends the pasted text and the allowed field
 * descriptors only, and proposes only values for the fields it asked for.
 *
 * @spec openspec/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), post: vi.fn() } }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), getLanguage: () => 'nl' }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (path) => path }))

const { SMART_PASTE_HANDLER, describeFields, smartPasteAvailable, smartPasteFill, smartPasteHandler } = await import('../../src/services/smartPaste.js')

const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'string', options: null },
	{ key: 'soort', label: 'Soort', type: 'enum', options: ['particulier', 'zakelijk'] },
]

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.post.mockReset()
})

describe('smartPaste', () => {
	it('is registered under buildiq-smart-paste as { available, fill }', () => {
		expect(SMART_PASTE_HANDLER).toBe('buildiq-smart-paste')
		expect(smartPasteHandler.available).toBe(smartPasteAvailable)
		expect(smartPasteHandler.fill).toBe(smartPasteFill)
	})

	it('is available only when hermiq says so, and not when hermiq cannot be asked', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { available: true } })
		expect(await smartPasteAvailable()).toBe(true)
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/hermiq/api/assistant/form-fill/available')

		axiosMock.get.mockResolvedValueOnce({ data: { available: false } })
		expect(await smartPasteAvailable()).toBe(false)

		axiosMock.get.mockRejectedValueOnce(new Error('404'))
		expect(await smartPasteAvailable()).toBe(false)
	})

	it('sends the pasted text and the field descriptors only, and keeps the proposals for asked fields', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: { available: true, values: { naam: 'Jan Jansen', email: 'x@y.nl' }, draft: true, notice: 'Controleer de voorstellen.' } })

		const answer = await smartPasteFill('Met vriendelijke groet, Jan Jansen', FIELDS)

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/hermiq/api/assistant/form-fill', {
			text: 'Met vriendelijke groet, Jan Jansen',
			fields: [
				{ key: 'naam', label: 'Naam', type: 'string', options: [] },
				{ key: 'soort', label: 'Soort', type: 'enum', options: ['particulier', 'zakelijk'] },
			],
			language: 'nl',
		})
		expect(answer).toEqual({ values: { naam: 'Jan Jansen' }, notice: 'Controleer de voorstellen.' })
	})

	it('proposes nothing when hermiq answers unavailable, and asks nothing for empty text', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: { available: false, reason: 'feature-not-enabled' } })
		expect(await smartPasteFill('tekst', FIELDS)).toEqual({ values: {}, notice: '' })

		expect(await smartPasteFill('   ', FIELDS)).toEqual({ values: {}, notice: '' })
		expect(axiosMock.post).toHaveBeenCalledTimes(1)
	})

	it('keeps the descriptors within the limits hermiq takes', () => {
		const many = Array.from({ length: 60 }, (_, i) => ({ key: `f${i}`, label: 'x'.repeat(250), type: 'Number' }))
		const described = describeFields(many)
		expect(described).toHaveLength(50)
		expect(described[0]).toEqual({ key: 'f0', label: 'x'.repeat(200), type: 'number', options: [] })
	})
})
