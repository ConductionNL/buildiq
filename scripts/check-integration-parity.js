#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App-side integration parity gate (hydra gate-24, `integration-parity`).
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * A leaf has TWO faces (ADR-019 AD-11/AD-13, ADR-066 decisions 4 and 7):
 *
 *   a SERVER face — either a `LeafDescriptor` contributed through
 *     `RegisterLeafProvidersEvent`, or an `IntegrationProvider` registered on
 *     OpenRegister's `IntegrationRegistry`. This face is what the
 *     `openregister.integrations.leaves` capability advertises.
 *   a JS face — a `registerIntegration({ id, … })` call that mounts the
 *     render pair on `window.OCA.OpenRegister.integrations`.
 *
 * The two are correlated ONLY by a shared `id`. Nothing at runtime notices when
 * one half is missing or when the two halves disagree: a descriptor with no JS
 * registration advertises a tab that renders nothing (a PHANTOM render
 * surface), and a JS registration with no server face mounts a widget that no
 * capability consumer can discover (an ORPHAN registration). A renderMode
 * disagreement is worse still — the host hands a `mount` leaf an SFC slot, or
 * asks a `component` leaf for a `mount()` it does not have, and the surface is
 * blank with no error.
 *
 * The canonical Node check that ships in `@conduction/nextcloud-vue`
 * (`scripts/check-integration-parity.js`) validates THAT library's own built-in
 * descriptors, and its ADR-066 cross-reference over `process.cwd()` is
 * WARN-only and self-disables in a repo with no `new LeafDescriptor(` at all.
 * Neither property is usable as an app-side gate: the library's `scripts/` dir
 * is not published to npm, so in CI the resolution fails and the historic
 * wrapper exits 0 having checked nothing. This check is therefore SELF-CONTAINED
 * — it reads only this repo's own sources, needs no node_modules, and FAILS.
 *
 * RULES (all hard; any violation exits 1)
 * ---------------------------------------
 *   R1  render pair       a JS registration declaring its own id must ship a
 *                         COMPLETE render pair for its renderMode:
 *                         `mount` + `unmount` for renderMode 'mount',
 *                         `tab` + `widget` for 'component' (the default).
 *   R2  id correlation    every server-side render-surface leaf id has a JS
 *                         registration, and every JS registration id has a
 *                         server-side face (descriptor OR provider).
 *   R3  renderMode        for a shared id, both faces declare the same
 *                         renderMode.
 *   R4  metadata          for a shared id, every field BOTH faces declare
 *                         statically (label, icon, group, requiredApp,
 *                         referenceType, surfaces) agrees.
 *   R5  spread source     a registration that inherits its identity by
 *                         spreading a descriptor (`...fooIntegration`) must
 *                         import that symbol from `@conduction/nextcloud-vue`
 *                         — the owning package. A spread of a local or
 *                         undefined binding registers a leaf with no id.
 *   R6  offlineConfig     an `offlineConfig` naming schemas/properties must
 *                         name ones this repo actually declares in its
 *                         OpenRegister register/schema JSON under
 *                         `lib/Settings/**`.
 *   R7  provider binding  a `KIND_DATA_PROVIDER` leaf must be registered WITH
 *                         a provider, not `null` — the mirror of R2, and the
 *                         only rule with subject matter in a repo whose
 *                         leaves are all data providers.
 *
 * A rule with no subject matter in this repo reports "0 checked" by name, so
 * the difference between "verified" and "there was nothing to verify" is
 * visible in the output rather than inferred from silence.
 *
 * Exit codes:
 *   0 — every rule that had subject matter passed
 *   1 — at least one parity violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * Constants owned by OpenRegister's `LeafDescriptor`, which is not in this
 * repo's tree. Resolving them by name is the only way a static reader can
 * follow `renderMode: LeafDescriptor::RENDER_MODE_MOUNT`.
 *
 * @type {Object<string, string>}
 */
const FOREIGN_CONSTANTS = {
	'LeafDescriptor::RENDER_MODE_MOUNT': 'mount',
	'LeafDescriptor::RENDER_MODE_COMPONENT': 'component',
	'LeafDescriptor::KIND_RENDER_SURFACE': 'render-surface',
	'LeafDescriptor::KIND_DATA_PROVIDER': 'data-provider',
	'LeafDescriptor::KIND_AGENT_RUNNER': 'agent-runner',
}

/**
 * `offlineConfig` keys that name an OpenRegister schema slug, and those that
 * name a property on the planned-item schema. Mirrors
 * `DEFAULT_FIELD_INSPECTION_CONFIG` in `@conduction/nextcloud-vue`.
 *
 * @type {{schemas: string[], plannedFields: string[]}}
 */
const OFFLINE_CONFIG_CONTRACT = {
	schemas: ['plannedSchema', 'referenceSchema', 'resultSchema'],
	plannedFields: ['templateRefField', 'assigneeField', 'dateField', 'titleField'],
}

/**
 * Recursively collect files under `root` matching `test(filename)`.
 *
 * @param {string} root Directory to walk.
 * @param {(name: string) => boolean} test Filename predicate.
 * @param {number} [maxDepth] Maximum recursion depth.
 *
 * @return {string[]} Absolute paths of matching files.
 */
function collectFiles(root, test, maxDepth = 10) {
	const out = []
	if (fs.existsSync(root) === false) {
		return out
	}
	const skip = new Set([
		'node_modules',
		'vendor',
		'.git',
		'dist',
		'build',
		'js',
		'coverage',
	])
	const visit = (ent, dir) => {
		const full = path.join(dir, ent.name)
		if (ent.isDirectory() === true) {
			return skip.has(ent.name) === false ? full : null
		}
		if (ent.isFile() === true && test(ent.name) === true) {
			out.push(full)
		}
		return null
	}
	const walk = (dir, depth) => {
		if (depth > maxDepth) {
			return
		}
		for (const ent of directoryEntries(dir)) {
			const sub = visit(ent, dir)
			if (sub !== null) {
				walk(sub, depth + 1)
			}
		}
	}
	walk(root, 0)
	return out
}

/**
 * The entries of a directory, or none when it cannot be read.
 *
 * @param {string} dir Directory to read.
 *
 * @return {fs.Dirent[]} Its entries.
 */
function directoryEntries(dir) {
	try {
		return fs.readdirSync(dir, { withFileTypes: true })
	} catch {
		return []
	}
}

/**
 * The closing delimiter for each opening one.
 *
 * @type {Object<string, string>}
 */
const CLOSING = { '(': ')', '{': '}', '[': ']' }

