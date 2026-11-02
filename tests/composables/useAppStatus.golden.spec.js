/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `useAppStatus().check()` over every pairing of a webroots
 * map, a probe outcome, an app id and a probe path from pools. The verdict,
 * the requests made and a second, cached check are pinned.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import {
	clearAppStatusCache,
	useAppStatus,
} from '../../src/composables/useAppStatus.js'

const ROOTS = [
	'(no OC)',
	'(no map)',
	{},
	{ files: '/apps/files' },
	{ dossiq: '/custom_apps/dossiq' },
	{ procest: '/custom_apps/procest' },
	{ openconnector: '/custom_apps/openconnector', files: '/apps/files' },
	'(throws)',
]

const PROBES = [
	{ ok: true },
	{ status: 404 },
	{ status: 501 },
	{ status: 403 },
	{ status: 500 },
	{ status: undefined },
	{ plain: 'boom' },
]

const APPS = ['dossiq', 'integriq', 'files']

const PATHS = [undefined, '/apps/custom/probe']

/**
 * Install the webroots map for one case.
 *
 * @param {*} roots One entry of ROOTS.
 *
 * @return {void}
 */
function installRoots(roots) {
	delete globalThis.OC
	if (roots === '(no OC)') {
		return
	}
	if (roots === '(no map)') {
		globalThis.OC = {}
	} else if (roots === '(throws)') {
		globalThis.OC = {
			get appswebroots() {
				throw new Error('no map')
			},
		}
	} else {
		globalThis.OC = { appswebroots: roots }
	}
}

/**
 * A client whose GET resolves or rejects as one probe outcome says.
 *
 * @param {object} probe One entry of PROBES.
 *
 * @return {{get: Function}} The client.
 */
function clientFor(probe) {
	const get = vi.fn()
	if (probe.ok === true) {
		get.mockResolvedValue({ data: {} })
	} else if ('plain' in probe) {
		get.mockRejectedValue(probe.plain)
	} else {
		get.mockRejectedValue(
			Object.assign(new Error('failed'), {
				response: { status: probe.status },
			}),
		)
	}
	return { get }
}

describe('useAppStatus golden', () => {
	afterEach(() => {
		delete globalThis.OC
	})

	it('pins the verdict of every webroots map and probe outcome', async () => {
		const lines = []
		for (const roots of ROOTS) {
			for (const probe of PROBES) {
				for (const appId of APPS) {
					for (const probePath of PATHS) {
						clearAppStatusCache()
						installRoots(roots)
						const client = clientFor(probe)
						const status = useAppStatus(appId, { client, probePath })
						const first = await status.check()
						const second = await useAppStatus(appId, {
							client,
							probePath,
						}).check()
						lines.push(
							`${JSON.stringify(roots)} ${JSON.stringify(probe)} ${appId} ${String(probePath)} => ${JSON.stringify(
								{
									first,
									second,
									available: status.available.value,
									checked: status.checked.value,
									calls: client.get.mock.calls,
								},
							)}`,
						)
					}
				}
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
