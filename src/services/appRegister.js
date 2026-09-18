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
	const self = version['@self'] || {}
	return String(self.id || version.uuid || version.id || '')
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
	if (productionVersion && typeof productionVersion === 'object') {
		const embedded = versionRegister(productionVersion)
		if (embedded) {
			return embedded
		}
	}
	const uuid =
		typeof productionVersion === 'string'
			? productionVersion
			: versionUuid(productionVersion)
	if (!appSlug || !uuid) {
		return ''
	}
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/versions`,
			),
		)
		const list = Array.isArray(data)
			? data
			: Array.isArray(data && data.results)
				? data.results
				: []
		return versionRegister(list.find((v) => versionUuid(v) === uuid))
	} catch {
		return ''
	}
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
		if (versionSlug) {
			const { data } = await axios.get(
				generateUrl(`${base}/versions/${encodeURIComponent(versionSlug)}`),
			)
			return versionRegister(data)
		}
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/objects/buildiq/built-app'),
			{ params: { slug: appSlug, _limit: 1 } },
		)
		const apps = Array.isArray(data && data.results) ? data.results : []
		const app = apps.find((a) => a && a.slug === appSlug)
		return app
			? await fetchProductionRegister(appSlug, app.productionVersion)
			: ''
	} catch {
		return ''
	}
}
