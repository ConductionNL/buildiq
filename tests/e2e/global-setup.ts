// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright globalSetup — logs in to Nextcloud once and writes the
 * authenticated browser context (cookies + localStorage) to
 * `tests/e2e/.auth/admin.json`. Every spec then reuses that storage
 * state via the project-level `storageState` config, so per-spec
 * `loginAsAdmin` helpers are no longer required.
 *
 * Before this hook existed, specs without an explicit form-login step
 * (applicationCard, builder-host, bootstrap-openbuild, …) landed on
 * `/login` and every locator timed out. Nextcloud's session is cookie-
 * based; basic auth alone doesn't satisfy the SPA's auth check.
 *
 * The hook is no-op when login fails (e.g. brute-force throttle); the
 * resulting empty storage state lets specs surface real errors with a
 * clear "still on /login" snapshot in the report.
 */

import { chromium, request as playwrightRequest, FullConfig } from '@playwright/test'
import { existsSync, mkdirSync } from 'fs'
import { dirname } from 'path'
import { execSync } from 'child_process'

/**
 * Docudesk template fixtures the document-attachment specs attach to.
 * Named after the spec's own scenarios so a failure reads the same as
 * openspec/specs/docudesk-document-templates/spec.md.
 */
export const DOCUDESK_TEMPLATE_NAMES = ['Bevestigingsbrief', 'Besluit'] as const

/**
 * Seed the deterministic `hello-world` virtual-app fixture the e2e specs run
 * against. Production no longer ships a hello-world seed (the SeedHelloWorld
 * repair step was retired with the versioned-model migration), so the suite
 * seeds it itself via the test-only occ command. Override the invocation with
 * OPENBUILD_SEED_CMD when occ is reached differently (e.g. a non-docker CI).
 * Non-fatal: a failure is logged and specs surface the missing fixture.
 */
function seedHelloWorldFixture(): void {
	const cmd = process.env.OPENBUILD_SEED_CMD
		|| 'docker exec -u www-data nextcloud php occ openbuild:seed-hello-world-fixture'
	try {
		const out = execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
		// eslint-disable-next-line no-console
		console.log(`[globalSetup] hello-world fixture: ${out.trim().split('\n').pop()}`)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`[globalSetup] hello-world fixture seed failed (specs needing it will fail): ${(e as Error).message}`)
	}
}

/**
 * Disable Nextcloud's rate-limit protection for the run.
 *
 * `ApplicationCreationController::wizard()` carries
 * `#[UserRateLimit(limit: 10, period: 3600)]`, i.e. TEN app creations per hour
 * per user. The suite creates far more than that — every `ensureApp()` in
 * tests/e2e/support/appFixture.ts goes through the wizard endpoint, on top of
 * createApplicationWizard / virtual-app-crud / build-workflow creating apps of
 * their own. Past the tenth create the endpoint returns 429 and every later
 * spec fails for a reason that has nothing to do with what it asserts. This is
 * exactly why createApplicationWizard passed when run alone and failed in the
 * full suite: an ordering-dependent 429, not a real defect.
 *
 * The limit is correct product behaviour and is deliberately NOT changed; it is
 * turned off for the test instance only, which is Nextcloud's own supported
 * switch for this (`ratelimit.protection.enabled`). Doing it here rather than
 * by hand on a container makes it repo state, so CI and any fresh container get
 * it too — a hand-set container value would make the suite green only on the
 * machine where someone remembered to set it.
 *
 * `occ` is reached the same way the fixture seed reaches it; override with
 * OPENBUILD_RATELIMIT_CMD for a non-docker CI. Non-fatal: on failure the run
 * continues and the 429s simply reappear, with this warning explaining them.
 */
function disableRateLimitProtection(): void {
	// The graceful reload is not optional on a warm container: config.php is
	// opcached, and with the container default `opcache.revalidate_freq=60` the
	// new value can be served stale for up to a minute — long enough for the
	// first specs to 429 anyway. Measured: setting the value alone left the
	// endpoint still returning 429; it only took effect after the reload.
	const cmd = process.env.OPENBUILD_RATELIMIT_CMD
		|| 'docker exec -u www-data nextcloud php occ config:system:set '
		+ 'ratelimit.protection.enabled --value=false --type=boolean '
		+ '&& docker exec nextcloud apache2ctl graceful'
	try {
		execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
		// eslint-disable-next-line no-console
		console.log('[globalSetup] rate-limit protection disabled for this run')
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[globalSetup] could not disable rate-limit protection — expect 429s '
			+ `from the app-creation wizard after 10 creates: ${(e as Error).message}`,
		)
	}
}

