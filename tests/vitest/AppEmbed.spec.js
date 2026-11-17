/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Embedding a built app in another site (apps-embed-in-other-sites): the
 * origin check, the snippet builder, the runtime's embed-mode shell slots,
 * the no-session sign-in notice, and the settings section that emits only
 * savable sites.
 *
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn(async () => ({ data: { pages: [{ title: 'Book a room', route: '/book' }, { title: 'Detail', route: '/item/:id' }] } }))
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => axiosGet(...args) } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => `/index.php${path}`,
}))

const {
	embedSlots,
	embedSnippet,
	embedUrl,
	needsSignIn,
	normaliseEmbed,
	originProblem,
	signInNotice,
} = await import('../../src/utils/appEmbed.js')
const AppEmbedSettings = (await import('../../src/components/AppEmbedSettings.vue')).default

describe('embed origins', () => {
	it('accepts an https site, with a port', () => {
		expect(originProblem('https://intranet.example.nl')).toBe('')
		expect(originProblem(' https://intranet.example.nl:8443 ')).toBe('')
	})

	it('refuses a wildcard, telling the maker to list each site', () => {
		expect(originProblem('https://*.example.nl')).toBe('A wildcard is not allowed: list each site separately.')
	})

	it('refuses http, a path and a bad address, each with its reason', () => {
		expect(originProblem('http://intranet.example.nl')).toBe('A site must start with https://.')
		expect(originProblem('https://intranet.example.nl/portal')).toBe('A site is a scheme and a host only, without a path.')
		expect(originProblem('https://user@intranet.example.nl')).toBe('This is not a valid site address.')
	})

	it('reads a stored setting as off with no sites unless set', () => {
		expect(normaliseEmbed(null)).toEqual({ enabled: false, origins: [] })
		expect(normaliseEmbed({ enabled: 'yes', origins: ['https://a.nl', 3] })).toEqual({ enabled: false, origins: ['https://a.nl'] })
		expect(normaliseEmbed({ enabled: true, origins: ['https://a.nl'] })).toEqual({ enabled: true, origins: ['https://a.nl'] })
	})
})

describe('embed snippet', () => {
	it('frames the whole app in embed mode', () => {
		expect(embedUrl({ origin: 'https://cloud.example.nl/', slug: 'room-bookings' }))
			.toBe('https://cloud.example.nl/index.php/apps/buildiq/builder/room-bookings?embed=1')
	})

	it('frames one page by its route, with a title for screen readers', () => {
		expect(embedSnippet({ origin: 'https://cloud.example.nl', slug: 'room-bookings', title: 'Room bookings – "Book" <a room>', pageRoute: '/book' }))
			.toBe('<iframe src="https://cloud.example.nl/index.php/apps/buildiq/builder/room-bookings/book?embed=1" title="Room bookings – &quot;Book&quot; &lt;a room&gt;" width="100%" height="600" style="border: 0"></iframe>')
	})
})

describe('embedded runtime', () => {
	it('drops the app navigation in embed mode and leaves the shell as it is otherwise', () => {
		const menu = () => 'nav'
		const slots = { menu, footer: () => 'f' }

		expect(embedSlots(false, slots)).toBe(slots)
		const embedded = embedSlots(true, slots)
		expect(embedded.menu()).toBeNull()
		expect(embedded.footer()).toBe('f')
		expect(embedSlots(true, {}).menu()).toBeNull()
	})

	it('asks to sign in only when an embedded app lost its session', () => {
		expect(needsSignIn(true, { response: { status: 401 } })).toBe(true)
		expect(needsSignIn(true, { response: { status: 403 } })).toBe(true)
		expect(needsSignIn(true, { response: { status: 404 } })).toBe(false)
		expect(needsSignIn(false, { response: { status: 401 } })).toBe(false)
		expect(needsSignIn(true, new Error('network'))).toBe(false)
	})

	it('offers a sign-in link that opens in a new tab, coming back to the app', () => {
		expect(signInNotice('https://cloud.example.nl/apps/buildiq/builder/room-bookings?embed=1')).toEqual({
			text: 'Sign in to use this app',
			link: 'Sign in to Nextcloud (opens in a new tab)',
			href: '/index.php/login?redirect_url=https%3A%2F%2Fcloud.example.nl%2Fapps%2Fbuildiq%2Fbuilder%2Froom-bookings%3Fembed%3D1',
			target: '_blank',
			rel: 'noopener noreferrer',
		})
	})
})

describe('the Embed section', () => {
	/**
	 * Mount the section with stubbed Nextcloud components.
	 *
	 * @param {object} embed The stored setting.
	 * @return {object} The wrapper.
	 */
	function section(embed) {
		return mount(AppEmbedSettings, {
			props: { embed, slug: 'room-bookings', appName: 'Room bookings' },
			global: { stubs: { NcButton: true, NcCheckboxRadioSwitch: true, NcSelect: true, NcTextField: true } },
		})
	}

	it('switches embedding on, keeping the listed sites, and reads no pages while off', () => {
		axiosGet.mockClear()
		const wrapper = section({ enabled: false, origins: ['https://intranet.example.nl'] })
		expect(axiosGet).not.toHaveBeenCalled()

		wrapper.vm.setEnabled(true)

		expect(wrapper.emitted('update:embed')).toEqual([[{ enabled: true, origins: ['https://intranet.example.nl'] }]])
	})

	it('saves a valid site, and holds back a wildcard with its reason', () => {
		const wrapper = section({ enabled: true, origins: [] })

		wrapper.vm.addRow()
		wrapper.vm.updateRow(0, 'https://*.example.nl')
		expect(wrapper.emitted('update:embed')).toBeUndefined()
		expect(wrapper.vm.problems).toEqual(['A wildcard is not allowed: list each site separately.'])

		wrapper.vm.updateRow(0, ' https://intranet.example.nl ')
		expect(wrapper.emitted('update:embed')).toEqual([[{ enabled: true, origins: ['https://intranet.example.nl'] }]])
	})

	it('offers the whole app and each page without a parameter in its route', async () => {
		const wrapper = section({ enabled: true, origins: ['https://intranet.example.nl'] })
		await flushPromises()

		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/buildiq/api/applications/room-bookings/manifest')

		expect(wrapper.vm.pageOptions.map((option) => option.route)).toEqual(['', '/book'])
		expect(wrapper.vm.snippet).toContain('/apps/buildiq/builder/room-bookings?embed=1')
		wrapper.vm.page = wrapper.vm.pageOptions[1]
		expect(wrapper.vm.snippet).toContain('/apps/buildiq/builder/room-bookings/book?embed=1')
		expect(wrapper.vm.snippet).toContain('title="Room bookings – Book a room"')
	})
})
