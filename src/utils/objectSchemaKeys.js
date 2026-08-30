/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Identify "which register/schema is this object?" in a way that matches how a
 * MANIFEST names them.
 *
 * THE BUG THIS FIXES
 * ------------------
 * A manifest names a register and a schema by SLUG. `runtime.documents[].schema`
 * is authored as `hello-message`; `runtime.externalForms[]` likewise carries
 * `{register, schema}` slugs, and the attach dialogs write slugs.
 *
 * An OpenRegister object's `@self` envelope names them by NUMERIC ID. Measured
 * on a live instance:
 *
 *     "@self": { "register": "15", "schema": "21", ... }
 *
 * So the two runtime widgets that pair an object with its manifest entry —
 * `DocumentActions` (REQ-DDT-004) and `TrackLinkAction` (REQ-EFP-006) — were
 * comparing `"21" === "hello-message"`. That can never be true, so both widgets
 * rendered NOTHING for every object, on every app, always. No error, no warning:
 * an empty filter result is indistinguishable from "this schema declares no
 * attachments", which is exactly the shape they render as blank by design.
 *
 * THE FIX
 * -------
 * Compare on a normalised key SET rather than on one field. `CnDetailPage`
 * provides `cnObjectContext`, whose `schema` / `register` come from the page's
 * own manifest config (`config.schema`) — i.e. the slug, the same vocabulary the
 * manifest entry uses. That is the authoritative source; the `@self` ids are
 * kept in the set so a host that mounts a widget without the detail-page context
 * still matches when it happens to store ids.
 *
 * Deliberately NOT done: rewriting what the attach dialogs persist. REQ-DDT-001
 * specifies the slug and the dialogs already write it — the numeric side is the
 * wrong one, so the numeric side is what gets normalised away.
 */

/**
 * Unwrap a possible Vue ref.
 *
 * `cnObjectContext` is provided as a `ref`, but a host may inject a plain
 * object. Accept both rather than making every call site remember which.
 *
 * @param {*} maybeRef A value that may be a Vue ref.
 * @return {*} The unwrapped value.
 */
function unref(maybeRef) {
	if (maybeRef && typeof maybeRef === 'object' && 'value' in maybeRef) {
		return maybeRef.value
	}
	return maybeRef
}

/**
 * Build a de-duplicated, stringified key set from candidate values.
 *
 * @param {Array<*>} candidates Raw candidate values, most authoritative first.
 * @return {Array<string>} Non-empty unique string keys.
 */
function keySet(candidates) {
	const out = []
	for (const value of candidates) {
		if (value === undefined || value === null || value === '') {
			continue
		}
		const key = String(value)
		if (out.includes(key) === false) {
			out.push(key)
		}
	}
	return out
}

/**
 * Every name this object's SCHEMA can legitimately be known by.
 *
 * Ordered most-authoritative first: the page context's slug (manifest
 * vocabulary), then any top-level `schema` on the object, then the `@self`
 * numeric id and any slug OR may add later.
 *
 * @param {object} object The OR object being viewed.
 * @param {object} [context] The injected `cnObjectContext` (ref or plain).
 * @return {Array<string>} Candidate schema keys.
 */
export function objectSchemaKeys(object, context = null) {
	const obj = object || {}
	const self = obj['@self'] || {}
	const ctx = unref(context) || {}
	return keySet([ctx.schema, obj.schema, self.schemaSlug, self.schema])
}

/**
 * Every name this object's REGISTER can legitimately be known by.
 *
 * @param {object} object The OR object being viewed.
 * @param {object} [context] The injected `cnObjectContext` (ref or plain).
 * @return {Array<string>} Candidate register keys.
 */
export function objectRegisterKeys(object, context = null) {
	const obj = object || {}
	const self = obj['@self'] || {}
	const ctx = unref(context) || {}
	return keySet([ctx.register, obj.register, self.registerSlug, self.register])
}

/**
 * Does a manifest entry's value name the same thing as one of these keys?
 *
 * An entry that declares NO value is not a wildcard — it simply does not match,
 * so a malformed manifest entry cannot silently attach itself to every object.
 *
 * @param {*} value The manifest entry's `register` / `schema` value.
 * @param {Array<string>} keys Candidate keys from {@link objectSchemaKeys}.
 * @return {boolean} True when the entry names this object's register/schema.
 */
export function matchesKey(value, keys) {
	if (value === undefined || value === null || value === '') {
		return false
	}
	return keys.includes(String(value))
}
