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
/**
 * The docker container serving the instance under test, resolved from its
 * PUBLISHED PORT.
 *
 * WHY THIS EXISTS — read before replacing it with a name.
 *
 * Both `occ` helpers below used to hardcode `docker exec … nextcloud …`. That
 * container is the SHARED dev box on :8080. The suite is driven with
 * PLAYWRIGHT_BASE_URL pointing at the disposable container (:8099), so every run
 * seeded fixtures into — and set config on, and gracefully restarted Apache in —
 * an instance the tests were not asserting against. A cross-instance write on
 * every single run, silent unless the shared box happened to be down (which is
 * how it surfaced: "hello-world fixture seed failed" while :8080 sat in
 * maintenance). Same class of drift as the base-URL bug that
 * tests/e2e/support/baseUrl.ts documents.
 *
 * Resolution is by `--filter publish=<port>` rather than by name. `docker ps -f
 * name=nextcloud` is a SUBSTRING match — it happily returns `nextcloud`,
 * `ob-vue3-e2e-nextcloud`, or somebody's `nextcloud-old` — so matching on the
 * port the tests actually talk to is the only form that cannot pick the wrong
 * box.
 *
 * Returns null when nothing publishes that port; callers then SKIP their occ
 * step with a clear reason instead of falling back to a hardcoded container,
 * because the fallback is exactly the bug.
 *
 * @param baseURL The instance under test.
 * @return {?string} The container name, or null when it cannot be resolved.
 */
function resolveContainerFor(baseURL: string): string | null {
	const override = process.env.OPENBUILD_E2E_CONTAINER
	if (override) {
		return override
	}
	let port: string
	try {
		port = new URL(baseURL).port || (baseURL.startsWith('https') ? '443' : '80')
	} catch {
		return null
	}
	try {
		const out = execSync(
			`docker ps --format '{{.Names}}' --filter publish=${port}`,
			{
				encoding: 'utf8',
				stdio: ['ignore', 'pipe', 'pipe'],
			},
		)
		const names = out
			.split('\n')
			.map((n) => n.trim())
			.filter(Boolean)
		return names[0] ?? null
	} catch {
		return null
	}
}

