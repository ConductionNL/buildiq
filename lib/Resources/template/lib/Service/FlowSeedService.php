<?php

/**
 * Seeds the flows this app ships into OpenRegister, so they can actually run.
 *
 * An exported app carries its flows as JSON under `lib/Settings/flows/`. Files
 * alone are inert: the engine executes the OpenRegister **`Flow` ENTITY** and
 * knows nothing about a directory in an app. Without this step the import
 * produces an app whose flows exist on disk, appear in no UI, and never fire —
 * which is worse than shipping no flows at all, because nothing surfaces it.
 *
 * WHAT THIS WRITES, AND WHY
 * -------------------------
 * The `Flow` entity, via `FlowMapper` — the same store the exporter read. A
 * parallel `agentflow` OBJECT store mirrors some definitions and drifts from
 * the entity; seeding the mirror would produce flows that look right in the
 * register UI and are invisible to the engine.
 *
 * ⚠️ THE UUID IS PRESERVED, NEVER MINTED. An application binds its flows by
 * UUID. Generating a fresh one on import leaves every binding in the imported
 * app pointing at nothing, while every file in the ZIP still looks correct —
 * the one failure mode that passes every check short of running a flow.
 *
 * IDEMPOTENT, AND IT DOES NOT CLOBBER
 * -----------------------------------
 * Runs on install AND on every upgrade (registered under `<post-migration>`,
 * because an app is installed once and upgraded many times, and a changed flow
 * ships in an upgrade). Seeding twice yields one flow.
 *
 * Where an operator has EDITED a seeded flow, the new version is not written
 * over it. The seeder remembers a fingerprint of what it last wrote; if the
 * flow on disk still matches that fingerprint it is safe to update, and if it
 * does not, the local edit is kept and the divergence recorded. Last-writer-
 * wins would silently delete the customisation that made the app useful to
 * that organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\AppTemplate\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts the app's shipped flow definitions into OpenRegister.
 */
class FlowSeedService {

