// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Shared e2e fixture: a `pw-` app cloned from a seeded template, made in
 * `beforeAll` and removed again in `afterAll`.
 *
 * `ensureApp()` (appFixture.ts) makes an app with a register and no schema,
 * which is enough for a page that binds to nothing. The specs that bind a page
 * to a schema, write records, or point an automation's record step at "one of
 * the app's schemas" need a schema that belongs to the app. The
 * `incident-reporter` template brings one (`incident`: reportedBy, location,
 * incidentType, severity, description, reportedAt, status), plus an index and a
 * detail page bound to it, so cloning it gives every such spec the same known
 * starting point. tests/e2e/page-codes.spec.ts did this inline first; this is
 * that code, shared.
 *
 * Every call runs inside the page (`fetch` with the session cookie and the
 * request token), because the plain AppFramework routes refuse a write that
 * carries neither (see appFixture.ts).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import type { Browser, Page } from '@playwright/test'

import path from 'node:path'
import { E2E_BASE_URL } from './baseUrl.ts'

/** The suite's admin session, written by global-setup. */
export const ADMIN_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** A cloned fixture app. */
export interface TemplateApp {
	/** The app slug. */
	slug: string
	/** The Application uuid, for the delete in `afterAll`. */
	uuid: string
	/** The app's own register slug. */
	register: string
	/** The schema the template's index and detail pages are bound to. */
	schema: string
}

/**
 * Call a Buildiq or OpenRegister route from inside the page.
 *
 * @param page   A signed-in page, on a Nextcloud route.
 * @param method The HTTP method.
 * @param url    The path, starting with `/index.php/`.
 * @param body   The JSON body, if any.
 * @return The status and the decoded body (the raw text when it is not JSON).
 */
export async function api(
	page: Page,
	method: string,
	url: string,
	body?: unknown,
): Promise<{ status: number; json: any }> {
	return page.evaluate(
		async ({ method, url, body }) => {
			const token = (window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
				?? ''
			const resp = await fetch(url, {
				body: body === undefined ? undefined : JSON.stringify(body),
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
					requesttoken: token,
				},
				method,
			})
			const text = await resp.text()
			const parse = (raw: string): unknown => {
				try {
					return JSON.parse(raw)
				} catch {
					return raw
				}
			}
			return { json: parse(text), status: resp.status }
		},
		{ body, method, url },
	)
}

/**
 * An object's uuid in any of the shapes OpenRegister and Buildiq answer it in.
 *
 * @param obj The decoded object.
 * @return The uuid, or ''.
 */
export function idOf(obj: any): string {
	return String(obj?.uuid ?? obj?.['@self']?.id ?? obj?.id ?? '')
}

/**
 * Open a page in a fresh admin context, already on a Buildiq route so `api()`
 * has a request token to send. The caller closes it.
 *
 * @param browser The Playwright browser.
 * @return The page.
 */
export async function adminPage(browser: Browser): Promise<Page> {
	const page = await browser.newPage({ baseURL: E2E_BASE_URL, storageState: ADMIN_STATE })
	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	return page
}

/**
 * A slug for a fixture app: `pw-`, the name, and a per-run suffix, so a run
 * that died before its `afterAll` never makes the next one collide.
 *
 * @param name The fixture name, lower-kebab.
 * @return The slug, at most 32 characters.
 */
export function fixtureSlug(name: string): string {
	return `pw-${name}-${Date.now().toString(36)}`.slice(0, 32).replace(/-+$/, '')
}

/**
 * Clone a seeded template into a new app.
 *
 * @param page     An admin page from `adminPage()`.
 * @param slug     The new app's slug (see `fixtureSlug()`).
 * @param name     The new app's name.
 * @param template The template slug.
 * @return The app.
 * @throws {Error} When the clone is refused, naming the status and the answer.
 */
export async function cloneTemplateApp(
	page: Page,
	slug: string,
	name: string,
	template = 'incident-reporter',
): Promise<TemplateApp> {
	const created = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/from-template/${template}`, { name, slug })
	if (created.status >= 300) {
		throw new Error(`cloning ${template} into ${slug} failed: ${created.status} ${JSON.stringify(created.json).slice(0, 300)}`)
	}
	const manifest = await readManifest(page, slug)
	const pages = (manifest.pages ?? []) as Array<Record<string, any>>
	const bound = pages.find((p) => p.type === 'index' && p.config?.schema)
		?? pages.find((p) => p.config?.schema)
	const register = String(created.json?.register ?? bound?.config?.register ?? `buildiq-${slug}`)
	return {
		slug,
		uuid: idOf(created.json?.application ?? created.json),
		register: register.includes('{{') ? `buildiq-${slug}` : register,
		schema: String(bound?.config?.schema ?? 'incident'),
	}
}

/**
 * Remove a fixture app with its data. Never throws: an `afterAll` that fails
 * hides the failure of the test before it.
 *
 * @param page An admin page from `adminPage()`.
 * @param uuid The Application uuid; '' does nothing.
 * @return Resolves once the delete was asked.
 */
export async function deleteApp(page: Page, uuid: string): Promise<void> {
	if (uuid === '') {
		return
	}
	await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}?deleteData=1`).catch(() => undefined)
}

/**
 * The stored manifest of an app.
 *
 * @param page A signed-in page.
 * @param slug The app slug.
 * @return The manifest.
 */
export async function readManifest(page: Page, slug: string): Promise<Record<string, any>> {
	const read = await api(page, 'GET', `/index.php/apps/buildiq/api/applications/${slug}/manifest`)
	if (read.status !== 200) {
		throw new Error(`reading the manifest of ${slug} failed: ${read.status}`)
	}
	return read.json as Record<string, any>
}

/**
 * Store a manifest, the way the designer and the in-app editor do.
 *
 * @param page     A signed-in page.
 * @param slug     The app slug.
 * @param manifest The whole manifest.
 * @return Resolves once stored.
 * @throws {Error} When the save is refused.
 */
export async function writeManifest(page: Page, slug: string, manifest: Record<string, any>): Promise<void> {
	const saved = await api(page, 'PUT', `/index.php/apps/buildiq/api/applications/${slug}/manifest`, { manifest })
	if (saved.status >= 300) {
		throw new Error(`saving the manifest of ${slug} failed: ${saved.status} ${JSON.stringify(saved.json).slice(0, 300)}`)
	}
}
