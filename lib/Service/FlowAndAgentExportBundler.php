<?php

/**
 * Bundles an Application's flows and agents into an export tree.
 *
 * Sibling to {@see DataRegisterExportBundler}: same "write JSON into the
 * scaffold, skip what does not resolve" shape, different stores and different
 * failure modes.
 *
 * WHAT IT READS, AND WHY THAT IS THE WHOLE RISK
 * --------------------------------------------
 * Flows come from the OpenRegister **`Flow` ENTITY** (`FlowMapper`), because
 * that is the store the engine executes. A parallel `agentflow` OBJECT store
 * in the hermiq register mirrors some definitions and DRIFTS from it — a
 * definition written to the object left the engine running the previous graph,
 * with the run log showing the old node set and no error surfaced anywhere.
 *
 * A bundler that read the mirror would produce an export whose flows look
 * correct in every UI and are not the graphs that run. Nothing downstream can
 * detect that, which is why the unit test for this class uses a fixture where
 * the two stores DISAGREE: a fixture where they agree cannot tell the right
 * implementation from the wrong one.
 *
 * BINDING BY UUID
 * ---------------
 * `Application.flows[].flow` is a UUID, not a slug — the `Flow` entity has no
 * slug — and not the numeric `id`, which is an auto-increment column that
 * resolves to a different flow, or to nothing, on any other instance.
 *
 * AGENTS HAVE NO BINDING
 * ----------------------
 * There is deliberately no `Application.agents`. The `agent` schema already
 * carries `applicationSlug`, and that is how `AgentsController` resolves an
 * application's agents. A second edge for the same relationship, pointing the
 * other way, is two facts that can disagree with nothing to arbitrate between
 * them. So agents are found by asking which agents point AT this application.
 *
 * ONE FLOW SYSTEM (ADR-065)
 * -------------------------
 * A flow whose nodes are agentic types contributed by hermiq
 * (`hermiq.workload-step`, `hermiq.workload-collect`) is an ORDINARY
 * OpenRegister flow. This class does not branch on node type, does not
 * special-case hermiq, and emits one file shape for every flow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an Application's flows and agents into the exported tree.
 */
class FlowAndAgentExportBundler {

	/**
	 * Register holding OpenBuild's own objects.
	 *
	 * @var int
	 */
	private const OPENBUILD_REGISTER = 206;

	/**
	 * Schema of an `agent` object.
	 *
	 * @var int
	 */
	private const AGENT_SCHEMA = 5060;

	/**
	 * Constructor.
	 *
	 * @param FlowMapper $flowMapper Resolves a bound flow's UUID against the entity the engine runs.
	 * @param ObjectService $objectService Finds the agents that point at this application.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FlowMapper $flowMapper,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bundle an application's flows and agents into `$rootDir`.
	 *
	 * Bindings are untrusted data round-tripped through OpenRegister — read
	 * back from an `ExportJob` record by `RunExportJob` — so they are typed
	 * loosely here and the defensive guards stay meaningful rather than being
	 * flagged as dead code against an assumed-certain shape. This mirrors
	 * `DataRegisterExportBundler::bundle()` deliberately.
	 *
	 * @param string $rootDir Scratch directory (exported tree root).
	 * @param array<int,mixed> $flows `Application.flows` bindings.
	 * @param string $applicationSlug Slug of the application whose agents to collect.
	 *
	 * @return array<int, array{kind: string, ref: string, reason: string}> What was skipped, for the job result.
	 */
	public function bundle(string $rootDir, array $flows, string $applicationSlug): array {
		$skipped = $this->bundleFlows(rootDir: $rootDir, flows: $flows);

		return array_merge($skipped, $this->bundleAgents(rootDir: $rootDir, applicationSlug: $applicationSlug));
	}//end bundle()