	/**
	 * Where an exported app carries its flows.
	 *
	 * @var string
	 */
	private const FLOW_DIR = '/../Settings/flows';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Stores the per-flow fingerprint of what was last seeded.
	 * @param LoggerInterface $logger Logger.
	 * @param string|null $flowDir Where the shipped flows live; null uses the shipped location.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?string $flowDir = null,
	) {
	}//end __construct()

	/**
	 * Seed every shipped flow.
	 *
	 * Reported rather than thrown: a flow that cannot be seeded must not stop
	 * an app from installing, and an operator needs to know which one.
	 *
	 * @param object|null $flowMapper OpenRegister's FlowMapper, or null when OpenRegister is absent.
	 * @param array<string,bool> $registeredNodeTypes Node types the engine knows, keyed by type.
	 *
	 * @return array{seeded: int, kept: int, unknownNodeTypes: array<int,string>, failed: array<int,string>} What happened.
	 */
	public function seed(?object $flowMapper, array $registeredNodeTypes = []): array {
		$report = ['seeded' => 0, 'kept' => 0, 'unknownNodeTypes' => [], 'failed' => []];

		if ($flowMapper === null) {
			return $report;
		}

		foreach ($this->shippedFlowFiles() as $path) {
			$definition = json_decode((string)file_get_contents($path), true);
			if (is_array($definition) === false) {
				$report['failed'][] = basename($path) . ' is not valid JSON';
				continue;
			}

			$uuid = trim((string)($definition['uuid'] ?? ''));
			if ($uuid === '') {
				// A definition with no UUID cannot be bound to, and seeding it
				// under a generated one would create a flow no application
				// references. Refused loudly rather than seeded uselessly.
				$report['failed'][] = basename($path) . ' carries no uuid';
				continue;
			}

			$unknown = $this->unknownNodeTypes(definition: $definition, registered: $registeredNodeTypes);
			if ($unknown !== []) {
				// NOT a refusal: an app may legitimately ship a flow for a
				// capability the operator installs later. But it is surfaced
				// now, because discovering it when somebody triggers the flow
				// — possibly months later — is the expensive path.
				$report['unknownNodeTypes'] = array_values(array_unique(array_merge($report['unknownNodeTypes'], $unknown)));
			}

			try {
				$outcome = $this->seedOne(flowMapper: $flowMapper, uuid: $uuid, definition: $definition);
				$report[$outcome]++;
			} catch (Throwable $e) {
				$report['failed'][] = $uuid . ': ' . $e->getMessage();
				$this->logger->warning('AppTemplate: could not seed flow ' . $uuid . ': ' . $e->getMessage());
			}
		}//end foreach

		return $report;
	}//end seed()

	/**
	 * Create or update one flow, preserving a local edit.
	 *
	 * @param object $flowMapper OpenRegister's FlowMapper.
	 * @param string $uuid The flow's UUID, as shipped.
	 * @param array $definition The shipped definition.
	 *
	 * @return string `seeded` when written, `kept` when a local edit was preserved.
	 */
	private function seedOne(object $flowMapper, string $uuid, array $definition): string {
		$fingerprintKey = 'flow-seed-' . $uuid;
		$shipped = $this->fingerprint(definition: $definition);

		$existing = null;
		try {
			$existing = $flowMapper->findByUuid($uuid);
		} catch (Throwable) {
			$existing = null;
		}

		if ($existing !== null) {
			$lastSeeded = $this->appConfig->getValueString('apptemplate', $fingerprintKey, '');
			$current = $this->fingerprint(
				definition: ['nodes' => (array)$existing->getNodes(), 'edges' => (array)$existing->getEdges()]
			);

			// Unchanged since we last wrote it → safe to update. Changed →
			// somebody edited it here, and an upgrade must not eat that.
			if ($lastSeeded !== '' && $lastSeeded !== $current) {
				$this->logger->info(
					'AppTemplate: flow ' . $uuid . ' was modified on this instance; the shipped version was NOT written over it'
				);
				return 'kept';
			}

			$this->applyTo(flow: $existing, definition: $definition);
			$flowMapper->update($existing);
			$this->appConfig->setValueString('apptemplate', $fingerprintKey, $shipped);
			return 'seeded';
		}

		// `FlowMapper` extends QBMapper: insert() takes an ENTITY, and there is
		// no createFromArray(). The entity class is resolved by name so this
		// template compiles on an instance without OpenRegister installed.
		$entityClass = '\\OCA\\OpenRegister\\Db\\Flow';
		if (class_exists($entityClass) === false) {
			throw new \RuntimeException('OpenRegister Flow entity not available');
		}

		$flow = new $entityClass();
		$flow->setUuid($uuid);
		$flow->setEnabled(false);
		$this->applyTo(flow: $flow, definition: $definition);
		$flowMapper->insert($flow);

		$this->appConfig->setValueString('apptemplate', $fingerprintKey, $shipped);

		return 'seeded';
	}//end seedOne()

	/**
	 * Copy shipped values onto an existing entity.
	 *
	 * The UUID is deliberately not among them: it is the identity being
	 * matched on, not a field to overwrite. `enabled` is likewise absent — an
	 * app that installs itself and immediately starts running flows against an
	 * operator's data is a surprise, not a feature, and an upgrade must not
	 * silently re-enable a flow the operator turned off.
	 *
	 * @param object $flow The entity.
	 * @param array $definition The shipped definition.
	 *
	 * @return void
	 */
	private function applyTo(object $flow, array $definition): void {
		$flow->setName((string)($definition['name'] ?? ''));
		$flow->setDescription((string)($definition['description'] ?? ''));
		$flow->setNodes((array)($definition['nodes'] ?? []));
		$flow->setEdges((array)($definition['edges'] ?? []));
	}//end applyTo()

	/**
	 * Node types the engine does not know.
	 *
	 * @param array $definition The shipped definition.
	 * @param array<string,bool> $registered Known types.
	 *
	 * @return array<int,string> Unknown types, deduplicated.
	 */
	private function unknownNodeTypes(array $definition, array $registered): array {
		if ($registered === []) {
			return [];
		}

		$unknown = [];
		foreach ((array)($definition['nodes'] ?? []) as $node) {
			$type = (string)(((array)$node)['type'] ?? '');
			if ($type === '' || isset($registered[$type]) === true) {
				continue;
			}

			$unknown[$type] = true;
		}

		return array_keys($unknown);
	}//end unknownNodeTypes()

	/**
	 * A stable fingerprint of a flow's graph.
	 *
	 * Nodes and edges only: a name change is not a reason to refuse an
	 * upgrade, and the graph is what an operator actually customises.
	 *
	 * @param array $definition The definition or entity fields.
	 *
	 * @return string The fingerprint.
	 */
	private function fingerprint(array $definition): string {
		return hash(
			'sha256',
			(string)json_encode(['nodes' => ($definition['nodes'] ?? []), 'edges' => ($definition['edges'] ?? [])])
		);
	}//end fingerprint()

	/**
	 * Every flow file this app ships.
	 *
	 * The directory is INJECTABLE, defaulting to the shipped location. A test
	 * that had to write fixtures into the source tree would be a test that
	 * needs write access to the app it is testing — which fails as soon as it
	 * runs as the web user against a read-only or differently-owned checkout,
	 * and which leaves artefacts in the repository when it does work.
	 *
	 * @return array<int,string> Absolute paths, sorted for deterministic order.
	 */
	private function shippedFlowFiles(): array {
		$dir = ($this->flowDir ?? (__DIR__ . self::FLOW_DIR));
		if (is_dir($dir) === false) {
			return [];
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;
	}//end shippedFlowFiles()
}//end class
