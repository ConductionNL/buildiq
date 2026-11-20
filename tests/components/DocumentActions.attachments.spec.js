/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning DocumentActions.vue's `effectiveAttachments`: a
 * non-empty prop wins, otherwise the injected manifest's
 * `runtime.documents[]`, otherwise nothing.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DocumentActions from '../../src/components/runtime/DocumentActions.vue'

const docs = [{ id: 'm', schema: 's', templateId: 't', templateName: 'M', label: 'M' }]

function attachmentsOf(attachments, cnManifest) {
	return shallowMount(DocumentActions, {
		propsData: { object: {}, attachments, docudeskAvailable: true },
		global: { provide: { cnManifest } },
	}).vm.effectiveAttachments
}

describe('DocumentActions effectiveAttachments', () => {
	it.each([
		['a non-empty prop', [{ id: 'p' }], { runtime: { documents: docs } }, [{ id: 'p' }]],
		['an empty prop', [], { runtime: { documents: docs } }, docs],
		['no manifest', [], null, []],
		['no runtime', [], {}, []],
		['no documents', [], { runtime: {} }, []],
		['non-list documents', [], { runtime: { documents: { a: 1 } } }, []],
	])('with %s', (label, attachments, manifest, expected) => {
		expect(attachmentsOf(attachments, manifest)).toEqual(expected)
	})
})