	/**
	 * Resolve and write every bound flow.
	 *
	 * @param string $rootDir Exported tree root.
	 * @param array<int,mixed> $flows The bindings.
	 *
	 * @return array<int, array{kind: string, ref: string, reason: string}> Skips.
	 */
	private function bundleFlows(string $rootDir, array $flows): array {
		$skipped = [];
		if ($flows === []) {
			return $skipped;
		}

		$targetDir = $rootDir . '/lib/Settings/flows';

		foreach ($flows as $binding) {
			if (is_array($binding) === false) {
				continue;
			}

			$uuid = trim((string)($binding['flow'] ?? ''));
			if ($uuid === '') {
				continue;
			}

			try {
				// THE ENTITY, not the `agentflow` object mirror. See the class docblock.
				$flow = $this->flowMapper->findByUuid($uuid);
			} catch (Throwable $e) {
				// Dangling reference. Skipped rather than fatal — deleting a
				// flow is ordinary — but RETURNED rather than only logged,
				// because an operator reads the finished job, not the log.
				$this->logger->info(
					'OpenBuild export: flows binding "' . $uuid . '" did not resolve to a flow — not bundled: '
					. $e->getMessage()
				);
				$skipped[] = ['kind' => 'flow', 'ref' => $uuid, 'reason' => 'no flow with that UUID'];
				continue;
			}

			if (is_dir($targetDir) === false) {
				mkdir($targetDir, 0o755, true);
			}

			$this->writeJson(
				path: $targetDir . '/' . $uuid . '.json',
				payload: [
					// The UUID travels WITH the definition. The importing side
					// seeds it verbatim: mint a new one and every binding in
					// the imported application points at nothing, while every
					// file in the ZIP still looks correct.
					'uuid' => $uuid,
					'name' => (string)$flow->getName(),
					'description' => (string)$flow->getDescription(),
					'enabled' => (bool)$flow->getEnabled(),
					'trigger' => $flow->getTrigger(),
					'triggerRegister' => $flow->getTriggerRegister(),
					'triggerSchema' => $flow->getTriggerSchema(),
					'cron' => $flow->getCron(),
					'executionMode' => $flow->getExecutionMode(),
					'nodes' => (array)$flow->getNodes(),
					'edges' => (array)$flow->getEdges(),
					'limits' => $flow->getLimits(),
				]
			);
		}//end foreach

		return $skipped;
	}//end bundleFlows()

	/**
	 * Write the agents that point at this application.
	 *
	 * No binding is consulted: `agent.applicationSlug` IS the relationship.
	 *
	 * @param string $rootDir Exported tree root.
	 * @param string $applicationSlug The application's slug.
	 *
	 * @return array<int, array{kind: string, ref: string, reason: string}> Skips.
	 */
	private function bundleAgents(string $rootDir, string $applicationSlug): array {
		$applicationSlug = trim($applicationSlug);
		if ($applicationSlug === '') {
			return [['kind' => 'agents', 'ref' => '', 'reason' => 'application has no slug to match agents against']];
		}

		try {
			$agents = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::OPENBUILD_REGISTER,
						'schema' => self::AGENT_SCHEMA,
						'applicationSlug' => $applicationSlug,
					],
					'limit' => 200,
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('OpenBuild export: could not read agents for "' . $applicationSlug . '": ' . $e->getMessage());
			return [['kind' => 'agents', 'ref' => $applicationSlug, 'reason' => 'agent lookup failed']];
		}

		if ($agents === []) {
			return [];
		}

		$targetDir = $rootDir . '/lib/Settings/agents';
		if (is_dir($targetDir) === false) {
			mkdir($targetDir, 0o755, true);
		}

		foreach ($agents as $agent) {
			$record = is_array($agent) === true ? $agent : $agent->jsonSerialize();
			$uuid = (string)($record['@self']['id'] ?? '');
			unset($record['@self']);

			if ($uuid === '') {
				continue;
			}

			$this->writeJson(path: $targetDir . '/' . $uuid . '.json', payload: $record);
		}

		return [];
	}//end bundleAgents()

	/**
	 * Write one definition as pretty JSON.
	 *
	 * Pretty-printed and slash-unescaped because these files are read by
	 * humans reviewing an exported app, and diffed between versions.
	 *
	 * @param string $path Destination.
	 * @param array $payload What to write.
	 *
	 * @return void
	 */
	private function writeJson(string $path, array $payload): void {
		file_put_contents(
			$path,
			(string)json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
		);
	}//end writeJson()
}//end class
