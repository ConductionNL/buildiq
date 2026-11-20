// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * ApplicationConnectionsSection — an app's sources and integrations with their
 * health (built-app-connections-page REQ-BIQ-CONN-005, task 3.2: the status
 * labels in English and Dutch).
 */
import { mount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const l10n = vi.hoisted(() => ({ catalogue: {} }))

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key) => (Object.hasOwn(l10n.catalogue, key) ? l10n.catalogue[key] : key),
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => `/index.php${p}`,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import axios from '@nextcloud/axios'
import ApplicationConnectionsSection from '../../../src/components/applicationDetail/ApplicationConnectionsSection.vue'
import { connectionStatusLabel } from '../../../src/services/appConnections.js'

const dutch = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../l10n/nl.json'), 'utf8')).translations
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const stubs = { NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' } }

const listed = {
	healthNeedsIntegriq: false,
	connections: [
		{ kind: 'source', id: 'src-brp', name: 'BRP', status: 'error', statusMessage: '401 Unauthorized', checkedAt: '2026-11-17T08:00:00Z', linkId: 'src-brp' },
		{ kind: 'source', id: 'src-kvk', name: 'KvK', status: null, statusMessage: null, checkedAt: null, linkId: 'src-kvk' },
		{ kind: 'integration', id: 'con-1', name: 'Zaaksysteem', status: 'configured', statusMessage: null, checkedAt: '2026-11-17T09:00:00Z', linkId: 'con-1' },
	],
}

/**
 * Mount the section for the Vergunningen app.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(ApplicationConnectionsSection, { props: { appSlug: 'vergunningen' }, global: { stubs } })
	await flush()
	await flush()
	return wrapper
}

describe('ApplicationConnectionsSection', () => {
	beforeEach(() => {
		l10n.catalogue = {}
		axios.get.mockReset()
	})

	it('REQ-BIQ-CONN-005: lists the sources and the integration with status, message, check time and link', async () => {
		axios.get.mockResolvedValue({ data: listed })
		const wrapper = await mountSection()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/buildiq/api/applications/vergunningen/connections')
		const rows = wrapper.findAll('[data-testid="app-connections-row"]')
		expect(rows).toHaveLength(3)
		expect(rows[0].find('[data-testid="app-connections-status"]').text()).toBe('Error')
		expect(rows[0].find('[data-testid="app-connections-message"]').text()).toBe('401 Unauthorized')
		expect(rows[0].text()).toContain(new Date('2026-11-17T08:00:00Z').toLocaleString())
		expect(rows[1].find('[data-testid="app-connections-status"]').text()).toBe('Not checked')
		expect(rows[1].text()).toContain('—')
		expect(rows[0].find('a').attributes('href')).toBe('/index.php/apps/integriq/sources/src-brp')
		expect(rows[2].find('a').attributes('href')).toBe('/index.php/apps/integriq/connections?app=vergunningen')
		expect(wrapper.find('[data-testid="app-connections-no-integriq"]').exists()).toBe(false)
	})

	it('REQ-BIQ-CONN-005: without integriq it lists the sources and says health needs integriq', async () => {
		axios.get.mockResolvedValue({ data: { healthNeedsIntegriq: true, connections: [{ kind: 'source', id: 'kvk/companies', name: 'kvk/companies', status: null, linkId: null }] } })
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="app-connections-no-integriq"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="app-connections-status"]').text()).toBe('Not checked')
		expect(wrapper.find('[data-testid="app-connections-link"]').exists()).toBe(false)
	})

	it('a refused or failed read says so instead of an empty list', async () => {
		axios.get.mockRejectedValue(Object.assign(new Error('403'), { response: { status: 403 } }))
		const wrapper = await mountSection()

		expect(wrapper.find('[role="alert"]').text()).toBe('The connections could not be loaded.')
		expect(wrapper.find('[data-testid="app-connections-table"]').exists()).toBe(false)
	})

	it('3.2: the eight status labels read in English, and in Dutch from the Dutch catalogue', () => {
		const statuses = ['configured', 'limited', 'unconfigured', 'simulated', 'disabled', 'unavailable', 'error', null]
		const english = ['Configured', 'Limited', 'Not configured', 'Simulated', 'Switched off', 'Not available', 'Error', 'Not checked']
		expect(statuses.map((status) => connectionStatusLabel(status))).toEqual(english)
		expect(connectionStatusLabel('something-new')).toBe('Not checked')

		l10n.catalogue = dutch
		expect(statuses.map((status) => connectionStatusLabel(status))).toEqual([
			'Ingericht', 'Beperkt', 'Niet geconfigureerd', 'Gesimuleerd', 'Uitgeschakeld', 'Niet beschikbaar', 'Fout', 'Niet gecontroleerd',
		])
	})
})
