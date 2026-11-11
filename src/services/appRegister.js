// SPDX-License-Identifier: EUPL-1.2
/**
 * appRegister: which OpenRegister register holds an app's own data.
 *
 * Every version of an app has its own register, named
 * `openbuild-{slug}-{version}` for apps made by the creation wizard, and the
 * ApplicationVersion record carries that name in its `register` field. The
 * name cannot be rebuilt from the app slug alone: `openbuild-{slug}` exists
 * for no wizard-made app, so a request against it 404s and every list built
 * on it comes back empty. Read the register off the version instead.
 *
 * @module services/appRegister
 * @spec openspec/specs/version-routing-ui/spec.md#requirement-version-composables-resolve-active-version-and-manifest-history
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The register a version record points at.
 *
 * @param {?object} version - an ApplicationVersion record.
 * @return {string} the register slug, or '' when the record has none.
 * @spec openspec/specs/version-routing-ui/spec.md#requirement-version-composables-resolve-active-version-and-manifest-history
 */
export function versionRegister(version) {
	const register = version && version.register
	return typeof register === 'string' ? register : ''
}

/**
 * The key a version record is known by.
 *
 * @param {?object} version - an ApplicationVersion record.
 * @return {string} its uuid, or ''.
 * @spec exclude record-shape helper for the two exported functions
 */
function versionUuid(version) {
	if (!version) {
		return ''
	}
	return String(selfId(version) || version.uuid || version.id || '')
}

/**
 * The id a version record carries in its `@self` block.
 *
 * @param {object} version - an ApplicationVersion record.
 * @return {(string|number|undefined)} the `@self` id, if any.
 * @spec exclude record-shape helper for versionUuid
 */
function selfId(version) {
	const self = version['@self'] || {}
	return self.id
}

/**
 * The register an embedded version record carries.
 *
 * @param {?(string|object)} productionVersion - a uuid or an embedded record.
 * @return {string} the register slug, or '' for a uuid or a record without one.
 * @spec exclude record-shape helper for fetchProductionRegister
 */
function embeddedRegister(productionVersion) {
	return productionVersion && typeof productionVersion === 'object'
		? versionRegister(productionVersion)
		: ''
}

/**
 * The uuid of the production version, given as a uuid or a record.
 *
 * @param {?(string|object)} productionVersion - a uuid or an embedded record.
 * @return {string} the uuid, or ''.
 * @spec exclude record-shape helper for fetchProductionRegister
 */
function productionUuid(productionVersion) {
	return typeof productionVersion === 'string'
		? productionVersion
		: versionUuid(productionVersion)
}

/**
 * The version records of a versions-list answer.
 *
 * @param {unknown} data - the response body: a list or `{ results }`.
 * @return {Array<object>} the records, or [] for any other shape.
 * @spec exclude response-shape helper for fetchVersionRegister
 */
function versionList(data) {
	if (Array.isArray(data)) {
		return data
	}
	return Array.isArray(data && data.results) ? data.results : []
}

/**
 * Look a version up in the app's versions list and read its register.
 *
 * @param {string} appSlug - the app slug.
 * @param {string} uuid - the version uuid.
 * @return {Promise<string>} the register slug, or '' when it cannot be found.
 * @spec exclude request helper for fetchProductionRegister
 */
async function fetchVersionRegister(appSlug, uuid) {
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/versions`,
			),
		)
		return versionRegister(versionList(data).find((v) => versionUuid(v) === uuid))
	} catch {
		return ''
	}
}

/**
 * The register of the app's production version, the version a URL without
 * `?_version=` serves.
 *
 * @param {string} appSlug - the app slug.
 * @param {?(string|object)} productionVersion - the Application's
 *   `productionVersion` (a uuid, or an embedded record).
 * @return {Promise<string>} the register slug, or '' when it cannot be found.
 * @spec openspec/specs/version-routing-ui/spec.md#requirement-version-composables-resolve-active-version-and-manifest-history
 */
export async function fetchProductionRegister(appSlug, productionVersion) {
	const embedded = embeddedRegister(productionVersion)
	if (embedded) {
		return embedded
	}
	const uuid = productionUuid(productionVersion)
	if (!appSlug || !uuid) {
		return ''
	}
	return fetchVersionRegister(appSlug, uuid)
}

/**
 * The register behind the version a runtime URL serves: the named version
 * when `?_version=` is set, otherwise the production version.
 *
 * @param {string} appSlug - the app slug.
 * @param {string} [versionSlug] - the `?_version=` value, if any.
 * @return {Promise<string>} the register slug, or '' when it cannot be found.
 * @spec openspec/specs/version-routing-ui/spec.md#requirement-version-composables-resolve-active-version-and-manifest-history
 */
export async function fetchAppRegister(appSlug, versionSlug) {
	if (!appSlug) {
		return ''
	}
	const base = `/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}`
	try {
		return versionSlug
			? await fetchNamedVersionRegister(base, versionSlug)
			: await fetchListedProductionRegister(appSlug)
	} catch {
		return ''
	}
}

/**
 * The register of the version a `?_version=` value names.
 *
 * @param {string} base - the app's API base path.
 * @param {string} versionSlug - the `?_version=` value.
 * @return {Promise<string>} the register slug, or '' when the record has none.
 * @spec exclude request helper for fetchAppRegister
 */
async function fetchNamedVersionRegister(base, versionSlug) {
	const { data } = await axios.get(
		generateUrl(`${base}/versions/${encodeURIComponent(versionSlug)}`),
	)
	return versionRegister(data)
}

/**
 * The production register of the built-app record listed under a slug.
 *
 * @param {string} appSlug - the app slug.
 * @return {Promise<string>} the register slug, or '' when the app is not listed.
 * @spec exclude request helper for fetchAppRegister
 */
async function fetchListedProductionRegister(appSlug) {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/buildiq/built-app'),
		{ params: { slug: appSlug, _limit: 1 } },
	)
	const apps = Array.isArray(data && data.results) ? data.results : []
	const app = apps.find((a) => a && a.slug === appSlug)
	return app
		? await fetchProductionRegister(appSlug, app.productionVersion)
		: ''
}
