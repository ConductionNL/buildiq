/**
 * SPDX-FileCopyrightText: 2026 Conduction / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the text scanners and value resolvers inside
 * `scripts/check-integration-parity.js` over seeded, generated inputs: the
 * delimiter matcher, the top-level comma splitter and the PHP and JS
 * expression resolvers. A digest pins every case and a sample stays readable.
 *
 * @spec exclude test of the gate-24 parity script, which has no spec of its own
 */
import { createHash } from 'crypto'
import { describe, expect, it } from 'vitest'
const parity = require('../../scripts/check-integration-parity.js')

/**
 * A seeded pseudo-random generator (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns a float in [0, 1).
 */
function mulberry32(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6d2b79f5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one item of a list.
 *
 * @param {function(): number} rnd The generator.
 * @param {Array} list The choices.
 *
 * @return {*} The picked item.
 */
function pick(rnd, list) {
	return list[Math.floor(rnd() * list.length)]
}

const RARE_TOKENS = [
	"'",
	'"',
	'`',
	'\\',
	'//',
	'/*',
	'*/',
	'#',
	'\n',
	"'q'",
	'"d"',
	'/* c */',
]
const CODE_TOKENS = [
	'(',
	')',
	'[',
	']',
	'{',
	'}',
	',',
	',',
	' ',
	'a',
	'bc',
	'/',
	'*',
	'$x',
	'=>',
]

/**
 * Generate a short text of scanner-relevant tokens; quotes and comment
 * markers are rarer than delimiters so most texts still nest.
 *
 * @param {function(): number} rnd The generator.
 *
 * @return {string} The text.
 */
function scanText(rnd) {
	const n = Math.floor(rnd() * 24)
	let out = ''
	for (let i = 0; i < n; i++) {
		out += pick(rnd, rnd() < 0.15 ? RARE_TOKENS : CODE_TOKENS)
	}
	return out
}

/**
 * An index of an opening delimiter in the text, or a random index when the
 * text has none.
 *
 * @param {function(): number} rnd The generator.
 * @param {string} text The text.
 *
 * @return {number} The index.
 */
function openerIndex(rnd, text) {
	const at = [...text].flatMap((c, i) => ('([{'.includes(c) ? [i] : []))
	return at.length === 0 ? Math.floor(rnd() * (text.length + 1)) : pick(rnd, at)
}

const PHP_LEAVES = [
	"'plain'",
	"'it\\'s'",
	'"dq"',
	'"a\\"b"',
	"$this->l10n->t('Label')",
	'$this->l->t("Other", [])',
	"$this->l10n->t( 'Spaced' )",
	'self::A',
	'static::ARR',
	'self::MISSING',
	'Foo::C',
	'Foo::L',
	'Bar::NONE',
	'LeafDescriptor::KIND_DATA_PROVIDER',
	'LeafDescriptor::RENDER_MODE_MOUNT',
	'$var',
	'42',
	'null',
	"'a' . 'b'",
	'self::lower',
	'',
	"  'padded'  ",
	'self::toString',
	'Foo::constructor',
]

const JS_LEAVES = [
	"'plain'",
	"'it\\'s'",
	'"dq"',
	'`tpl`',
	'`a${b}`',
	"t('app', 'Label')",
	"t('Solo')",
	"t( 'app' , 'Spaced' )",
	"t('app', x)",
	'LOCAL',
	'LIST',
	'MISSING',
	'toString',
	'$id',
	'42',
	"'a' + 'b'",
	'',
	"  'padded'  ",
	'obj.prop',
]

/**
 * Generate an expression: a leaf, or an array of expressions.
 *
 * @param {function(): number} rnd The generator.
 * @param {{leaves: string[], depth: number}} grammar The leaves and the remaining depth.
 *
 * @return {string} The expression.
 */
function expression(rnd, grammar) {
	if (grammar.depth === 0 || rnd() < 0.6) {
		return pick(rnd, grammar.leaves)
	}
	const n = Math.floor(rnd() * 4)
	const inner = { leaves: grammar.leaves, depth: grammar.depth - 1 }
	const members = []
	for (let i = 0; i < n; i++) {
		members.push(expression(rnd, inner))
	}
	const tail = rnd() < 0.3 ? ',' : ''
	return '[' + members.join(pick(rnd, [',', ', ', ' ,\n'])) + tail + ']'
}

const LOCAL_CONSTS = { A: 'va', ARR: ['x', 'y'] }
const GLOBAL_CONSTS = { 'Foo::C': 'fc', 'Foo::L': ['l1'] }
const JS_LOCALS = { LOCAL: 'loc', LIST: ['p', 'q'] }

/**
 * Run every scanner and resolver over the generated cases of one seed.
 *
 * @param {number} seed The seed.
 *
 * @return {Array} One record per case.
 */
function cases(seed) {
	const rnd = mulberry32(seed)
	const out = []
	for (let i = 0; i < 1000; i++) {
		const text = scanText(rnd)
		const opened = pick(rnd, ['(', '[', '{', 'x']) + text
		const from = openerIndex(rnd, text)
		const php = expression(rnd, { leaves: PHP_LEAVES, depth: 3 })
		const js = expression(rnd, { leaves: JS_LEAVES, depth: 3 })
		out.push({
			text,
			split: parity.splitTopLevel(text),
			balancedOpened: parity.balanced(opened, 0),
			balancedAt: parity.balanced(text, from),
			php,
			phpValue: parity.resolvePhp(php, LOCAL_CONSTS, GLOBAL_CONSTS),
			js,
			jsValue: parity.resolveJs(js, JS_LOCALS),
		})
	}
	return out
}

describe('check-integration-parity scanners and resolvers', () => {
	it('keep their output over seeded inputs', () => {
		const all = [1, 7, 42, 2026].map(cases)
		const digest = createHash('sha256').update(JSON.stringify(all)).digest('hex')
		expect({ digest, sample: all[0].slice(0, 25) }).toMatchSnapshot()
	})
})
