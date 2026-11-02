/**
 * SPDX-FileCopyrightText: 2026 Conduction / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `scripts/check-integration-parity.js` over small fixture
 * repositories that each trip a known set of rules R1 to R7. The script is run
 * as a child process, so the exit code and both output streams are part of the
 * snapshot, and the per-rule assertion counts gate-24 reads stay pinned.
 *
 * @spec exclude test of the gate-24 parity script, which has no spec of its own
 */
import { spawnSync } from 'child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { afterAll, describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(
	__dirname,
	'../../scripts/check-integration-parity.js',
)
const roots = []

/**
 * Write a fixture repository and return its root.
 *
 * @param {Object<string, string>} files Relative path to file contents.
 *
 * @return {string} The fixture root.
 */
function fixture(files) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'buildiq-parity-'))
	roots.push(root)
	for (const [rel, text] of Object.entries(files)) {
		fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
		fs.writeFileSync(path.join(root, rel), text)
	}
	return root
}

/**
 * Run the parity script over a fixture, with the fixture root normalised in
 * the output so the snapshot does not depend on the temp directory.
 *
 * @param {string} root The fixture root.
 *
 * @return {{status: number, stdout: string, stderr: string}} The run.
 */
function run(root) {
	const p = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' })
	const clean = (text) => text.split(root).join('<root>')
	return { status: p.status, stdout: clean(p.stdout), stderr: clean(p.stderr) }
}

const SCHEMAS = JSON.stringify({
	components: {
		schemas: {
			plan: { slug: 'plan', properties: { title: {}, date: {} } },
			ref: { properties: { name: {} } },
		},
	},
})

const PASSING = {
	'lib/Leaf/Leaves.php': `<?php
class Leaves {
	const ID = 'alpha';
	public function all() {
		return [
			new LeafDescriptor(
				id: self::ID,
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				renderMode: LeafDescriptor::RENDER_MODE_COMPONENT,
				label: 'Alpha',
				surfaces: ['tab', 'widget'],
			),
		];
	}
}
`,
	'lib/Provider/BetaProvider.php': `<?php
class BetaProvider extends AbstractIntegrationProvider {
	public function getId(): string { return Leaves::ID . 'x'; }
	public function getLabel(): string { return $this->l10n->t('Beta'); }
	public function getIcon(): string { return "icon-beta"; }
}
`,
	'lib/Provider/GammaProvider.php': `<?php
class GammaProvider implements Foo, IntegrationProviderInterface {
	public function getId(): string { return 'gamma'; }
	public function getGroup(): string { return 'g'; }
}
`,
	'lib/Settings/register.json': SCHEMAS,
	'src/alpha.js': `import { registerIntegration } from '@conduction/nextcloud-vue'
const LABEL = 'Alpha'
registerIntegration({
	id: 'alpha',
	label: LABEL,
	icon: 'only-on-the-js-side',
	surfaces: ['widget', 'tab'],
	tab: AlphaTab,
	widget: AlphaWidget,
	offlineConfig: { plannedSchema: 'plan', referenceSchema: 'ref', titleField: 'title', dateField: 'date' },
})
const gamma = { id: 'gamma', group: 'g', tab: G, widget: W }
window.OCA.OpenRegister.integrations.register(gamma)
export function registerIntegrationIcons() {}
`,
}

const FAILING = {
	'lib/Leaf/Leaves.php': `<?php
final class Leaves {
	const MODE = 'mount';
	const LIST = ['a', "b"];
	public function all() {
		return [
			new LeafDescriptor(id: 'bravo', kinds: [LeafDescriptor::KIND_RENDER_SURFACE], renderMode: LeafDescriptor::RENDER_MODE_COMPONENT, label: 'Bravo', icon: 'i', group: 'x', requiredApp: 'a', referenceType: 'r', surfaces: ['tab']),
			new LeafDescriptor(label: 'nameless'),
			new LeafDescriptor(id: 'charlie', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]),
			new LeafDescriptor(id: ['listed'], kinds: [LeafDescriptor::KIND_RENDER_SURFACE], renderMode: ['mount']),
			new LeafDescriptor('positional', id: 'foxtrot', kinds: ['render-surface'], renderMode: self::MODE, label: Other::NAME, icon: Leaves::MISSING),
		];
	}
}
`,
	'lib/Settings/register.json': SCHEMAS,
	'lib/Other.php': `<?php
abstract class Other {
	const NAME = 'Fox';
}
`,
	'lib/Provider/HotelProvider.php': `<?php
class HotelProvider extends AbstractIntegrationProvider {
	public function getId(): string { return 'hotel'; }
	public function getLabel(): string { throw new Exception(); }
	public function getIcon(): string { return [self::NOPE]; }
}
`,
	'lib/Provider/NamelessProvider.php': `<?php
class NamelessProvider extends AbstractIntegrationProvider {
	public function getLabel(): string { return 'x'; }
}
`,
	'lib/Settings/broken.json': '{ not json',
	'lib/Settings/other.json': JSON.stringify({ components: {} }),
	'src/bravo.js': `import { local } from './local'
import Def, { other as aliased } from '@conduction/nextcloud-vue'
registerIntegration({ id: 'bravo', renderMode: 'mount', mount, label: 'Bravo2', icon: 'i', group: 'y', requiredApp: 'a', referenceType: 'q', surfaces: ['widget'] })
registerIntegration({ id: 'delta', tab: T, widget: W, offlineConfig: { plannedSchema: 'nope', resultSchema: 'plan', titleField: 'title' } })
registerIntegration({ id: 'echo', tab: T, widget: W, offlineConfig: { plannedSchema: 'plan', titleField: 'missing', assigneeField: 'title', templateRefField: 'gone' } })
registerIntegration({ ...ghost })
registerIntegration({ ...local, tab: T })
registerIntegration({ ...aliased, ...Def })
registerIntegration({ tab: T })
registerIntegration(unknownName)
`,
	'src/foxtrot.ts': `const MODE = 'mount'
const fox = { 'id': "foxtrot", renderMode: MODE, mount, "unmount": u, label: 'Fox', offlineConfig: { ...base, plannedSchema: 'plan', titleField: NOT_KNOWN, 7: 'x' } }
OCA.OpenRegister.integrations . register( fox )
registerIntegration({ id: 'hotel', tab, widget, offlineConfig: cfg })
`,
	'src/skip.vue': '<script>\nregisterIntegrationIcons()\n</script>\n',
}

