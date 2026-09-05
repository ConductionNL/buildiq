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
 * AN AGENT CAN LIVE IN HERMIQ'S REGISTER TOO
 * -------------------------------------------
 * `agent` is not exclusively Buildiq's schema slug: hermiq declares its own
 * `agent` schema in its own `hermiq` register (`lib/Settings/hermiq_register.json`),
 * and an agent an operator hand-creates or hermiq seeds there can carry
 * `applicationSlug` too (hermiq-agent-application-slug). The first lookup below
 * is scoped to `register: buildiq` explicitly, so it structurally cannot see
 * those rows no matter what `applicationSlug` they carry — a schema slug match
 * is not a register match. When that lookup finds nothing, a second one is
 * attempted against hermiq's register, guarded by `IAppManager::isEnabledForUser()`
 * exactly the way {@see SkillChannelDelegate} treats hermiq as an optional
 * dependency elsewhere in this codebase — hermiq may not be installed, and this
 * class must not hard-depend on it. The two stores are a FALLBACK, not a merge:
 * an application's agents are expected to live in one store or the other, and
 * checking hermiq only when buildiq's own schema is empty keeps the common
 * case (agents in buildiq's own store) a single query.
 *
 * hermiq's agent shape is richer than buildiq's own schema (real prompts up
 * to several thousand characters, `hermiq.*` tool grants) and is never coerced
 * into buildiq's schema on the way out: {@see self::writeJson()} writes plain
 * JSON with no schema validation, exactly as it already does for an
 * buildiq-native agent.
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
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an Application's flows and agents into the exported tree.
 */
class FlowAndAgentExportBundler {

	/**
	 * Slug of the register holding Buildiq's own objects.
	 *
	 * A SLUG, not the numeric register id: the id is an auto-increment
	 * column assigned per instance and is not stable across a fresh
	 * install, while the slug is buildiq's own fixed identity for this
	 * register (`register.d/*.json`). `ObjectService::findAll()` resolves
	 * a string filter value through `RegisterMapper`, which supports slug
	 * lookup — see `AgentsController::REGISTER_SLUG` and
	 * `ObjectSchemaSlugResolver::REGISTER_SLUG` for the same constant
	 * elsewhere in this codebase.
	 *
	 * @var string
	 */
	private const BUILDIQ_REGISTER = 'buildiq';

	/**
	 * Slug of the `agent` schema.
	 *
	 * A SLUG, not the numeric schema id, for the same reason as
	 * {@see self::BUILDIQ_REGISTER}. Resolved register-scoped (register
	 * is set before schema in `ObjectService::prepareFindAllConfig()`),
	 * which matters because schema slugs are not globally unique on this
	 * instance.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'buildAgent';

	/**
	 * App id of hermiq, hermiq's own register slug, and hermiq's own `agent`
	 * schema slug — consulted only as a FALLBACK when {@see self::BUILDIQ_REGISTER}
	 * finds no agents, and only when hermiq is actually installed. Both are
	 * slugs, resolved the same way as {@see self::BUILDIQ_REGISTER} — never
	 * hermiq's numeric register/schema ids, which are per-instance and not
	 * portable.
	 *
	 * @var string
	 */
	private const HERMIQ_APP_ID = 'hermiq';

	/**
	 * Slug of hermiq's own register.
	 *
	 * @var string
	 */
	private const HERMIQ_REGISTER = 'hermiq';

	/**
	 * Slug of hermiq's own `agent` schema.
	 *
	 * @var string
	 */
	private const HERMIQ_AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param FlowMapper $flowMapper Resolves a bound flow's UUID against the entity the engine runs.
	 * @param ObjectService $objectService Finds the agents that point at this application.
	 * @param IAppManager $appManager Detects whether hermiq is installed, so the fallback lookup
	 *                                stays optional (hermiq is not an Buildiq dependency).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FlowMapper $flowMapper,
		private readonly ObjectService $objectService,
		private readonly IAppManager $appManager,
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
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-composition-bindings/spec.md#requirement-a-binding-must-be-resolved-against-the-openregister-flow-entity
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-export-completeness/spec.md#requirement-an-unresolvable-binding-must-be-reported-not-silently-dropped
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
					'Buildiq export: flows binding "' . $uuid . '" did not resolve to a flow — not bundled: '
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
			$agents = $this->resolveAgents(applicationSlug: $applicationSlug);
		} catch (Throwable $e) {
			$this->logger->info('Buildiq export: could not read agents for "' . $applicationSlug . '": ' . $e->getMessage());
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
			$record = $agent;
			if (is_array($agent) === false) {
				$record = $agent->jsonSerialize();
			}

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
	 * Resolve an application's agents: buildiq's own schema first, falling
	 * back to hermiq's register only when that finds nothing AND hermiq is
	 * installed. A FALLBACK, not a merge — the common case (agents in
	 * buildiq's own store) stays a single query.
	 *
	 * A failure in buildiq's own lookup propagates to the caller (fatal to
	 * {@see self::bundleAgents()}, which reports it as a skip); a failure in
	 * the fallback is swallowed by {@see self::findHermiqRegisterAgentsOrEmpty()} —
	 * the fallback is a best-effort extra on a lookup that already succeeded
	 * (with zero results), not a second point of failure for it.
	 *
	 * @param string $applicationSlug The application slug agents must carry.
	 *
	 * @return array<int, mixed> The matching agents (raw `ObjectService::findAll()` shape).
	 *
	 * @throws Throwable When buildiq's own lookup fails.
	 */
	private function resolveAgents(string $applicationSlug): array {
		$agents = $this->findAgentsByApplicationSlug(
			register: self::BUILDIQ_REGISTER,
			schema: self::AGENT_SCHEMA,
			applicationSlug: $applicationSlug
		);

		if ($agents !== [] || $this->appManager->isEnabledForUser(self::HERMIQ_APP_ID) === false) {
			return $agents;
		}

		return $this->findHermiqRegisterAgentsOrEmpty(applicationSlug: $applicationSlug);
	}//end resolveAgents()

	/**
	 * The hermiq-register fallback lookup, guarded the way
	 * {@see SkillChannelDelegate} treats hermiq as optional elsewhere in this
	 * codebase. Never throws: a failed fallback degrades to "no agents found
	 * there either" rather than failing the whole export, because buildiq's
	 * own lookup (the caller) already succeeded.
	 *
	 * @param string $applicationSlug The application slug agents must carry.
	 *
	 * @return array<int, mixed> The matching agents, or `[]` on any failure.
	 */
	private function findHermiqRegisterAgentsOrEmpty(string $applicationSlug): array {
		try {
			return $this->findAgentsByApplicationSlug(
				register: self::HERMIQ_REGISTER,
				schema: self::HERMIQ_AGENT_SCHEMA,
				applicationSlug: $applicationSlug
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Buildiq export: could not read hermiq-register agents for "' . $applicationSlug . '": '
				. $e->getMessage()
			);

			return [];
		}
	}//end findHermiqRegisterAgentsOrEmpty()

	/**
	 * Find the agents pointing at `$applicationSlug` in one register/schema.
	 *
	 * Both `$register` and `$schema` MUST be slugs, never numeric ids — see
	 * {@see self::BUILDIQ_REGISTER} for why a numeric id is not portable.
	 * `ObjectService::findAll()` resolves a string filter value through the
	 * register/schema mappers' slug lookup.
	 *
	 * @param string $register Register slug to search.
	 * @param string $schema Schema slug to search.
	 * @param string $applicationSlug The application slug agents must carry.
	 *
	 * @return array<int, mixed> The matching agents (raw `ObjectService::findAll()` shape).
	 *
	 * @throws Throwable When the underlying lookup fails; left to the caller to decide
	 *                   whether that is fatal (buildiq's own store) or a degraded
	 *                   fallback (hermiq's store).
	 */
	private function findAgentsByApplicationSlug(string $register, string $schema, string $applicationSlug): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'applicationSlug' => $applicationSlug,
				],
				'limit' => 200,
			]
		);
	}//end findAgentsByApplicationSlug()

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