function seedHelloWorldFixture(container: string | null): void {
	const cmd =
		process.env.OPENBUILD_SEED_CMD
		|| (container
			? `docker exec -u www-data ${container} php occ openbuild:seed-hello-world-fixture`
			: null)
	if (!cmd) {
		// eslint-disable-next-line no-console
		console.warn(
			'[globalSetup] hello-world fixture NOT seeded: could not resolve the container '
				+ 'serving the instance under test. Set OPENBUILD_E2E_CONTAINER or OPENBUILD_SEED_CMD. '
				+ 'Deliberately not falling back to a hardcoded container — that would seed a DIFFERENT instance.',
		)
		return
	}
	try {
		const out = execSync(cmd, {
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		// eslint-disable-next-line no-console
		console.log(
			`[globalSetup] hello-world fixture: ${out.trim().split('\n').pop()}`,
		)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			`[globalSetup] hello-world fixture seed failed (specs needing it will fail): ${(e as Error).message}`,
		)
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
 *
 * @param container The container serving the instance under test, or null when
 *   it could not be resolved (in which case the config is left alone).
 * @return {void}
 */
function disableRateLimitProtection(container: string | null): void {
	// The graceful reload is not optional on a warm container: config.php is
	// opcached, and with the container default `opcache.revalidate_freq=60` the
	// new value can be served stale for up to a minute — long enough for the
	// first specs to 429 anyway. Measured: setting the value alone left the
	// endpoint still returning 429; it only took effect after the reload.
	const cmd =
		process.env.OPENBUILD_RATELIMIT_CMD
		|| (container
			? `docker exec -u www-data ${container} php occ config:system:set `
				+ 'ratelimit.protection.enabled --value=false --type=boolean '
				+ `&& docker exec ${container} apache2ctl graceful`
			: null)
	if (!cmd) {
		// eslint-disable-next-line no-console
		console.warn(
			'[globalSetup] rate-limit protection left ALONE: could not resolve the container '
				+ 'serving the instance under test. Set OPENBUILD_E2E_CONTAINER or OPENBUILD_RATELIMIT_CMD. '
				+ 'This one writes config and gracefully restarts Apache, so it must never guess an instance.',
		)
		return
	}
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
		console.log(
			'[globalSetup] docudesk seeding skipped (OPENBUILD_DOCUDESK_SEED=0)',
		)
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
			Authorization:
				'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
		},
	})
	try {
		const settingsResp = await api.get('/index.php/apps/docudesk/api/settings')
		if (settingsResp.status() === 404 || settingsResp.status() === 501) {
			// eslint-disable-next-line no-console
			console.log(
				'[globalSetup] docudesk not installed — template fixtures skipped',
			)
			return
		}
		if (settingsResp.ok() === false) {
			// eslint-disable-next-line no-console
			console.warn(
				`[globalSetup] docudesk settings probe returned ${settingsResp.status()} — template fixtures skipped`,
			)
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
			const register = registers.find(
				(r: Record<string, any>) => r.slug === 'docudesk',
			)
			const schema = (register?.schemas || []).find(
				(s: Record<string, any>) => s.slug === 'template',
			)
			if (!register || !schema) {
				// eslint-disable-next-line no-console
				console.warn(
					'[globalSetup] docudesk register/`template` schema not found — template fixtures skipped',
				)
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
			console.warn(
				`[globalSetup] docudesk template list returned ${listResp.status()} — template fixtures skipped`,
			)
			return
		}
		const existing = ((await listResp.json()).results || []).map(
			(tpl: Record<string, any>) => tpl.name,
		)
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
				console.warn(
					`[globalSetup] docudesk template "${name}" create returned ${resp.status()}`,
				)
			}
		}
		// eslint-disable-next-line no-console
		console.log(
			`[globalSetup] docudesk templates ready: ${DOCUDESK_TEMPLATE_NAMES.join(', ')}`
				+ (created.length
					? ` (created ${created.join(', ')})`
					: ' (already present)'),
		)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			`[globalSetup] docudesk template seeding failed: ${(e as Error).message}`,
		)
	} finally {
		await api.dispose()
	}
}

/**
 * The RBAC fixture users the permission specs need, with the credentials those
 * specs already expect. Passwords are >= 10 chars: Nextcloud silently refuses
 * shorter ones and the user is then simply absent.
 */
export const ROLE_USERS = [
	{ id: 'rbac-owner', pass: 'RbacOwner-1!', groups: [] as string[] },
	{ id: 'rbac-editor', pass: 'RbacEditor-1!', groups: ['rbac-editors'] },
	{ id: 'rbac-viewer', pass: 'RbacViewer-1!', groups: ['rbac-viewers'] },
	// Deliberately in NO group referenced by any Application's permissions.
	{ id: 'rbac-outsider', pass: 'RbacOutsider-1!', groups: [] as string[] },
] as const

/**
 * Provision the RBAC fixture users and groups through the OCS provisioning API,
 * then mint one storageState per user under `tests/e2e/.auth/{id}.json`.
 *
 * Why this lives in globalSetup rather than in the specs: the permission suites
 * used to form-log-in per test. Nextcloud's brute-force throttle fires after a
 * handful of near-simultaneous logins from one IP, and once it does every later
 * spec falls back to /login — a whole-suite false red. Logging each role in
 * exactly ONCE here, and letting specs attach the stored session, is what makes
 * those suites safe to enable at all.
 *
 * Idempotent: an existing user returns OCS 102 ("user already exists"), which is
 * treated as success. Failures are logged, never thrown — a missing RBAC user
 * must not take down the suites that do not need one; those specs assert the
 * session they were given and fail on their own terms.
 *
 * @param baseURL       The instance under test.
 * @param adminUser     Admin login, for the provisioning calls.
 * @param adminPassword Admin password.
 * @return {Promise<void>}
 */
