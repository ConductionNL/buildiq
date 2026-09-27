// SPDX-License-Identifier: EUPL-1.2
/**
 * externalFormProvisioningService — provisions/revokes the two OpenRegister
 * primitives an "externally fillable" page needs, riding the builder's own
 * NC session (no Buildiq PHP proxy — ADR-022, design.md Decision 3):
 *
 *   1. `GET`/`PATCH /api/schemas/{id}` — merge-safe schema authorization
 *      (REQ-EFP-003 / REQ-EFP-005). The schemas endpoint resolves `{id}` by
 *      slug directly (same pattern as `AutomationEditDialog.vue`'s
 *      `fetchTransitions()`), so no separate slug→uuid lookup is needed.
 *   2. `POST`/`PUT /api/objects/portaliq/portalPage` — the Portaliq render
 *      target (REQ-EFP-004). Degrades gracefully to "unavailable" when the
 *      `portaliq`/`portalPage` schema does not yet exist on the instance
 *      (design.md Decision 5) — the OR-only leg above is unconditional and
 *      independent of this leg's outcome.
 *
 * `authorization` and a `portalPage` object are both single JSON blobs on
 * the OR side — every write here is READ-MERGE-WRITE, never a partial
 * fragment PUT/PATCH, per the fleet-wide "OR saveObject is PUT-semantic —
 * nulls/omissions clobber existing fields" gotcha (design.md Decision 2).
 *
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-003
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-005
 */
import defaultAxios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const PUBLIC_GROUP = 'public'

/**
 * The sign-in levels portaliq enforces on a `portalPage` entry (its
 * `minTrust` string enum). There is no "anonymous" level: an entry without a
 * level and with `anonymous: true` is the anonymous one (portaliq#725).
 */
export const SIGN_IN_LEVELS = ['low', 'substantial', 'high']

/**
 * Whether a value is one of portaliq's sign-in levels.
 *
 * @param {string|null|undefined} value - the value.
 * @return {boolean}
 */
function isSignInLevel(value) {
	return SIGN_IN_LEVELS.includes(value)
}

/**
 * URL of a schema by slug (OR resolves `{id}` by slug or uuid).
 *
 * @param {string} schemaSlug - the schema slug.
 * @return {string}
 */
function schemaUrl(schemaSlug) {
	return generateUrl(
		`/apps/openregister/api/schemas/${encodeURIComponent(schemaSlug)}`,
	)
}

/**
 * URL of the `portaliq`/`portalPage` objects collection, or one object when
 * an id is given.
 *
 * @param {?string} [objectId] - the portalPage object's uuid.
 * @return {string}
 */
function portalPageUrl(objectId) {
	const base = '/apps/openregister/api/objects/portaliq/portalPage'
	return generateUrl(objectId ? `${base}/${encodeURIComponent(objectId)}` : base)
}

/**
 * Add a group to an authorization array without duplicating it. Returns a
 * NEW array (never mutates the input) — callers deep-copy the whole
 * `authorization` object before calling this per group.
 *
 * @param {Array<string>|undefined} list - the existing group list (or undefined).
 * @param {string} group - the group to add.
 * @return {Array<string>}
 */
function addGroup(list, group) {
	const next = Array.isArray(list) ? list.slice() : []
	if (!next.includes(group)) {
		next.push(group)
	}
	return next
}

/**
 * Remove a group from an authorization array, if present. Returns a NEW
 * array; other entries are left byte-identical.
 *
 * @param {Array<string>|undefined} list - the existing group list (or undefined).
 * @param {string} group - the group to remove.
 * @return {Array<string>}
 */
function removeGroup(list, group) {
	const next = Array.isArray(list) ? list.slice() : []
	return next.filter((g) => g !== group)
}

/**
 * Deep-copy a schema's `authorization` object (never share references with
 * the fetched payload — the caller mutates the copy, not the original).
 *
 * @param {?object} authorization - the schema's current authorization block.
 * @return {object}
 */
function cloneAuthorization(authorization) {
	return authorization && typeof authorization === 'object'
		? JSON.parse(JSON.stringify(authorization))
		: {}
}

/**
 * REQ-EFP-003: enable public create (+ optional public read) for a schema.
 * GET the schema, deep-copy its `authorization`, append `"public"` to
 * `create` (and to `read` when `publicRead`) WITHOUT touching any other
 * group already present in any of the four verbs, then PATCH the full
 * merged `authorization` object back as the ONLY changed top-level key.
 * Never sends a partial `{authorization: {create: [...]}}` fragment.
 *
 * @param {object} opts - options.
 * @param {string} opts.schema - the target schema slug.
 * @param {boolean} [opts.publicRead] - also add `"public"` to `read`.
 * @param {object} [client] - axios-like client (test injection).
 * @return {Promise<object>} - the updated schema.
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-003
 */
