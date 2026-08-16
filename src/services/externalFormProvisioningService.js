// SPDX-License-Identifier: EUPL-1.2
/**
 * externalFormProvisioningService — provisions/revokes the two OpenRegister
 * primitives an "externally fillable" page needs, riding the builder's own
 * NC session (no OpenBuild PHP proxy — ADR-022, design.md Decision 3):
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
 * Build the `type: "create"` action entry bound to `(register, schema)`
 * that makes a `portalPage` accept the toggle's anonymous submissions
 * (REQ-EFP-004). `minTrust: 0` is the anonymous-eligible floor.
 *
 * @param {string} register - the OR register slug.
 * @param {string} schema - the OR schema slug.
 * @return {object}
 */
function buildAnonymousCreateAction(register, schema) {
	return {
		type: 'create',
		register,
		schema,
		anonymous: true,
		minTrust: 0,
	}
}

/**
 * Merge (or insert) the anonymous create action for `(register, schema)`
 * into an existing `actions[]` array without disturbing any other action
 * entry — matched by `{type, register, schema}` so a repeat save updates
 * the SAME entry rather than appending a duplicate.
 *
 * @param {Array<object>|undefined} actions - the portalPage's current actions.
 * @param {string} register - the OR register slug.
 * @param {string} schema - the OR schema slug.
 * @return {Array<object>}
 */
function mergeAnonymousCreateAction(actions, register, schema) {
	const next = Array.isArray(actions) ? actions.slice() : []
	const entry = buildAnonymousCreateAction(register, schema)
	const idx = next.findIndex(
		(a) =>
			a
			&& a.type === 'create'
			&& a.register === register
			&& a.schema === schema,
	)
	if (idx >= 0) {
		next[idx] = { ...next[idx], ...entry }
	} else {
		next.push(entry)
	}
	return next
}

/**
 * Merge (or insert) the anonymous collection entry for `(register, schema)`
 * into an existing `collections[]` array, matched by `{register, schema}`.
 *
 * @param {Array<object>|undefined} collections - the portalPage's current collections.
 * @param {string} register - the OR register slug.
 * @param {string} schema - the OR schema slug.
 * @return {Array<object>}
 */
function mergeAnonymousCollection(collections, register, schema) {
	const next = Array.isArray(collections) ? collections.slice() : []
	const idx = next.findIndex(
		(c) => c && c.register === register && c.schema === schema,
	)
	const entry = { register, schema, anonymous: true }
	if (idx >= 0) {
		next[idx] = { ...next[idx], ...entry }
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
 * @param {object} [client] - axios-like client (test injection).
 * @return {Promise<{objectId: ?string, portalPath: ?string, unavailable: boolean}>}
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
 */
export async function provisionPortalPage(
	{ register, schema, objectId },
	client = defaultAxios,
) {
	try {
		if (objectId) {
			const { data: current } = await client.get(portalPageUrl(objectId))
			const payload = {
				...current,
				status: 'active',
				collections: mergeAnonymousCollection(
					current && current.collections,
					register,
					schema,
				),
				actions: mergeAnonymousCreateAction(
					current && current.actions,
					register,
					schema,
				),
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
			minTrust: 0,
			collections: [{ register, schema, anonymous: true }],
			actions: [buildAnonymousCreateAction(register, schema)],
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