async function seedRoleUsersAndSessions(
	baseURL: string,
	adminUser: string,
	adminPassword: string,
): Promise<void> {
	const auth =
		'Basic ' + Buffer.from(`${adminUser}:${adminPassword}`).toString('base64')
	const headers = {
		Authorization: auth,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/x-www-form-urlencoded',
		Accept: 'application/json',
	}

	const groups = [...new Set(ROLE_USERS.flatMap((u) => u.groups))]
	for (const group of groups) {
		try {
			await fetch(`${baseURL}/ocs/v2.php/cloud/groups`, {
				method: 'POST',
				headers,
				body: new URLSearchParams({ groupid: group }).toString(),
			})
		} catch (e) {
			// eslint-disable-next-line no-console
			console.warn(
				`[globalSetup] could not create group ${group}: ${(e as Error).message}`,
			)
		}
	}

	for (const user of ROLE_USERS) {
		try {
			const body = new URLSearchParams({
				userid: user.id,
				password: user.pass,
			})
			for (const group of user.groups) {
				body.append('groups[]', group)
			}
			const resp = await fetch(`${baseURL}/ocs/v2.php/cloud/users`, {
				method: 'POST',
				headers,
				body: body.toString(),
			})
			const text = await resp.text()
			// OCS v2 answers 200 on success; the v1 shape answers 100 (created) or
			// 102 (already exists).
			if (
				!/<statuscode>(10[02]|200)<\/statuscode>|"statuscode":(10[02]|200)/.test(
					text,
				)
			) {
				// eslint-disable-next-line no-console
				console.warn(
					`[globalSetup] provisioning ${user.id} returned an unexpected status: ${text.slice(0, 160)}`,
				)
			}

			// "Already exists" is NOT the same as "usable". On a long-lived
			// instance the account may predate this harness and carry a DIFFERENT
			// password, in which case creation is skipped, every later login fails,
			// and the specs run that role UNAUTHENTICATED — which reads as a
			// product change, not a fixture gap. Observed on the shared dev box:
			// rbac-owner/editor/viewer authenticated 200, rbac-outsider 401, and
			// versionRouting's "non-member must receive 404" failed with 401.
			//
			// So prove the credentials work, and repair them if they do not.
			const probe = await fetch(
				`${baseURL}/ocs/v2.php/cloud/user?format=json`,
				{
					headers: {
						'OCS-APIRequest': 'true',
						Authorization:
							'Basic '
							+ Buffer.from(`${user.id}:${user.pass}`).toString(
								'base64',
							),
					},
				},
			)
			if (probe.status === 401) {
				const reset = await fetch(
					`${baseURL}/ocs/v2.php/cloud/users/${encodeURIComponent(user.id)}`,
					{
						method: 'PUT',
						headers,
						body: new URLSearchParams({
							key: 'password',
							value: user.pass,
						}).toString(),
					},
				)
				const ok = await fetch(
					`${baseURL}/ocs/v2.php/cloud/user?format=json`,
					{
						headers: {
							'OCS-APIRequest': 'true',
							Authorization:
								'Basic '
								+ Buffer.from(`${user.id}:${user.pass}`).toString(
									'base64',
								),
						},
					},
				)
				// eslint-disable-next-line no-console
				console.warn(
					`[globalSetup] ${user.id} existed with a different password — reset ${reset.status}, now ${ok.status === 200 ? 'usable' : `STILL UNUSABLE (${ok.status})`}`,
				)
			}
		} catch (e) {
			// eslint-disable-next-line no-console
			console.warn(
				`[globalSetup] could not provision ${user.id}: ${(e as Error).message}`,
			)
		}
	}

	// Mint one session per role — ONCE, sequentially.
	const browser = await chromium.launch()
	const failed: string[] = []
	for (const user of ROLE_USERS) {
		const statePath = `tests/e2e/.auth/${user.id}.json`
		const context = await browser.newContext({ baseURL })
		const page = await context.newPage()
		try {
			await page.goto('/index.php/login', {
				waitUntil: 'domcontentloaded',
				timeout: 90_000,
			})
			await page.locator('input[name="user"]').fill(user.id)
			await page.locator('input[name="password"]').fill(user.pass)
			await page
				.locator('form')
				.first()
				.evaluate((form: HTMLFormElement) => form.requestSubmit())
			// Wait on the URL leaving /login rather than on `#header`: the landing
			// page differs per user (dashboard vs first-run) and a header selector
			// races the theming bundle. Generous budget — four logins in a row is
			// exactly the pattern Nextcloud's brute-force throttle slows down, which
			// is why this is done ONCE here rather than per test.
			await page.waitForURL(
				(url) => !/\/login(\?|$|\/)/.test(url.toString()),
				{ timeout: 60_000 },
			)
			await context.storageState({ path: statePath })
			// eslint-disable-next-line no-console
			console.log(`[globalSetup] ${user.id} session stored at ${statePath}`)
		} catch (e) {
			failed.push(user.id)
			// eslint-disable-next-line no-console
			console.warn(
				`[globalSetup] could not mint a session for ${user.id}: ${(e as Error).message}`,
			)
		} finally {
			await context.close()
		}
		// Space the logins out: consecutive form logins from one IP are what
		// trips the throttle, and a throttled login stalls well past any
		// reasonable timeout.
		await new Promise((resolve) => setTimeout(resolve, 1_500))
	}
	await browser.close()

	// ⚠️ ON CI A MISSING ROLE SESSION IS FATAL, NOT A WARNING.
	//
	// Off CI the warning above is the right call: a developer running one spec
	// against a shared box must not be blocked by an unrelated fixture user. On
	// CI it is the opposite, because of what an absent storage state DOES to the
	// permission suites. `test.use({ storageState: 'tests/e2e/.auth/rbac-outsider.json' })`
	// with no such file, or one holding an empty cookie jar, does not error — the
	// spec simply runs UNAUTHENTICATED. And an unauthenticated caller is denied
	// everything, so `rbac-403.spec.ts`'s "the outsider sees no application
	// cards" and `versionRouting.spec.ts`'s "a non-member receives 404" both
	// PASS — for exactly the wrong reason. The absence of the fixture is
	// indistinguishable from the product enforcing its rules.
	//
	// So on CI, say so loudly and stop. A failed globalSetup is one legible
	// failure; a silently unauthenticated RBAC suite is a false green.
	if (failed.length > 0 && (process.env.CI || process.env.GITHUB_ACTIONS)) {
		throw new Error(
			`[globalSetup] could not mint a session for the RBAC fixture user(s): ${failed.join(', ')}. `
				+ 'Refusing to continue on CI: an RBAC spec attaching a missing storage state runs '
				+ 'UNAUTHENTICATED, and every "must be denied" assertion then passes for the wrong reason.',
		)
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL =
		(config.projects[0].use.baseURL as string)
		|| process.env.PLAYWRIGHT_BASE_URL
		|| 'http://localhost:8080'
	const adminUser = process.env.NC_ADMIN_USER || 'admin'
	const adminPassword =
		process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin'
	const storagePath = 'tests/e2e/.auth/admin.json'

	if (existsSync(dirname(storagePath)) === false) {
		mkdirSync(dirname(storagePath), { recursive: true })
	}

	// Before anything else: the wizard endpoint's 10-per-hour user rate limit
	// would otherwise 429 every app creation past the tenth, mid-run.
	const container = resolveContainerFor(baseURL)
	// eslint-disable-next-line no-console
	console.log(
		`[globalSetup] instance under test: ${baseURL} (container: ${container ?? 'unresolved'})`,
	)
	disableRateLimitProtection(container)

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Retry the whole login: this dev instance is shared, and a concurrent
	// build/test run or an app upgrade elsewhere can make even the login page
	// exceed Playwright's 30s default navigation timeout. A single timeout used
	// to leave EVERY spec unauthenticated (whole-suite false red), so give the
	// slow path an explicit budget and two more attempts before giving up.
	const LOGIN_ATTEMPTS = 3
	let adminAuthenticated = false
	for (let attempt = 1; attempt <= LOGIN_ATTEMPTS; attempt++) {
		try {
			await page.goto('/index.php/login', {
				waitUntil: 'domcontentloaded',
				timeout: 90_000,
			})
			await page.locator('input[name="user"]').fill(adminUser)
			await page.locator('input[name="password"]').fill(adminPassword)
			// Submit via the form rather than a button click: on a slow/loaded
			// instance the themed submit button's click can be swallowed by an
			// overlay/animation and never navigate. Falling back to a button
			// click keeps it working where the form ref is unavailable.
			await page.evaluate(() => {
				const form = document.querySelector(
					'form[name="login"], form',
				) as HTMLFormElement | null
				if (form && typeof form.requestSubmit === 'function') {
					form.requestSubmit()
				} else if (form) {
					form.submit()
				} else {
					document
						.querySelector<HTMLButtonElement>(
							'button[type="submit"], input[type="submit"]',
						)
						?.click()
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
					const resp = await fetch(
						'/index.php/apps/openbuild/api/applications',
						{
							headers: { 'OCS-APIRequest': 'true' },
						},
					)
					return resp.status !== 401
				} catch {
					return false
				}
			})
			if (authed === false) {
				throw new Error(
					'session cookie not accepted by an authenticated endpoint (401)',
				)
			}
			await context.storageState({ path: storagePath })
			adminAuthenticated = true
			// eslint-disable-next-line no-console
			console.log(
				`[globalSetup] authenticated session stored at ${storagePath}`,
			)
			break
		} catch (e) {
			if (attempt < LOGIN_ATTEMPTS) {
				// eslint-disable-next-line no-console
				console.warn(
					`[globalSetup] login attempt ${attempt}/${LOGIN_ATTEMPTS} failed (${(e as Error).message}) — retrying`,
				)
				continue
			}
			// eslint-disable-next-line no-console
			console.warn(
				`[globalSetup] login failed after ${LOGIN_ATTEMPTS} attempts — specs will run unauthenticated: ${(e as Error).message}`,
			)
		}
	}
	await browser.close()

	// On CI, stop here rather than run the whole suite unauthenticated.
	//
	// Off CI the warning is deliberate — a developer gets a legible "still on
	// /login" snapshot out of the failing spec. On a runner it just produces a
	// wall of selector timeouts across ~327 tests whose common cause is stated
	// only in a console line nobody reads, and it burns the job's entire 45
	// minute budget to say so. One failure, named, is worth more.
	if (
		adminAuthenticated === false
		&& (process.env.CI || process.env.GITHUB_ACTIONS)
	) {
		throw new Error(
			`[globalSetup] could not authenticate as '${adminUser}' after ${LOGIN_ATTEMPTS} attempts. `
				+ 'Refusing to continue on CI: every spec would fail on a selector timeout that accuses '
				+ 'the selector rather than the missing session.',
		)
	}

	// Seed the hello-world fixture the specs run against (idempotent).
	seedHelloWorldFixture(container)

	// Configure Docudesk + seed its template fixtures (idempotent, and a no-op
	// with a log when Docudesk is not installed).
	await seedDocudeskTemplateFixtures(baseURL, adminUser, adminPassword)

	// RBAC fixture users + one stored session per role (idempotent).
	await seedRoleUsersAndSessions(baseURL, adminUser, adminPassword)
}
