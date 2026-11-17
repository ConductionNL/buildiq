/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shared lists the schema designer's choice field offers
 * (data-field-types-and-choice-lists design D2).
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

import axios from '@nextcloud/axios'
import { labelText, listConceptSchemes, listSchemeOptions } from '../../src/services/conceptSchemes.js'

describe('conceptSchemes', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('lists the vocabulary register\'s schemes by title, keyed by slug', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ '@self': { slug: 'gemeenten' }, title: { nl: 'Gemeenten', en: 'Municipalities' }, uri: 'https://example.org/gemeenten' },
					{ '@self': { slug: 'beroepen' }, title: 'Beroepen', uri: 'https://example.org/beroepen' },
					{ '@self': {}, title: 'No slug', uri: 'u' },
				],
			},
		})

		expect(await listConceptSchemes()).toEqual([
			{ value: 'beroepen', label: 'Beroepen', uri: 'https://example.org/beroepen' },
			{ value: 'gemeenten', label: 'Gemeenten', uri: 'https://example.org/gemeenten' },
		])
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/vocabulary/conceptScheme',
			{ params: { _limit: 500 } },
		)
	})

	it('lists one scheme\'s options by notation and preferred label', async () => {
		axios.get.mockResolvedValue({
			data: { results: [{ notation: '0363', prefLabel: { nl: 'Amsterdam' } }, { uri: 'u:x', prefLabel: '' }] },
		})

		expect(await listSchemeOptions('https://example.org/gemeenten')).toEqual([
			{ value: '0363', label: 'Amsterdam' },
			{ value: 'u:x', label: 'u:x' },
		])
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/vocabulary/concepts',
			{ params: { scheme: 'https://example.org/gemeenten', _limit: 100 } },
		)
	})

	it('reads a label as a string or a language map, Dutch first', () => {
		expect(labelText('Plain')).toBe('Plain')
		expect(labelText({ en: 'English', nl: 'Nederlands' })).toBe('Nederlands')
		expect(labelText({ de: 'Deutsch' })).toBe('Deutsch')
		expect(labelText(null)).toBe('')
	})
})