/**
 * Configure Docudesk's template register/schema and seed the template fixtures
 * the `docudesk-document-templates` specs attach to.
 *
 * WHY THIS IS HERE AND NOT A ONE-OFF API CALL
 *
 * `GET /apps/docudesk/api/templates` answers
 * `{ results: [], total: 0, notConfigured: true }` until Docudesk's
 * `template_register` / `template_schema` app-config keys point at its own
 * OpenRegister register. On this instance Docudesk's own initializer had set
 * `templateVersion_register`/`templateVersion_schema` but left `template_*`
 * EMPTY, so the template picker was permanently empty and every attach
 * scenario had nothing to pick. Setting those keys by hand on the container
 * would make the suite green only on the machine where someone remembered —
 * the exact failure mode `disableRateLimitProtection()` above exists to avoid.
 * So it is repo state, run on every boot, idempotent.
 *
 * Both steps go through Docudesk's OWN public API (`POST /api/settings`, which
 * allowlists `template_register`/`template_schema`, and `POST /api/templates`)
 * rather than occ or SQL, so this works against any reachable instance,
 * container or not, and never reaches around the app's own validation.
 *
 * Graceful when Docudesk is absent: a 404/501 from the settings probe is a
 * documented capability state (the specs' own probe treats it identically), so
 * seeding is skipped with a log rather than failing the run. Set
 * OPENBUILD_DOCUDESK_SEED=0 to skip deliberately.
 *
 * @param baseURL Absolute base URL of the instance under test.
 * @param user Admin username.
 * @param password Admin password.
 * @return {Promise<void>}
 */
