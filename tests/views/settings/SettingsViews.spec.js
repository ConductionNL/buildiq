/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the admin settings views: what Settings.vue reads from the
 * settings store, the payload it saves (a blank token is left out), what it
 * shows afterwards, and how AdminRoot waits for the stores and re-creates the
 * settings section after a re-import.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const settingsStore = { settings: null, saveSettings: vi.fn() }

vi.mock('../../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => settingsStore,
}))

const initializeStores = vi.fn()

vi.mock('../../../src/store/store.js', () => ({
	initializeStores: (...args) => initializeStores(...args),
}))

const { default: Settings } = await import('../../../src/views/settings/Settings.vue')
const { default: AdminRoot } = await import('../../../src/views/settings/AdminRoot.vue')

/**
 * A bare component context: its data, computed values and bound methods.
 *
 * @param {object} component The component options
 * @return {object} The context
 */
function context(component) {
	const ctx = { ...component.data.call({}) }
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	for (const [name, method] of Object.entries(component.methods || {})) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	settingsStore.settings = null
	settingsStore.saveSettings = vi.fn()
	initializeStores.mockReset()
})

describe('Settings.vue', () => {
	it('starts from the defaults when the store holds no settings', () => {
		const ctx = context(Settings)
		Settings.created.call(ctx)

		expect(ctx.form).toEqual({ register: '', registry_url: '', registry_register: 'buildiq', registry_token: '' })
		expect(ctx.tokenIsSet).toBe(false)
	})

	it('reads the stored settings, never the token itself', () => {
		settingsStore.settings = {
			register: 'apps',
			registry_url: 'https://registry.example',
			registry_register: 'catalogue',
			registry_token_set: 1,
		}
		const ctx = context(Settings)
		Settings.created.call(ctx)

		expect(ctx.form).toEqual({ register: 'apps', registry_url: 'https://registry.example', registry_register: 'catalogue', registry_token: '' })
		expect(ctx.tokenIsSet).toBe(true)
	})

	it('names a different token placeholder once a token is stored', () => {
		const ctx = context(Settings)
		const empty = ctx.tokenPlaceholder
		ctx.tokenIsSet = true

		expect(empty).toBe('Bearer token (optional)')
		expect(ctx.tokenPlaceholder).toBe('Leave blank to keep the current token')
	})

	it('saves without a token when the field is blank, and keeps the stored one', async () => {
		settingsStore.saveSettings.mockResolvedValue({ registry_token_set: false })
		const ctx = context(Settings)
		Object.assign(ctx.form, { register: 'apps', registry_url: 'u', registry_register: 'r', registry_token: '   ' })
		ctx.tokenIsSet = true

		const pending = ctx.save()
		expect([ctx.saving, ctx.successMessage]).toEqual([true, ''])
		await pending

		expect(settingsStore.saveSettings).toHaveBeenCalledWith({ register: 'apps', registry_url: 'u', registry_register: 'r', allow_page_code: false })
		expect([ctx.saving, ctx.successMessage, ctx.tokenIsSet, ctx.form.registry_token])
			.toEqual([false, 'Settings saved successfully', true, ''])
	})

	it('sends a new token and clears the field once saved', async () => {
		settingsStore.saveSettings.mockResolvedValue({ registry_token_set: true })
		const ctx = context(Settings)
		ctx.form.registry_token = 'secret-token'

		await ctx.save()

		expect(settingsStore.saveSettings.mock.calls[0][0].registry_token).toBe('secret-token')
		expect([ctx.tokenIsSet, ctx.form.registry_token]).toEqual([true, ''])
	})

	it('reads "Allow code on pages" as off unless the server says true', () => {
		const off = context(Settings)
		Settings.created.call(off)
		settingsStore.settings = { allow_page_code: 'true' }
		const notABoolean = context(Settings)
		Settings.created.call(notABoolean)
		settingsStore.settings = { allow_page_code: true }
		const on = context(Settings)
		Settings.created.call(on)

		expect([off.allowPageCode, notABoolean.allowPageCode, on.allowPageCode]).toEqual([false, false, true])
	})

	it('sends "Allow code on pages" and shows what the server stored', async () => {
		settingsStore.saveSettings.mockResolvedValue({ allow_page_code: false })
		const ctx = context(Settings)
		ctx.allowPageCode = true

		await ctx.save()

		expect(settingsStore.saveSettings.mock.calls[0][0].allow_page_code).toBe(true)
		expect(ctx.allowPageCode).toBe(false)
	})

	it('reports nothing and keeps the typed token when the save fails', async () => {
		settingsStore.saveSettings.mockResolvedValue(null)
		const ctx = context(Settings)
		ctx.form.registry_token = 'typed'

		await ctx.save()

		expect([ctx.saving, ctx.successMessage, ctx.tokenIsSet, ctx.form.registry_token]).toEqual([false, '', false, 'typed'])
	})
})

describe('AdminRoot.vue', () => {
	it('shows the settings only once the stores are initialised', async () => {
		let release
		initializeStores.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const ctx = context(AdminRoot)

		const pending = AdminRoot.created.call(ctx)
		expect(ctx.storesReady).toBe(false)
		release()
		await pending

		expect(ctx.storesReady).toBe(true)
		expect(initializeStores).toHaveBeenCalledTimes(1)
	})

	it('re-loads the stores and re-creates the settings section after a re-import', async () => {
		initializeStores.mockResolvedValue(undefined)
		const ctx = context(AdminRoot)

		await ctx.onReimported()
		await ctx.onReimported()

		expect(initializeStores).toHaveBeenCalledTimes(2)
		expect(ctx.settingsKey).toBe(2)
	})
})
