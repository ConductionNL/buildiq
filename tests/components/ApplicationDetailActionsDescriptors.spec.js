/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden spec for the app detail action cluster.
 *
 * Runs the `actionDescriptors` computed over every combination of the gates it
 * reads, and pins which entries appear, in which order, and what each entry's
 * `onSelect` does.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['admin'],
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

globalThis.t = (app, text, vars) => {
	let out = `${app}:${text}`
	Object.entries(vars || {}).forEach(([k, v]) => {
		out = out.replace(`{${k}}`, v)
	})
	return out
}

const FLAGS = ['permissionsOpen', 'copyOpen', 'githubOpen', 'historyOpen', 'exportOpen', 'deleteOpen']
const CALLS = ['onSettingsOpen', 'editVersion', 'promoteVersion', 'openWalkthroughDesigner', 'openAutomations', 'openSaveAsTemplate', 'openSupportEditor']

function context(gates) {
	const log = []
	const ctx = {
		log,
		obAppRole: gates.owner ? 'owner' : 'editor',
		builderUrl: gates.builderUrl ? '/builder/permit' : '',
		openEditForm: gates.editForm ? () => log.push('openEditForm()') : null,
		canEditVersions: gates.canEditVersions,
		canSaveAsTemplate: gates.canSaveAsTemplate,
		saveTemplateLoading: gates.saveTemplateLoading,
		canCopyApp: gates.canCopyApp,
		obApp: gates.obApp,
		openableVersions: [{ slug: 'development' }, { slug: 'production' }],
		promotableVersions: [{ slug: 'development' }, { slug: 'staging' }],
		versionLabel: (v) => `label:${v.slug}`,
		versionUrl: (v) => `url:${v.slug}`,
	}
	FLAGS.forEach((flag) => {
		ctx[flag] = false
	})
	CALLS.forEach((name) => {
		ctx[name] = (...args) => log.push(`${name}(${JSON.stringify(args)})`)
	})
	return ctx
}

function select(ctx, entry) {
	ctx.log.length = 0
	FLAGS.forEach((flag) => {
		ctx[flag] = false
	})
	entry.onSelect()
	return [...ctx.log, ...FLAGS.filter((flag) => ctx[flag]).map((flag) => `${flag}=true`)]
}

function describeEntry(ctx, entry) {
	const out = {}
	Object.keys(entry).sort().forEach((key) => {
		if (key === 'onSelect') {
			out.onSelect = select(ctx, entry)
		} else if (key === 'children') {
			out.children = entry.children.map((child) => describeEntry(ctx, child))
		} else {
			out[key] = entry[key]
		}
	})
	return out
}

const BOOLEAN_GATES = ['owner', 'builderUrl', 'editForm', 'canEditVersions', 'canSaveAsTemplate', 'saveTemplateLoading', 'canCopyApp']
const OB_APPS = { none: null, noSlug: { slug: '' }, slug: { slug: 'permit' } }

function allGates() {
	const out = []
	for (let mask = 0; mask < 2 ** BOOLEAN_GATES.length; mask++) {
		Object.entries(OB_APPS).forEach(([obAppName, obApp]) => {
			const gates = { obApp }
			BOOLEAN_GATES.forEach((gate, i) => {
				gates[gate] = (mask & (1 << i)) !== 0
			})
			out.push({ name: `${BOOLEAN_GATES.filter((g) => gates[g]).join('+') || 'none'}|obApp=${obAppName}`, gates })
		})
	}
	return out
}

const compute = (ctx) => ApplicationDetailActions.computed.actionDescriptors.call(ctx)

describe('ApplicationDetailActions actionDescriptors golden', () => {
	it('shows the same entries, in the same order, for every gate combination', async () => {
		const rows = allGates().map(({ name, gates }) => `${name} => ${compute(context(gates)).map((e) => e.id).join(',')}`)
		await expect(rows.join('\n') + '\n').toMatchFileSnapshot('./__golden__/actionDescriptors.ids.txt')
	})

	it('builds each entry the same way when every gate is open', async () => {
		const gates = { obApp: OB_APPS.slug }
		BOOLEAN_GATES.forEach((gate) => {
			gates[gate] = gate !== 'saveTemplateLoading'
		})
		const ctx = context(gates)
		const entries = compute(ctx).map((entry) => describeEntry(ctx, entry))
		await expect(JSON.stringify(entries, null, '\t') + '\n').toMatchFileSnapshot('./__golden__/actionDescriptors.full.json')
	})

	it('labels the template entry while it is being prepared', () => {
		const ctx = context({ owner: true, canSaveAsTemplate: true, saveTemplateLoading: true, obApp: null })
		const entry = compute(ctx).find((e) => e.id === 'app-save-as-template')
		expect(entry.label).toBe('buildiq:Preparing…')
	})
})