async function seedDocudeskTemplateFixtures(
	baseURL: string,
	user: string,
	password: string,
): Promise<void> {
	if (process.env.OPENBUILD_DOCUDESK_SEED === '0') {
		// eslint-disable-next-line no-console
		console.log('[globalSetup] docudesk seeding skipped (OPENBUILD_DOCUDESK_SEED=0)')
		return
	}
	// Basic auth is sent PREEMPTIVELY rather than via `httpCredentials`: that
	// option only replays the credentials after a 401 challenge, and Nextcloud
	// answers an `OCS-APIRequest` call with a bare 401 and no `WWW-Authenticate`
	// header, so the retry never happens and every request 401s.
	const api = await playwrightRequest.newContext({
		baseURL,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
		},
	})
	try {
		const settingsResp = await api.get('/index.php/apps/docudesk/api/settings')
		if (settingsResp.status() === 404 || settingsResp.status() === 501) {
			// eslint-disable-next-line no-console
			console.log('[globalSetup] docudesk not installed — template fixtures skipped')
			return
		}
		if (settingsResp.ok() === false) {
			// eslint-disable-next-line no-console
			console.warn(`[globalSetup] docudesk settings probe returned ${settingsResp.status()} — template fixtures skipped`)
			return
		}
		const settings = await settingsResp.json()
		const configuration = settings.configuration || {}

		// Resolve Docudesk's own register + `template` schema by SLUG. The
		// settings payload already carries the full register list (with nested
		// schemas), so no second lookup — and slugs are stable across instances
		// where the numeric ids are not.
		if (!configuration.template_register || !configuration.template_schema) {
			const registers = settings.availableRegisters || []
			const register = registers.find((r: Record<string, any>) => r.slug === 'docudesk')
			const schema = (register?.schemas || []).find((s: Record<string, any>) => s.slug === 'template')
			if (!register || !schema) {
				// eslint-disable-next-line no-console
				console.warn('[globalSetup] docudesk register/`template` schema not found — template fixtures skipped')
				return
			}
			const written = await api.post('/index.php/apps/docudesk/api/settings', {
				data: {
					template_register: String(register.id),
					template_schema: String(schema.id),
					template_source: 'openregister',
				},
			})
			// eslint-disable-next-line no-console
			console.log(
				`[globalSetup] docudesk template register configured (register=${register.id}, schema=${schema.id}, `
				+ `status=${written.status()})`,
			)
		}

		// Seed the fixture templates, by name, only when missing.
		const listResp = await api.get('/index.php/apps/docudesk/api/templates')
		if (listResp.ok() === false) {
			// eslint-disable-next-line no-console
			console.warn(`[globalSetup] docudesk template list returned ${listResp.status()} — template fixtures skipped`)
			return
		}
		const existing = ((await listResp.json()).results || [])
			.map((tpl: Record<string, any>) => tpl.name)
		const created: string[] = []
		for (const name of DOCUDESK_TEMPLATE_NAMES) {
			if (existing.includes(name)) {
				continue
			}
			const resp = await api.post('/index.php/apps/docudesk/api/templates', {
				data: {
					name,
					description: 'openbuild e2e fixture template',
					namespace: 'openbuild',
					// `{{dossiernummer}}` is what the filename-interpolation
					// scenario (REQ-DDT-003) renders against.
					content: `<h1>${name}</h1><p>Dossier {{dossiernummer}}</p>`,
					format: 'A4',
					orientation: 'P',
				},
			})
			if (resp.ok()) {
				created.push(name)
			} else {
				// eslint-disable-next-line no-console
				console.warn(`[globalSetup] docudesk template "${name}" create returned ${resp.status()}`)
			}
		}
		// eslint-disable-next-line no-console
		console.log(
			`[globalSetup] docudesk templates ready: ${DOCUDESK_TEMPLATE_NAMES.join(', ')}`
			+ (created.length ? ` (created ${created.join(', ')})` : ' (already present)'),
		)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`[globalSetup] docudesk template seeding failed: ${(e as Error).message}`)
	} finally {
		await api.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0].use.baseURL as string)
		|| process.env.PLAYWRIGHT_BASE_URL
		|| 'http://localhost:8080'
	const adminUser = process.env.NC_ADMIN_USER || 'admin'
	const adminPassword = process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin'
	const storagePath = 'tests/e2e/.auth/admin.json'

	if (existsSync(dirname(storagePath)) === false) {
		mkdirSync(dirname(storagePath), { recursive: true })
	}

	// Before anything else: the wizard endpoint's 10-per-hour user rate limit
	// would otherwise 429 every app creation past the tenth, mid-run.
	disableRateLimitProtection()

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Retry the whole login: this dev instance is shared, and a concurrent
	// build/test run or an app upgrade elsewhere can make even the login page
	// exceed Playwright's 30s default navigation timeout. A single timeout used
	// to leave EVERY spec unauthenticated (whole-suite false red), so give the
	// slow path an explicit budget and two more attempts before giving up.
	const LOGIN_ATTEMPTS = 3
	for (let attempt = 1; attempt <= LOGIN_ATTEMPTS; attempt++) {
	try {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded', timeout: 90_000 })
		await page.locator('input[name="user"]').fill(adminUser)
		await page.locator('input[name="password"]').fill(adminPassword)
		// Submit via the form rather than a button click: on a slow/loaded
		// instance the themed submit button's click can be swallowed by an
		// overlay/animation and never navigate. Falling back to a button
		// click keeps it working where the form ref is unavailable.
		await page.evaluate(() => {
			const form = document.querySelector('form[name="login"], form') as HTMLFormElement | null
			if (form && typeof form.requestSubmit === 'function') {
				form.requestSubmit()
			} else if (form) {
				form.submit()
			} else {
				document.querySelector<HTMLButtonElement>('button[type="submit"], input[type="submit"]')?.click()
			}
		})
		// Accept both pretty + index.php-prefixed redirects. Generous
		// timeout for slow dev instances.
		//
		// Do NOT treat the post-login URL as the success signal on its own: on a
		// loaded dev instance the redirect chain (login -> apps/<default app>)
		// can outlast any reasonable timeout while the session cookie is in fact
		// already set, which used to abort setup and leave EVERY spec running
		// unauthenticated (a whole-suite false red). Wait for the URL, but fall
		// back to probing an authenticated API endpoint and accept the session
		// whenever that probe succeeds.
		await page.waitForURL(/\/apps\//, { timeout: 60_000 }).catch(() => {})
		const authed = await page.evaluate(async () => {
			try {
				const resp = await fetch('/index.php/apps/openbuild/api/applications', {
					headers: { 'OCS-APIRequest': 'true' },
				})
				return resp.status !== 401
			} catch {
				return false
			}
		})
		if (authed === false) {
			throw new Error('session cookie not accepted by an authenticated endpoint (401)')
		}
		await context.storageState({ path: storagePath })
		// eslint-disable-next-line no-console
		console.log(`[globalSetup] authenticated session stored at ${storagePath}`)
		break
	} catch (e) {
		if (attempt < LOGIN_ATTEMPTS) {
			// eslint-disable-next-line no-console
			console.warn(`[globalSetup] login attempt ${attempt}/${LOGIN_ATTEMPTS} failed (${(e as Error).message}) — retrying`)
			continue
		}
		// eslint-disable-next-line no-console
		console.warn(`[globalSetup] login failed after ${LOGIN_ATTEMPTS} attempts — specs will run unauthenticated: ${(e as Error).message}`)
	}
	}
	await browser.close()

	// Seed the hello-world fixture the specs run against (idempotent).
	seedHelloWorldFixture()

	// Configure Docudesk + seed its template fixtures (idempotent, and a no-op
	// with a log when Docudesk is not installed).
	await seedDocudeskTemplateFixtures(baseURL, adminUser, adminPassword)
}