/**
 * Characters that open a string literal.
 *
 * @type {string[]}
 */
const QUOTES = ["'", '"', '`']

/**
 * Advance the scan's string-literal state over `text[i]`.
 *
 * @param {object} scan The scan state (text, hash, quote, opens, closes).
 * @param {number} i The index.
 *
 * @return {boolean} True when `text[i]` belongs to a string literal, its quotes included.
 */
function inQuote(scan, i) {
	if (scan.quote !== null) {
		endQuoteAt(scan, i)
		return true
	}
	if (QUOTES.includes(scan.text[i]) === true) {
		scan.quote = scan.text[i]
		return true
	}
	return false
}

/**
 * Close the open string literal when `text[i]` is its unescaped quote.
 *
 * @param {object} scan The scan state.
 * @param {number} i The index.
 *
 * @return {void}
 */
function endQuoteAt(scan, i) {
	if (scan.text[i] === scan.quote && scan.text[i - 1] !== '\\') {
		scan.quote = null
	}
}

/**
 * Where to resume when a comment starts at `text[i]`: the newline ending a
 * line comment, the `/` closing a block comment, or the end of the text.
 *
 * @param {object} scan The scan state; `hash` makes `#` start a line comment.
 * @param {number} i The index.
 *
 * @return {number} The index to resume from, -1 when no comment starts here.
 */
function commentEnd(scan, i) {
	const two = scan.text.slice(i, i + 2)
	if (two === '//' || (scan.hash === true && scan.text[i] === '#')) {
		const nl = scan.text.indexOf('\n', i)
		return nl === -1 ? scan.text.length : nl
	}
	if (two === '/*') {
		const end = scan.text.indexOf('*/', i)
		return end === -1 ? scan.text.length : end + 1
	}
	return -1
}

/**
 * How a code character changes the nesting depth.
 *
 * @param {object} scan The scan state; `opens` and `closes` list the delimiters.
 * @param {string} c The character.
 *
 * @return {number} 1, -1 or 0.
 */
function depthStep(scan, c) {
	if (scan.opens.includes(c) === true) {
		return 1
	}
	return scan.closes.includes(c) === true ? -1 : 0
}

/**
 * Extract the balanced text that follows `open` at `src[from]`, honouring
 * string literals and comments so a brace inside a string never unbalances it.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {string} The text between the delimiters (exclusive), '' when unbalanced.
 */
function balanced(src, from) {
	const scan = bracketScan(src, from)
	let depth = 0
	for (let i = from; i < src.length; i++) {
		const skip = skipNonCode(scan, i)
		if (skip !== -1) {
			i = skip
			continue
		}
		const step = depthStep(scan, src[i])
		depth += step
		if (step === -1 && depth === 0) {
			return src.slice(from + 1, i)
		}
	}
	return ''
}

/**
 * Where to resume when `text[i]` is not code: `i` itself inside a string
 * literal, the comment's end when one starts here.
 *
 * @param {object} scan The scan state.
 * @param {number} i The index.
 *
 * @return {number} The index to resume from, -1 when `text[i]` is code.
 */
function skipNonCode(scan, i) {
	return inQuote(scan, i) === true ? i : commentEnd(scan, i)
}

/**
 * The scan state for `balanced`: only the delimiter at `src[from]` and its
 * closing one nest, and `#` starts a comment only inside parentheses.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {object} The scan state.
 */
function bracketScan(src, from) {
	const open = src[from]
	const close = CLOSING[open] || ''
	return { text: src, hash: open === '(', quote: null, opens: open, closes: close }
}

/**
 * Split an argument/member list on top-level commas.
 *
 * @param {string} body The text between the delimiters.
 *
 * @return {string[]} Trimmed top-level members.
 */
function splitTopLevel(body) {
	const scan = { text: body, hash: true, quote: null, opens: '([{', closes: ')]}' }
	const split = { depth: 0, current: '', parts: [] }
	for (let i = 0; i < body.length; i++) {
		if (inQuote(scan, i) === true) {
			split.current += body[i]
			continue
		}
		const skip = commentEnd(scan, i)
		if (skip !== -1) {
			i = skip
			continue
		}
		split.depth += depthStep(scan, body[i])
		addCodeChar(split, body[i])
	}
	return finishSplit(split)
}

/**
 * The named arguments of the `new LeafDescriptor(` call whose `(` is at
 * `open`, with its value objects read through: `identity: new LeafIdentity(…)`,
 * `availability: new LeafAvailability(…)` and `rendering: new LeafRendering(…)`
 * contribute their own named arguments, so the grouped form and the flat
 * eleven-argument form yield the same fields.
 *
 * @param {string} src The file text.
 * @param {number} open Index of the call's opening parenthesis.
 *
 * @return {string[]} The top-level arguments, value objects expanded.
 */
function leafDescriptorArgs(src, open) {
	const out = []
	for (const arg of splitTopLevel(balanced(src, open))) {
		const group = /^\s*(?:identity|availability|rendering)\s*:\s*new\s+Leaf(?:Identity|Availability|Rendering)\s*\(/.exec(arg)
		if (group === null) {
			out.push(arg)
			continue
		}
		const inner = arg.indexOf('(', group.index + group[0].length - 1)
		out.push(...splitTopLevel(balanced(arg, inner)).map((a) => a.trim()))
	}
	return out
}

/**
 * The split's parts, with the last one added when it is not blank.
 *
 * @param {{depth: number, current: string, parts: string[]}} split The split state.
 *
 * @return {string[]} Trimmed top-level members.
 */
function finishSplit(split) {
	if (split.current.trim() !== '') {
		split.parts.push(split.current.trim())
	}
	return split.parts
}

/**
 * Add one code character to a split: a top-level comma ends the current part.
 *
 * @param {{depth: number, current: string, parts: string[]}} split The split state.
 * @param {string} c The character.
 *
 * @return {void}
 */
function addCodeChar(split, c) {
	if (c === ',' && split.depth === 0) {
		split.parts.push(split.current.trim())
		split.current = ''
		return
	}
	split.current += c
}

/**
 * Unescape the quotes inside a string literal's body.
 *
 * @param {string} body The text between the quotes.
 *
 * @return {string} The string value.
 */
function unescapeQuotes(body) {
	return body.replace(/\\'/g, "'").replace(/\\"/g, '"')
}

/**
 * Resolve a `Class::CONST` reference: a known foreign constant first, then
 * the repo's own class constants.
 *
 * @param {string} key The `Class::CONST` text.
 * @param {Object<string, (string|string[])>} globalConsts `Class::CONST` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function classConstant(key, globalConsts) {
	if (Object.hasOwn(FOREIGN_CONSTANTS, key) === true) {
		return FOREIGN_CONSTANTS[key]
	}
	return Object.hasOwn(globalConsts, key) ? globalConsts[key] : null
}

/**
 * The PHP expression shapes that resolve statically, in the order they are
 * tried. Each rule's `value` reads its match and the `{local, global}` const
 * tables. The translated literal of `$this->l10n->t('X')` (or
 * `$this->l->t('X', […])`) is taken as is.
 *
 * @type {Array<{pattern: RegExp, value: function(Array, object): (string|string[]|null)}>}
 */
const PHP_RULES = [
	{ pattern: /^'((?:[^'\\]|\\.)*)'$/, value: (m) => unescapeQuotes(m[1]) },
	{ pattern: /^"((?:[^"\\]|\\.)*)"$/, value: (m) => unescapeQuotes(m[1]) },
	{
		pattern: /^\$this->[A-Za-z0-9_]+->t\(\s*'((?:[^'\\]|\\.)*)'/,
		value: (m) => m[1],
	},
	{
		pattern: /^\$this->[A-Za-z0-9_]+->t\(\s*"((?:[^"\\]|\\.)*)"/,
		value: (m) => m[1],
	},
	{
		pattern: /^(?:self|static)::([A-Z0-9_]+)$/,
		value: (m, consts) =>
			Object.hasOwn(consts.local, m[1]) ? consts.local[m[1]] : null,
	},
	{
		pattern: /^([A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)$/,
		value: (m, consts) => classConstant(`${m[1]}::${m[2]}`, consts.global),
	},
]