/**
 * A listener that registers one descriptor, padded so the descriptor's `new`
 * starts `gap` characters after the `registerLeaf(` it belongs to.
 *
 * @param {{id: string, gap: number}} leaf The leaf id and the distance.
 *
 * @return {string} The PHP call.
 */
function paddedLeaf(leaf) {
	const call = '$event->registerLeaf('
	const pad = ' '.repeat(leaf.gap - call.length + '$event->'.length)
	return `${call}${pad}new LeafDescriptor(id: '${leaf.id}', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]), null);`
}

const PROVIDERS = {
	'lib/Listener/Leaves.php': `<?php
class Leaves {
	public function handle($event) {
		$event->registerLeaf(new LeafDescriptor(id: 'bound', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]), $this->forms);
		$event->registerLeaf(new LeafDescriptor(id: 'unbound', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]), null);
		$event->registerLeaf(new LeafDescriptor(id: 'spaced', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]),   null  );
		$event->registerLeaf(new LeafDescriptor(id: 'single', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]));
		$loose = new LeafDescriptor(id: 'loose', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]);
		$event->registerLeaf(new LeafDescriptor(kinds: [LeafDescriptor::KIND_DATA_PROVIDER]), null);
		$event->registerLeaf(new LeafDescriptor(id: 'both', kinds: [LeafDescriptor::KIND_RENDER_SURFACE, LeafDescriptor::KIND_DATA_PROVIDER]), null);
		$event->registerLeaf(new LeafDescriptor(id: 'surface', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]), null);
		$event->registerLeaf(new LeafDescriptor(id: 'unread', kinds: ['data-provider']), null);
		${paddedLeaf({ id: 'near', gap: 1999 })}
		${paddedLeaf({ id: 'far', gap: 2000 })}
	}
}
`,
	'src/both.js': `registerIntegration({ id: 'both', tab: T, widget: W })
registerIntegration({ id: 'bound', tab: T, widget: W })
`,
}

describe('check-integration-parity golden output', () => {
	afterAll(() => {
		for (const root of roots) {
			fs.rmSync(root, { recursive: true, force: true })
		}
	})

	it('passes a repository whose faces agree', () => {
		expect(run(fixture(PASSING))).toMatchSnapshot()
	})

	it('reports every rule violation in a disagreeing repository', () => {
		expect(run(fixture(FAILING))).toMatchSnapshot()
	})

	it('fails an offlineConfig when no schema declaration exists', () => {
		const files = { ...PASSING }
		delete files['lib/Settings/register.json']
		expect(run(fixture(files))).toMatchSnapshot()
	})

	it('checks the provider binding of data-provider leaves', () => {
		expect(run(fixture(PROVIDERS))).toMatchSnapshot()
	})

	it('passes data-provider leaves that are all bound', () => {
		expect(
			run(
				fixture({
					'lib/Listener/Leaves.php': `<?php
$event->registerLeaf(new LeafDescriptor(id: 'only', kinds: [LeafDescriptor::KIND_DATA_PROVIDER]), $this->forms);
`,
				}),
			),
		).toMatchSnapshot()
	})

	it('leaves an offlineConfig passed by name unchecked', () => {
		expect(
			run(
				fixture({
					'lib/Leaf/Leaves.php': `<?php
new LeafDescriptor(id: 'india', kinds: [LeafDescriptor::KIND_RENDER_SURFACE]);
`,
					'src/india.js': `registerIntegration({ id: 'india', tab: T, widget: W, offlineConfig: cfg })
`,
				}),
			),
		).toMatchSnapshot()
	})

	it('fails a pass over nothing', () => {
		expect(
			run(fixture({ 'lib/Empty.php': '<?php\n', 'src/empty.js': '\n' })),
		).toMatchSnapshot()
	})
})