export async function enablePublicCreate(
	{ schema, publicRead = false },
	client = defaultAxios,
) {
	const { data: current } = await client.get(schemaUrl(schema))
	const authorization = cloneAuthorization(current && current.authorization)
	authorization.create = addGroup(authorization.create, PUBLIC_GROUP)
	if (publicRead) {
		authorization.read = addGroup(authorization.read, PUBLIC_GROUP)
	}
	const { data } = await client.patch(schemaUrl(schema), { authorization })
	return data
}

/**
 * REQ-EFP-005: revoke — reverse `enablePublicCreate`'s merge. GET the
 * current schema, remove `"public"` from `create` (and from `read` only
 * when this toggle had added it), leave every other group untouched, PATCH
 * the result.
 *
 * @param {object} opts - options.
 * @param {string} opts.schema - the target schema slug.
 * @param {boolean} [opts.removeRead] - also remove `"public"` from `read`
 *   (only when this toggle enabled `publicRead` in the first place).
 * @param {object} [client] - axios-like client (test injection).
 * @return {Promise<object>} - the updated schema.
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-005
 */
export async function revokePublicCreate(
	{ schema, removeRead = false },
	client = defaultAxios,
) {
	const { data: current } = await client.get(schemaUrl(schema))
	const authorization = cloneAuthorization(current && current.authorization)
	authorization.create = removeGroup(authorization.create, PUBLIC_GROUP)
	if (removeRead) {
		authorization.read = removeGroup(authorization.read, PUBLIC_GROUP)
	}
	const { data } = await client.patch(schemaUrl(schema), { authorization })
	return data
}

/**
 * Whether an axios error represents "the `portaliq`/`portalPage` schema
 * does not exist on this instance" — a 404 addressing that register/schema
 * pair (design.md Decision 5's degrade condition), as opposed to a genuine
 * failure (network error, 500, validation error) that should propagate.
 *
 * @param {Error} error - the caught error.
 * @return {boolean}
 */
function isPortalPageSchemaMissing(error) {
	const status = error && error.response && error.response.status
	return status === 404
}

/**
 * Apply a sign-in level to one `portalPage` entry (an action or a
 * collection), returning a NEW entry.
 *
 * - A level (`low`, `substantial`, `high`) sets `minTrust` and drops
 *   `anonymous`: portaliq treats the two as mutually exclusive.
 * - `null` is the maker explicitly choosing no sign-in: `anonymous: true`
 *   and no `minTrust`.
 * - `undefined` means the caller did not say. A level already stored on the
 *   entry (set in buildiq earlier, or by hand in portaliq) is KEPT, so a
 *   repeat save never lowers it to anonymous (buildiq#935). Without a stored
 *   level the entry is anonymous. A stored non-level `minTrust` (the invalid
 *   `0` older saves wrote, buildiq#921) is dropped.
 *
 * @param {object} entry - the entry, with its identifying keys set.
 * @param {?string|undefined} minTrust - the level, null, or undefined.
 * @return {object}
 */
function applySignInLevel(entry, minTrust) {
	const next = { ...entry }
	if (isSignInLevel(minTrust)) {
		delete next.anonymous
		next.minTrust = minTrust
		return next
	}
	if (minTrust === undefined && isSignInLevel(next.minTrust)) {
		delete next.anonymous
		return next
	}
	delete next.minTrust
	next.anonymous = true
	return next
}

/**
 * Merge (or insert) the create action for `(register, schema)` into an
 * existing `actions[]` array without disturbing any other action entry,
 * matched by `{type, register, schema}` so a repeat save updates the SAME
 * entry rather than appending a duplicate (REQ-EFP-004).
 *
 * @param {Array<object>|undefined} actions - the portalPage's current actions.
 * @param {string} register - the OR register slug.
 * @param {string} schema - the OR schema slug.
 * @param {?string|undefined} minTrust - the sign-in level (see applySignInLevel).
 * @return {Array<object>}
 */
function mergeCreateAction(actions, register, schema, minTrust) {
	const next = Array.isArray(actions) ? actions.slice() : []
	const idx = next.findIndex(
		(a) =>
			a
			&& a.type === 'create'
			&& a.register === register
			&& a.schema === schema,
	)
	const base = { ...(idx >= 0 ? next[idx] : {}), type: 'create', register, schema }
	const entry = applySignInLevel(base, minTrust)
	if (idx >= 0) {
		next[idx] = entry
	} else {
		next.push(entry)
	}
	return next
}

