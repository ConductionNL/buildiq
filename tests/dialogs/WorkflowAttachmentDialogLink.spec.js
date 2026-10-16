/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Create zaakUrl property" is a LINK to the schema designer, not a click
 * handler that navigates. It used to emit up two components to
 * PageDesignerHost, which assigned `window.location.href` — a full reload of
 * the SPA the user was already in, discarding unsaved designer state, and
 * unreachable by middle-click or "open in new tab".
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: { results: [] } }) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const WorkflowAttachmentDialog = (
	await import('../../src/dialogs/WorkflowAttachmentDialog.vue')
).default

const SCHEMAS = [{ slug: 'pet', title: 'Pet', properties: {} }]

function mountDialog({ query = {} } = {}) {
	return mount(WorkflowAttachmentDialog, {
		props: { open: false, schemas: SCHEMAS, procestAvailable: true },
		global: {
			mocks: { $route: { params: { slug: 'petstore' }, query } },
			stubs: {
				NcDialog: { template: '<div><slot /></div>' },
				NcButton: { props: ['to'], template: '<a :href="to"><slot /></a>' },
				NcSelect: { template: '<div />' },
				NcTextField: { template: '<div />' },
			},
		},
	})
}

describe('WorkflowAttachmentDialog — create-link-property', () => {
	it('is null until a schema is picked, so it never renders a link to nowhere', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.createLinkPropertyRoute).toBeNull()
	})

	it('builds an in-app route carrying the schema and the property to add', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ schemaOption: { label: 'Pet', slug: 'pet' } })

		expect(wrapper.vm.createLinkPropertyRoute).toEqual({
			name: 'SchemaDesignerList',
			params: { slug: 'petstore' },
			query: { schema: 'pet', addProperty: 'zaakUrl' },
		})
	})

	it('forwards the active ?_version= so the link stays on the version being edited', async () => {
		const wrapper = mountDialog({ query: { _version: 'staging' } })
		await wrapper.setData({ schemaOption: { label: 'Pet', slug: 'pet' } })

		expect(wrapper.vm.createLinkPropertyRoute.query).toEqual({
			schema: 'pet',
			addProperty: 'zaakUrl',
			_version: 'staging',
		})
	})
})