/**
 * Resolve a PHP expression to a string or array of strings, or null when it is
 * not statically knowable (which is never a failure — unknown values are simply
 * not compared).
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} localConsts Same-file const table.
 * @param {Object<string, (string|string[])>} globalConsts `Class::CONST` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolvePhp(expr, localConsts, globalConsts) {
	return resolveExpression(expr.trim(), {
		rules: PHP_RULES,
		consts: { local: localConsts, global: globalConsts },
		member: (member) => resolvePhp(member, localConsts, globalConsts),
	})
}

/**
 * Resolve a trimmed expression with the first matching rule, or as a list
 * when it opens with `[`.
 *
 * @param {string} e The trimmed expression.
 * @param {{rules: Array, consts: object, member: function(string): (string|string[]|null)}} how The rules, their const tables and the member resolver.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolveExpression(e, how) {
	for (const rule of how.rules) {
		const m = rule.pattern.exec(e)
		if (m !== null) {
			return rule.value(m, how.consts)
		}
	}
	return e.startsWith('[') === true ? resolveList(e, how.member) : null
}

/**
 * Resolve a `[…]` list whose members all resolve to strings.
 *
 * @param {string} e The list expression, opening with `[`.
 * @param {function(string): (string|string[]|null)} resolveMember Resolves one member.
 *
 * @return {string[]|null} The member values, null when one is not a string.
 */
function resolveList(e, resolveMember) {
	const out = []
	for (const member of splitTopLevel(balanced(e, 0))) {
		const v = resolveMember(member)
		if (typeof v !== 'string') {
			return null
		}
		out.push(v)
	}
	return out
}

/**
 * Build the same-file `const NAME = …` table for a PHP source.
 *
 * @param {string} src PHP source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function phpLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const semi = src.indexOf(';', start)
			expr = semi === -1 ? '' : src.slice(start, semi)
		}
		const v = resolvePhp(expr, table, {})
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * Read a file, or null when it cannot be read.
 *
 * @param {string} file Absolute path.
 *
 * @return {string|null} The file text.
 */
function readSource(file) {
	try {
		return fs.readFileSync(file, 'utf8')
	} catch {
		return null
	}
}

/**
 * Read every PHP file and build the global `Class::CONST` table, so
 * `Application::APP_ID` resolves.
 *
 * @param {string[]} phpFiles Absolute paths.
 *
 * @return {{sources: Map<string, string>, globalConsts: Object<string, (string|string[])>}}
 *   The readable sources and the constant table.
 */
function readPhpSources(phpFiles) {
	const globalConsts = {}
	const sources = new Map()
	for (const file of phpFiles) {
		const src = readSource(file)
		if (src === null) {
			continue
		}
		sources.set(file, src)
		const cls =
			/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/.exec(src)
		if (cls === null) {
			continue
		}
		for (const [name, value] of Object.entries(phpLocalConsts(src))) {
			globalConsts[`${cls[1]}::${name}`] = value
		}
	}
	return { sources, globalConsts }
}

/**
 * The string value of `fields[name]`, or null.
 *
 * @param {object} fields Statically resolved fields.
 * @param {string} name Field name.
 *
 * @return {string|null} The value when it is a string.
 */
function stringField(fields, name) {
	return typeof fields[name] === 'string' ? fields[name] : null
}

/**
 * Which provider the descriptor at `at` was registered WITH.
 *
 * The collect event takes the descriptor and its provider as two arguments:
 * `$event->registerLeaf(<descriptor>, $this->forms)`, and `null` there means
 * "this leaf answers nothing server-side": correct for a render surface, and
 * a dark leaf for a data provider. Scanning back to the enclosing call is
 * bounded: a descriptor lives inside its own registerLeaf argument list.
 *
 * @param {string} src The file text.
 * @param {number} at Index where the descriptor's `new` starts.
 *
 * @return {boolean|null} True when bound, false for `null`, null when unknown.
 */
function providerBinding(src, at) {
	const callStart = src.lastIndexOf('registerLeaf(', at)
	if (callStart === -1 || at - callStart >= 2000) {
		return null
	}
	const callArgs = splitTopLevel(balanced(src, src.indexOf('(', callStart)))
	return callArgs.length >= 2 ? callArgs[1].trim() !== 'null' : null
}

/**
 * Parse one `new LeafDescriptor(…)` call's named arguments into a face.
 *
 * @param {object} source The file: rel, src, locals and globalConsts.
 * @param {number} at Index where the call's `new` starts.
 *
 * @return {object} The LeafDescriptor face.
 */