/**
 * Merge (or insert) the collection entry for `(register, schema)` into an
 * existing `collections[]` array, matched by `{register, schema}`, with the
 * same sign-in level as its create action.
 *
 * @param {Array<object>|undefined} collections - the portalPage's current collections.
 * @param {string} register - the OR register slug.
 * @param {string} schema - the OR schema slug.
 * @param {?string|undefined} minTrust - the sign-in level (see applySignInLevel).
 * @return {Array<object>}
 */
function mergeCollection(collections, register, schema, minTrust) {
	const next = Array.isArray(collections) ? collections.slice() : []
	const idx = next.findIndex(
		(c) => c && c.register === register && c.schema === schema,
	)
	const base = { ...(idx >= 0 ? next[idx] : {}), register, schema }
	const entry = applySignInLevel(base, minTrust)
	if (idx >= 0) {
		next[idx] = entry
	} else {
		next.push(entry)
	}
	return next
}

/**
 * Resolve a created/updated OR object's uuid from its response envelope.
 *
 * @param {object} data - the OR object response.
 * @return {string}
 */
function resolveObjectId(data) {
	return (
		(data && data['@self'] && data['@self'].id)
		|| (data && data.id)
		|| (data && data.uuid)
		|| ''
	)
}

/**
 * REQ-EFP-004: create or update the Portaliq `portalPage` object bound to
 * `(register, schema)`. First save (`objectId` falsy) POSTs a new object;
 * subsequent saves (matched by the stored `objectId`) GET-merge-PUT so any
 * other collections/actions/pages a builder or another toggle already put
 * on the SAME portalPage object survive untouched (the object-level twin of
 * the schema-authorization read-merge-write rule — OR objects are
 * PUT-semantic).
 *
 * Degrades gracefully when the `portaliq`/`portalPage` schema does not
 * exist on the instance: returns `{ objectId: null, portalPath: null,
 * unavailable: true }` and never throws — the OR-only leg
 * (`enablePublicCreate`) is unconditional and independent of this outcome.
 *
 * @param {object} opts - options.
 * @param {string} opts.register - the OR register slug the toggle targets.
 * @param {string} opts.schema - the OR schema slug the toggle targets.
 * @param {?string} [opts.objectId] - the previously-stored portalPage uuid, if any.
 * @param {?string} [opts.minTrust] - the sign-in level the form requires:
 *   `low`, `substantial` or `high`; `null` for none (anonymous); left out
 *   to keep whatever level the portal page already stores.
 * @param {object} [client] - axios-like client (test injection).
 * @return {Promise<{objectId: ?string, portalPath: ?string, unavailable: boolean}>}
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
 */
export async function provisionPortalPage(
	{ register, schema, objectId, minTrust },
	client = defaultAxios,
) {
	try {
		if (objectId) {
			const { data: current } = await client.get(portalPageUrl(objectId))
			const payload = {
				...current,
				status: 'active',
				collections: mergeCollection(
					current && current.collections,
					register,
					schema,
					minTrust,
				),
				actions: mergeCreateAction(
					current && current.actions,
					register,
					schema,
					minTrust,
				),
			}
			// An older save wrote the page-level number 0, which is not one of
			// portaliq's levels (buildiq#921). A real stored level stays.
			if ('minTrust' in payload && !isSignInLevel(payload.minTrust)) {
				delete payload.minTrust
			}
			const { data } = await client.put(portalPageUrl(objectId), payload)
			return {
				objectId: resolveObjectId(data) || objectId,
				portalPath: '/portal',
				unavailable: false,
			}
		}
		const payload = {
			label: `${schema} — external intake`,
			status: 'active',
			audience: 'public',
			collections: mergeCollection([], register, schema, minTrust),
			actions: mergeCreateAction([], register, schema, minTrust),
			pages: [],
		}
		const { data } = await client.post(portalPageUrl(), payload)
		return {
			objectId: resolveObjectId(data),
			portalPath: '/portal',
			unavailable: false,
		}
	} catch (error) {
		if (isPortalPageSchemaMissing(error)) {
			return { objectId: null, portalPath: null, unavailable: true }
		}
		throw error
	}
}

/**
 * REQ-EFP-004: disable — set the linked `portalPage` object's `status` to
 * `"draft"` (never delete it). GET-merge-PUT so every other field (label,
 * audience, collections, actions, pages) survives byte-identical; only
 * `status` changes. No-ops when no `portalPage` is linked.
 *
 * @param {?string} objectId - the linked portalPage uuid, or null/undefined when none.
 * @param {object} [client] - axios-like client (test injection).
 * @return {Promise<?object>} - the updated object, or null when there was nothing to do.
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
 */
export async function draftPortalPage(objectId, client = defaultAxios) {
	if (!objectId) {
		return null
	}
	const { data: current } = await client.get(portalPageUrl(objectId))
	const payload = { ...current, status: 'draft' }
	const { data } = await client.put(portalPageUrl(objectId), payload)
	return data
}
