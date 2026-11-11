/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: the keys an OpenRegister object's schema and register are known by
 * — de-duplicated, stringified, most authoritative first, blanks dropped.
 */
import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import {
	objectRegisterKeys,
	objectSchemaKeys,
} from '../../src/utils/objectSchemaKeys.js'

describe('objectSchemaKeys', () => {
	it('orders context, object, @self slug and @self id, dropping blanks and repeats', () => {
		expect(
			objectSchemaKeys(
				{ schema: 'meldingen', '@self': { schemaSlug: 'meldingen', schema: 12 } },
				{ schema: '' },
			),
		).toEqual(['meldingen', '12'])
		expect(
			objectSchemaKeys({ schema: 0, '@self': { schema: null } }, ref({ schema: 'ctx' })),
		).toEqual(['ctx', '0'])
		expect(objectSchemaKeys(null)).toEqual([])
		expect(objectSchemaKeys({ '@self': null }, null)).toEqual([])
	})

	it('reads the register the same way', () => {
		expect(
			objectRegisterKeys(
				{ register: 5, '@self': { registerSlug: 'r', register: '5' } },
				{ register: 'r' },
			),
		).toEqual(['r', '5'])
		expect(objectRegisterKeys({}, ref(null))).toEqual([])
	})
})