function descriptorFace(source, at) {
	const open = source.src.indexOf('(', at)
	const face = { kind: 'LeafDescriptor', file: source.rel, fields: {} }
	for (const arg of leafDescriptorArgs(source.src, open)) {
		const m = /^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([\s\S]+)$/.exec(arg)
		const value =
			m === null ? null : resolvePhp(m[2], source.locals, source.globalConsts)
		if (value !== null) {
			face.fields[m[1]] = value
		}
	}
	face.id = stringField(face.fields, 'id')
	const kinds = Array.isArray(face.fields.kinds) ? face.fields.kinds : []
	face.renderSurface = kinds.includes('render-surface')
	face.dataProvider = kinds.includes('data-provider')
	face.providerBound = providerBinding(source.src, at)
	face.renderMode = stringField(face.fields, 'renderMode')
	return face
}

/**
 * Every `new LeafDescriptor(` face in one file.
 *
 * @param {object} source The file: rel, src, locals and globalConsts.
 *
 * @return {Array<object>} The faces, in source order.
 */
function descriptorFaces(source) {
	const faces = []
	let idx = 0
	while ((idx = source.src.indexOf('new LeafDescriptor(', idx)) !== -1) {
		faces.push(descriptorFace(source, idx))
		idx = source.src.indexOf('(', idx) + 1
	}
	return faces
}

/**
 * Provider getters whose literal return value is a face field.
 *
 * @type {Object<string, string>}
 */
const PROVIDER_GETTERS = {
	getId: 'id',
	getLabel: 'label',
	getIcon: 'icon',
	getGroup: 'group',
	getRequiredApp: 'requiredApp',
	getReferenceType: 'referenceType',
}

/**
 * The statically resolved return value of one getter, or null.
 *
 * @param {object} source The file: rel, src, locals and globalConsts.
 * @param {string} method The getter name.
 *
 * @return {string|string[]|null} The returned value.
 */
function getterValue(source, method) {
	const sig = new RegExp(`function\\s+${method}\\s*\\([^)]*\\)[^{;]*\\{`).exec(
		source.src,
	)
	if (sig === null) {
		return null
	}
	const body = balanced(source.src, sig.index + sig[0].length - 1)
	const ret = /\breturn\s+([^;]+);/.exec(body)
	return ret === null
		? null
		: resolvePhp(ret[1], source.locals, source.globalConsts)
}

/**
 * The IntegrationProvider face of one file, or null when the file is not a
 * provider or its `getId()` is not a literal.
 *
 * @param {object} source The file: rel, src, locals and globalConsts.
 *
 * @return {object|null} The provider face.
 */
function providerFace(source) {
	if (isProviderSource(source.src) === false) {
		return null
	}
	const face = { kind: 'IntegrationProvider', file: source.rel, fields: {} }
	for (const [method, field] of Object.entries(PROVIDER_GETTERS)) {
		const value = getterValue(source, method)
		if (value !== null) {
			face.fields[field] = value
		}
	}
	if (typeof face.fields.id !== 'string') {
		return null
	}
	face.id = face.fields.id
	// A provider is a data face, not a render surface: it is discoverable
	// through the capability and therefore MUST have a JS registration,
	// but it does not itself declare a render pair.
	face.renderSurface = false
	face.renderMode = stringField(face.fields, 'renderMode')
	return face
}

/**
 * Whether a PHP file declares an IntegrationProvider.
 *
 * @param {string} src The file's source.
 *
 * @return {boolean} True for a provider.
 */
function isProviderSource(src) {
	return (
		/extends\s+AbstractIntegrationProvider\b/.test(src)
		|| /implements\s+[^{]*\bIntegrationProviderInterface\b/.test(src)
	)
}

/**
 * Collect the server-side leaf faces declared in this repo's PHP.
 *
 * Two shapes count as a server face, because both are how a leaf reaches the
 * `openregister.integrations.leaves` capability:
 *   `new LeafDescriptor(…)` — the ADR-066 collect-event contribution;
 *   an `IntegrationProvider` (extends `AbstractIntegrationProvider` or
 *     implements `IntegrationProviderInterface`) whose `getId()` returns a
 *     literal — the `IntegrationRegistry::addProvider()` path.
 *
 * @return {Array<object>} The discovered server faces.
 */
function collectServerFaces() {
	const faces = []
	const phpFiles = collectFiles(path.join(REPO_ROOT, 'lib'), (n) =>
		n.endsWith('.php'),
	)
	const { sources, globalConsts } = readPhpSources(phpFiles)
	for (const [file, src] of sources) {
		const source = {
			rel: path.relative(REPO_ROOT, file),
			src,
			locals: phpLocalConsts(src),
			globalConsts,
		}
		faces.push(...descriptorFaces(source))
		const provider = providerFace(source)
		if (provider !== null) {
			faces.push(provider)
		}
	}
	return faces
}

/**
 * The JS expression shapes that resolve statically, in the order they are
 * tried: string and plain template literals, the translated literal of
 * `t('appid', 'Label')` / `t('Label')`, and a same-file constant name.
 *
 * @type {Array<{pattern: RegExp, value: function(Array, object): (string|string[]|null)}>}
 */
const JS_RULES = [
	{ pattern: /^'((?:[^'\\]|\\.)*)'$/, value: (m) => unescapeQuotes(m[1]) },
	{ pattern: /^"((?:[^"\\]|\\.)*)"$/, value: (m) => unescapeQuotes(m[1]) },
	{ pattern: /^`([^`$]*)`$/, value: (m) => unescapeQuotes(m[1]) },
	{ pattern: /^t\(\s*'[^']*'\s*,\s*'((?:[^'\\]|\\.)*)'/, value: (m) => m[1] },
	{ pattern: /^t\(\s*'((?:[^'\\]|\\.)*)'\s*\)/, value: (m) => m[1] },
	{
		pattern: /^[A-Za-z_$][A-Za-z0-9_$]*$/,
		value: (m, locals) =>
			Object.hasOwn(locals, m[0]) === true ? locals[m[0]] : null,
	},
]

/**
 * Resolve a JS expression to a string or array of strings, or null when it is
 * not statically knowable.
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolveJs(expr, locals) {
	return resolveExpression(expr.trim(), {
		rules: JS_RULES,
		consts: locals,
		member: (member) => resolveJs(member, locals),
	})
}

/**
 * Build the same-file `const NAME = { … }` table, keyed to the OBJECT LITERAL
 * TEXT rather than a resolved scalar.
 *
 * `jsLocalConsts` below resolves constants to VALUES, and for a `{` it only
 * reads to end-of-line, so a multi-line descriptor object never lands in its
 * table. That is fine for `id: SOME_CONST`, but it cannot serve a registration
 * whose whole descriptor is passed by name:
 *
 *   export const decisionsLeafDescriptor = { id: …, renderMode: 'mount', … }
 *   …
 *   OCA.OpenRegister.integrations.register(decisionsLeafDescriptor)
 *
 * The member parser in `collectJsRegistrations` needs the literal's TEXT, so
 * this table keeps the balanced `{ … }` body verbatim for that one purpose.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, string>} Constant name to object-literal inner text.
 */
function jsLocalObjectLiterals(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\{/g
	let m
	while ((m = re.exec(src)) !== null) {
		const brace = m.index + m[0].length - 1
		table[m[1]] = balanced(src, brace)
	}
	return table
}

/**
 * Build the same-file `const NAME = …` table for a JS/Vue source.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function jsLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const nl = src.indexOf('\n', start)
			expr =
				nl === -1
					? src.slice(start)
					: src.slice(start, nl).replace(/,\s*$/, '')
		}
		const v = resolveJs(expr, table)
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * The key a key/value regex match captured: single-quoted, double-quoted or bare.
 *
 * @param {Array<string>} match The match with the key in groups 1 to 3.
 *
 * @return {string} The key.
 */
function keyOf(match) {
	return match[1] || match[2] || match[3]
}

/**
 * Whether a member value is an inline object literal.
 *
 * @param {string|undefined} raw The value text, undefined for a shorthand.
 *
 * @return {boolean} True for `{ … }`.
 */
function isObjectLiteral(raw) {
	return raw !== undefined && raw.trim().startsWith('{') === true
}

/**
 * Parse an inline `offlineConfig: { … }` into its string-valued entries.
 *
 * @param {string} text The object literal text.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {Object<string, string>} Config key to resolved string.
 */
function parseOfflineConfig(text, locals) {
	const config = {}
	for (const cfg of splitTopLevel(balanced(text, 0))) {
		const ckv =
			/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*:\s*([\s\S]+)$/.exec(
				cfg,
			)
		const cvalue = ckv === null ? null : resolveJs(ckv[4], locals)
		if (typeof cvalue === 'string') {
			config[keyOf(ckv)] = cvalue
		}
	}
	return config
}

/**
 * Record one `key: value` (or shorthand) member on a registration.
 *
 * @param {object} reg The registration being built.
 * @param {{key: string, raw: (string|undefined), locals: object}} member The key,
 *   its value text, and the same-file `const` table.
 *
 * @return {void}
 */
function addField(reg, member) {
	const { key, raw, locals } = member
	reg.keys.push(key)
	// A shorthand (`mount,`) carries the key without a value.
	const value = raw === undefined ? null : resolveJs(raw, locals)
	if (value !== null) {
		reg.fields[key] = value
	}
	if (key === 'offlineConfig' && isObjectLiteral(raw) === true) {
		reg.offlineConfig = parseOfflineConfig(raw.trim(), locals)
	}
}

/**
 * Record one top-level member of a registration literal: a spread or a field.
 *
 * @param {object} reg The registration being built.
 * @param {{member: string, locals: object}} item The member text and the
 *   same-file `const` table.
 *
 * @return {void}
 */
function addMember(reg, item) {
	const spread = /^\.\.\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/.exec(item.member)
	if (spread !== null) {
		reg.spreads.push(spread[1])
		return
	}
	const kv =
		/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*(?::\s*([\s\S]+))?$/.exec(
			item.member,
		)
	if (kv !== null) {
		addField(reg, { key: keyOf(kv), raw: kv[4], locals: item.locals })
	}
}

/**
 * Build a registration from its object-literal argument text.
 *
 * @param {object} source The file: rel, src, locals and objects.
 * @param {string} argText The `{ … }` argument text.
 *
 * @return {object} The registration.
 */
function parseRegistration(source, argText) {
	const reg = { file: source.rel, fields: {}, keys: [], spreads: [] }
	for (const member of splitTopLevel(balanced(argText, 0))) {
		addMember(reg, { member, locals: source.locals })
	}
	reg.id = typeof reg.fields.id === 'string' ? reg.fields.id : null
	reg.renderMode =
		typeof reg.fields.renderMode === 'string' ? reg.fields.renderMode : null
	// Import source per spread symbol, for R5.
	reg.spreadSources = {}
	for (const symbol of reg.spreads) {
		reg.spreadSources[symbol] = importSourceOf(source.src, symbol)
	}
	return reg
}

/**
 * The object-literal argument text of the registration call at `at`, or null
 * when the call is a declaration or its argument cannot be resolved.
 *
 * @param {object} source The file: rel, src, locals and objects.
 * @param {number} at Index where the call's callee starts.
 *
 * @return {string|null} The `{ … }` argument text.
 */
function registrationArgument(source, at) {
	const { src, objects } = source
	const before = src.slice(Math.max(0, at - 20), at)
	if (/function\s+$/.test(before) === true) {
		return null // the library's own `export function registerIntegration(`
	}
	const argText = balanced(src, src.indexOf('(', at)).trim()
	if (argText.startsWith('{') === true) {
		return argText
	}
	// A descriptor passed BY NAME is the same registration as an
	// inline literal; resolve it to the literal's text or skip.
	const ident = /^([A-Za-z_$][A-Za-z0-9_$]*)$/.exec(argText)
	if (ident === null || objects[ident[1]] === undefined) {
		return null
	}
	return '{' + objects[ident[1]] + '}'
}

/**
 * Every registration call in one source file.
 *
 * @param {object} source The file: rel, src, locals and objects.
 *
 * @return {Array<object>} The registrations, in source order.
 */
function registrationsIn(source) {
	const regs = []
	// `\s*\(` anchors both alternatives to a CALL, so neither
	// `registerIntegrationIcons(` nor `installIntegrationRegistry(` matches.
	const re = /(?:\bregisterIntegration|\bintegrations\s*\.\s*register)\s*\(/g
	let m
	while ((m = re.exec(source.src)) !== null) {
		const argText = registrationArgument(source, m.index)
		if (argText !== null) {
			regs.push(parseRegistration(source, argText))
		}
	}
	return regs
}

/**
 * Collect the JS `registerIntegration({ … })` call sites in `src/**`.
 *
 * @return {Array<object>} The discovered registrations.
 */
function collectJsRegistrations() {
	const regs = []
	const jsFiles = collectFiles(
		path.join(REPO_ROOT, 'src'),
		(n) => n.endsWith('.js') || n.endsWith('.ts') || n.endsWith('.vue'),
	)
	for (const file of jsFiles) {
		const src = readSource(file)
		// BOTH SUPPORTED REGISTRATION APIs, NOT ONE.
		//
		// `registerIntegration(descriptor)` is the convenience wrapper exported
		// from @conduction/nextcloud-vue. The registry object it wraps is
		// equally canonical and is called directly as
		// `OCA.OpenRegister.integrations.register(descriptor)` — including by
		// nextcloud-vue's OWN built-in leaves. Matching only the wrapper made
		// this checker report ZERO registrations for a repo that plainly has
		// one, which the wrapper script then correctly refused to call a pass.
		// gate-24's own selector probe was fixed for exactly this and reads
		// both forms; this checker was a generation behind it.
		if (
			src === null
			|| (src.includes('registerIntegration') === false
				&& src.includes('integrations.register') === false)
		) {
			continue
		}
		regs.push(
			...registrationsIn({
				rel: path.relative(REPO_ROOT, file),
				src,
				locals: jsLocalConsts(src),
				objects: jsLocalObjectLiterals(src),
			}),
		)
	}
	return regs
}

/**
 * Find the module a symbol is imported from.
 *
 * @param {string} src JS source text.
 * @param {string} symbol The imported binding name.
 *
 * @return {string|null} The module specifier, or null when not imported.
 */
function importSourceOf(src, symbol) {
	const re = /import\s+([\s\S]*?)\s+from\s+'([^']+)'/g
	let m
	while ((m = re.exec(src)) !== null) {
		const clause = m[1]
		const named =
			clause.indexOf('{') === -1 ? '' : balanced(clause, clause.indexOf('{'))
		const bindings = splitTopLevel(named).map((b) =>
			b
				.split(/\s+as\s+/)
				.pop()
				.trim(),
		)
		const defaultBinding = clause
			.replace(/\{[\s\S]*\}/, '')
			.replace(/,/g, '')
			.trim()
		if (bindings.includes(symbol) === true || defaultBinding === symbol) {
			return m[2]
		}
	}
	return null
}

/**
 * Collect the schema slugs and their property names from this repo's
 * OpenRegister register/schema declarations under `lib/Settings/**`.
 *
 * @return {{schemas: Object<string, string[]>, files: number}} Slug to property
 *   names, and how many declaration files were read.
 */
function collectOrSchemas() {
	const schemas = {}
	let files = 0
	for (const file of collectFiles(path.join(REPO_ROOT, 'lib', 'Settings'), (n) =>
		n.endsWith('.json'),
	)) {
		const declared = declaredSchemas(file)
		if (declared !== null) {
			files++
			addSchemaProperties(schemas, declared)
		}
	}
	return { schemas, files }
}

/**
 * The `components.schemas` block of one declaration file, or null when the
 * file does not parse or declares none.
 *
 * @param {string} file Absolute path of a `lib/Settings` JSON file.
 *
 * @return {object|null} The declared schemas, keyed as in the file.
 */
function declaredSchemas(file) {
	const doc = readJson(file)
	const declared = doc && doc.components && doc.components.schemas
	return typeof declared === 'object' && declared !== null ? declared : null
}

/**
 * A JSON file's document, or null when it cannot be read or parsed.
 *
 * @param {string} file Absolute path of the file.
 *
 * @return {unknown} The parsed document.
 */
function readJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch {
		return null
	}
}

/**
 * Add the property names of each declared schema under its slug (the
 * schema's own `slug`, or its key).
 *
 * @param {Object<string, string[]>} schemas Slug to property names, extended in place.
 * @param {object} declared One file's `components.schemas` block.
 *
 * @return {void}
 */
function addSchemaProperties(schemas, declared) {
	for (const [key, schema] of Object.entries(declared)) {
		const slug = schema && typeof schema.slug === 'string' ? schema.slug : key
		const props =
			schema && schema.properties && typeof schema.properties === 'object'
				? Object.keys(schema.properties)
				: []
		schemas[slug] = (schemas[slug] || []).concat(props)
	}
}

/**
 * Compare two statically-resolved values for parity.
 *
 * @param {string|string[]} a Left value.
 * @param {string|string[]} b Right value.
 *
 * @return {boolean} True when equal (arrays compare as sets).
 */
function sameValue(a, b) {
	if (Array.isArray(a) === true && Array.isArray(b) === true) {
		const left = [...a].sort()
		const right = [...b].sort()
		return left.length === right.length && left.every((v, i) => v === right[i])
	}
	return a === b
}

/**
 * Index items that carry a statically-read id by that id.
 *
 * @param {Array<object>} items Server faces or JS registrations.
 *
 * @return {Map<string, object>} Id to item; items without an id are left out.
 */
function indexById(items) {
	const map = new Map()
	for (const item of items) {
		if (item.id !== null) {
			map.set(item.id, item)
		}
	}
	return map
}

/**
 * Read both faces and the schema declarations into one rule context.
 *
 * @return {object} The faces, registrations, schemas, their indexes, and the
 *   failures and per-rule counts the rules fill in.
 */
function buildContext() {
	const faces = collectServerFaces()
	const regs = collectJsRegistrations()
	const { schemas, files: schemaFiles } = collectOrSchemas()
	return {
		faces,
		regs,
		schemas,
		schemaFiles,
		jsById: indexById(regs),
		faceById: indexById(faces),
		failures: [],
		counts: {},
	}
}

/**
 * R1: a JS registration with its own id ships the render pair of its renderMode.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkRenderPairs(ctx) {
	ctx.counts.R1 = 0
	for (const r of ctx.regs) {
		if (r.id === null) {
			continue // identity inherited from a spread — covered by R5
		}
		ctx.counts.R1++
		const { mode, required } = renderPairOf(r)
		for (const key of required.filter((k) => r.keys.includes(k) === false)) {
			ctx.failures.push(
				`✗ [R1 render-pair] registerIntegration id "${r.id}" (${r.file}) declares `
					+ `renderMode "${mode}" but is missing the required \`${key}\` key — an `
					+ `incomplete render pair renders nothing on its surface (ADR-019 AD-11/AD-13, `
					+ `ADR-066 decision 7).`,
			)
		}
	}
}

/**
 * The renderMode of a registration (`component` when it declares none) and the
 * keys its render pair needs.
 *
 * @param {object} r The JS registration.
 *
 * @return {{mode: string, required: string[]}} The mode and its keys.
 */
function renderPairOf(r) {
	const mode = r.renderMode === null ? 'component' : r.renderMode
	return {
		mode,
		required: mode === 'mount' ? ['mount', 'unmount'] : ['tab', 'widget'],
	}
}

/**
 * R2, server half: every server face has a readable id with a JS registration.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkServerIds(ctx) {
	ctx.counts.R2 = 0
	for (const f of ctx.faces) {
		if (f.id === null) {
			ctx.failures.push(
				`✗ [R2 id-correlation] a ${f.kind} in ${f.file} declares no statically-readable `
					+ `\`id\` — a leaf whose id cannot be read cannot be correlated with its JS half.`,
			)
			continue
		}
		// Only a RENDER SURFACE needs a JS half, which is what this rule says
		// at the top of this file and what ADR-066 decision 4 is about:
		// phantom render surfaces. A KIND_DATA_PROVIDER leaf answers a server
		// question and mounts nothing, so demanding a `registerIntegration`
		// for it asks a data provider to have a user interface.
		//
		// This is not hypothetical strictness. The rule was written for render
		// surfaces and implemented over every face, and it went unnoticed
		// because the repo that shipped this checker has ZERO server leaf
		// faces (measured 2026-09-19: `0 server leaf face(s), 1 JS
		// registration(s)`), so R2's server side had never run against real
		// subject matter. Buildiq is the first repo where it does, and two of
		// its three leaves are data providers, one of them carrying a comment
		// that says so in as many words.
		if (f.renderSurface !== true) {
			continue
		}
		ctx.counts.R2++
		if (ctx.jsById.has(f.id) === false) {
			ctx.failures.push(
				`✗ [R2 id-correlation] server leaf "${f.id}" (${f.kind}, ${f.file}) has NO matching `
					+ `registerIntegration({ id: '${f.id}' }) in src/** — phantom leaf: the `
					+ `openregister.integrations.leaves capability advertises a surface that never mounts.`,
			)
		}
	}
}

/**
 * R2, JS half: every JS registration id has a server face.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkJsIds(ctx) {
	for (const r of ctx.regs) {
		if (r.id === null) {
			continue
		}
		ctx.counts.R2++
		if (ctx.faceById.has(r.id) === false) {
			ctx.failures.push(
				`✗ [R2 id-correlation] registerIntegration id "${r.id}" (${r.file}) has NO matching `
					+ `server-side face in lib/** (neither a \`new LeafDescriptor(id: '${r.id}')\` nor an `
					+ `IntegrationProvider whose getId() returns '${r.id}') — orphan registration: it `
					+ `mounts on window.OCA.OpenRegister.integrations but is invisible to the `
					+ `openregister.integrations.leaves capability.`,
			)
		}
	}
}

/**
 * R3: for a shared id, both faces declare the same renderMode.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkRenderModes(ctx) {
	ctx.counts.R3 = 0
	for (const [id, f] of ctx.faceById) {
		const r = ctx.jsById.get(id)
		if (bothDeclareRenderMode(f, r) === false) {
			continue
		}
		ctx.counts.R3++
		if (f.renderMode !== r.renderMode) {
			ctx.failures.push(
				`✗ [R3 renderMode] leaf "${id}" declares renderMode "${f.renderMode}" server-side `
					+ `(${f.file}) but "${r.renderMode}" in its JS registration (${r.file}) — a `
					+ `renderMode mismatch blanks the surface (ADR-066 decision 7).`,
			)
		}
	}
}

/**
 * Whether a server face and its JS registration (if any) both declare a renderMode.
 *
 * @param {object} f The server face.
 * @param {object|undefined} r The JS registration with the same id.
 *
 * @return {boolean} True when both declare one.
 */
function bothDeclareRenderMode(f, r) {
	return r !== undefined && f.renderMode !== null && r.renderMode !== null
}

/**
 * Metadata fields R4 compares when both faces declare them statically.
 *
 * @type {string[]}
 */
const COMPARED = [
	'label',
	'icon',
	'group',
	'requiredApp',
	'referenceType',
	'surfaces',
]

/**
 * R4 for one shared id: every field both faces declare agrees.
 *
 * @param {object} ctx The rule context.
 * @param {{id: string, f: object, r: object}} leaf The id with its two faces.
 *
 * @return {void}
 */
function compareLeafFields(ctx, leaf) {
	const { id, f, r } = leaf
	for (const field of COMPARED) {
		const left = f.fields[field]
		const right = r.fields[field]
		if (left === undefined || right === undefined) {
			continue
		}
		ctx.counts.R4++
		if (sameValue(left, right) === false) {
			ctx.failures.push(
				`✗ [R4 metadata] leaf "${id}" field \`${field}\` mismatch across layers: `
					+ `server (${f.file}) says ${JSON.stringify(left)}, JS (${r.file}) says `
					+ `${JSON.stringify(right)} — the two halves describe different leaves.`,
			)
		}
	}
}

/**
 * R4: cross-layer metadata agreement for every shared id.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkMetadata(ctx) {
	ctx.counts.R4 = 0
	for (const [id, f] of ctx.faceById) {
		const r = ctx.jsById.get(id)
		if (r !== undefined) {
			compareLeafFields(ctx, { id, f, r })
		}
	}
}

/**
 * R5 for one registration: each spread comes from the owning package, and a
 * registration without an id spreads at least one descriptor.
 *
 * @param {object} ctx The rule context.
 * @param {object} r The JS registration.
 *
 * @return {void}
 */
function checkSpreads(ctx, r) {
	for (const symbol of r.spreads) {
		ctx.counts.R5++
		const failure = spreadSourceFailure(r, symbol)
		if (failure !== null) {
			ctx.failures.push(failure)
		}
	}
	if (r.id === null && r.spreads.length === 0) {
		ctx.failures.push(
			`✗ [R5 spread-source] registerIntegration in ${r.file} declares neither an \`id\` `
				+ `nor a spread of a descriptor — it registers a leaf with no identity.`,
		)
	}
}

/**
 * The R5 failure of one spread, or null when it comes from the owning package.
 *
 * @param {object} r The JS registration.
 * @param {string} symbol The spread binding.
 *
 * @return {string|null} The failure message.
 */
function spreadSourceFailure(r, symbol) {
	const source = r.spreadSources[symbol]
	if (source === null) {
		return (
			`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\`, `
			+ `which is not imported in that file — the spread of an undefined binding `
			+ `registers a leaf with no id.`
		)
	}
	if (source !== '@conduction/nextcloud-vue') {
		return (
			`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\` `
			+ `imported from "${source}", not from the leaf-owning package `
			+ `@conduction/nextcloud-vue — an override must extend the descriptor it `
			+ `overrides, or the id it registers under is not the one it thinks.`
		)
	}
	return null
}

/**
 * R5: a spread registration spreads the owning package's descriptor.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkSpreadSources(ctx) {
	ctx.counts.R5 = 0
	for (const r of ctx.regs) {
		checkSpreads(ctx, r)
	}
}

/**
 * R6, schema half: each schema slug an offlineConfig names is declared.
 *
 * @param {object} ctx The rule context.
 * @param {object} r The JS registration with an offlineConfig.
 *
 * @return {void}
 */
function checkOfflineSchemas(ctx, r) {
	for (const key of OFFLINE_CONFIG_CONTRACT.schemas) {
		const slug = r.offlineConfig[key]
		if (slug === undefined) {
			continue
		}
		ctx.counts.R6++
		if (Object.hasOwn(ctx.schemas, slug) === false) {
			ctx.failures.push(
				`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${slug}" (${r.file}) names a `
					+ `schema this repo does not declare in lib/Settings/** — the leaf would query a `
					+ `schema that does not exist. Declared slugs: ${Object.keys(ctx.schemas).sort().join(', ')}`,
			)
		}
	}
}

/**
 * R6, property half: each planned-item field an offlineConfig names is a
 * property of the declared planned schema.
 *
 * @param {object} ctx The rule context.
 * @param {object} r The JS registration with an offlineConfig.
 *
 * @return {void}
 */
function checkOfflinePlannedFields(ctx, r) {
	const planned = r.offlineConfig.plannedSchema
	const props = plannedProperties(ctx, planned)
	if (props === undefined) {
		return // no planned schema, or one already reported by the schema half
	}
	for (const key of OFFLINE_CONFIG_CONTRACT.plannedFields) {
		const prop = r.offlineConfig[key]
		if (prop === undefined) {
			continue
		}
		ctx.counts.R6++
		if (props.includes(prop) === false) {
			ctx.failures.push(
				`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${prop}" (${r.file}) is not a `
					+ `property of schema "${planned}" as declared in lib/Settings/** — the leaf `
					+ `would filter/display on a property that does not exist. Declared properties: `
					+ `${[...new Set(props)].sort().join(', ')}`,
			)
		}
	}
}

/**
 * The declared properties of the planned schema, or undefined when there is
 * none or it is not declared.
 *
 * @param {object} ctx The rule context.
 * @param {string|undefined} planned The planned schema slug.
 *
 * @return {string[]|undefined} Its property names.
 */
function plannedProperties(ctx, planned) {
	return planned === undefined ? undefined : ctx.schemas[planned]
}

/**
 * R6: an offlineConfig names schemas and properties this repo declares.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkOfflineConfigs(ctx) {
	ctx.counts.R6 = 0
	for (const r of ctx.regs.filter((reg) => reg.offlineConfig !== undefined)) {
		if (ctx.schemaFiles === 0) {
			ctx.failures.push(
				`✗ [R6 offlineConfig] registerIntegration in ${r.file} supplies an \`offlineConfig\` `
					+ `naming OpenRegister schemas, but this repo declares no register/schema JSON under `
					+ `lib/Settings/** to check it against — the mapping cannot be verified.`,
			)
			continue
		}
		checkOfflineSchemas(ctx, r)
		checkOfflinePlannedFields(ctx, r)
	}
}

/**
 * R7: a data-provider leaf is registered with a provider.
 *
 * @param {object} ctx The rule context.
 *
 * @return {void}
 */
function checkProviderBindings(ctx) {
	// --- R7: a data-provider leaf is bound to a provider ---------------------
	// The mirror of R2. R2 asks whether a RENDER SURFACE has the JS half that
	// draws it; this asks whether a DATA PROVIDER has the server half that
	// answers it. `registerLeaf($descriptor, null)` on a data-provider leaf
	// puts an entry in OpenRegister's catalogue that can never return data,
	// which is the same phantom in the other direction.
	//
	// It also gives this checker something to say about a repo whose leaves
	// are all data providers. Without it such a repo reaches the
	// "every rule had ZERO subject matter" refusal below, and a gate that
	// cannot speak about the only subject matter present is a gate that does
	// not run.
	ctx.counts.R7 = 0
	for (const f of ctx.faces) {
		if (f.dataProvider !== true || f.id === null) {
			continue
		}
		ctx.counts.R7++
		if (f.providerBound === false) {
			ctx.failures.push(
				`✗ [R7 provider-binding] server leaf "${f.id}" (${f.kind}, ${f.file}) declares `
					+ `KIND_DATA_PROVIDER but is registered with a null provider — the catalogue `
					+ `advertises a data source that can never answer.`,
			)
		}
	}
}

/**
 * Print the outcome and exit: 0 when every rule with subject matter passed,
 * 1 on a violation or when no rule had any subject matter.
 *
 * @param {object} ctx The rule context after every rule ran.
 *
 * @return {void}
 */
function report(ctx) {
	const { failures, counts } = ctx
	const scope =
		`${ctx.faces.length} server leaf face(s), ${ctx.regs.length} JS registration(s), `
		+ `${ctx.schemaFiles} OpenRegister schema declaration file(s)`
	const perRule = Object.entries(counts)
		.map(([rule, n]) => `${rule}:${n}`)
		.join(' ')
	if (failures.length === 0) {
		console.log(
			`✓ integration parity: ${scope} — all rules pass (assertions run per rule: ${perRule})`,
		)
		if (Object.values(counts).every((n) => n === 0) === true) {
			console.error(
				'✗ integration parity: every rule had ZERO subject matter, yet gate-24 selected this '
					+ 'repo as one that registers leaves. That contradiction means this checker failed to '
					+ 'read what the gate can see — a pass here would be a pass over nothing.',
			)
			process.exit(1)
		}
		process.exit(0)
	}
	// The header carries no `✗` on purpose: gate-24 counts violations by
	// grepping `^✗` in this log, so every violation — and only a violation —
	// starts a line with it.

	console.error(
		`integration parity gate FAILED — ${failures.length} violation(s) over ${scope}:`,
	)
	for (const f of failures) {
		console.error(f)
	}

	console.error(`\nAssertions run per rule: ${perRule}`)
	process.exit(1)
}

/**
 * Run every rule and report.
 *
 * @return {void}
 */
function main() {
	const ctx = buildContext()
	checkRenderPairs(ctx)
	checkServerIds(ctx)
	checkJsIds(ctx)
	checkRenderModes(ctx)
	checkMetadata(ctx)
	checkSpreadSources(ctx)
	checkProviderBindings(ctx)
	checkOfflineConfigs(ctx)
	report(ctx)
}

if (require.main === module) {
	main()
}

module.exports = {
	balanced,
	splitTopLevel,
	resolvePhp,
	resolveJs,
	collectServerFaces,
	collectJsRegistrations,
	collectOrSchemas,
}
