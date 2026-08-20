<?php

/**
 * OpenBuild AgentsController
 *
 * REST surface for the agent-workspace change (spec `agent-workspace`).
 * Agent CRUD itself goes through OpenRegister's generic REST surface
 * (`/apps/openregister/api/objects/openbuild/agent`, ADR-022 — mirrors
 * `AutomationsController`'s posture for the `automation` object), because
 * Agent create/edit/delete carries no OpenBuild-specific side effect. This
 * controller owns exactly the one row-level-RBAC-sensitive read the generic
 * OR REST surface cannot safely serve:
 *
 *   GET /api/agents/{uuid}/runs — an agent's transparent run-history list.
 *
 * `AgentRun` visibility is owners/editors-only on the agent's parent
 * Application (design.md Open Questions — "matching the existing
 * openbuild-rbac posture for anything execute-adjacent"), enforced
 * server-side here BEFORE any row is read. Without this per-object guard, a
 * `#[NoAdminRequired]` endpoint returning another Application's AgentRun
 * rows by uuid alone would be a textbook IDOR (hydra-gate-no-admin-idor) —
 * the same shape `routes.php`'s `applications#listMine` comment documents
 * for the Application list, and exactly why AgentRun rows are never served
 * through the generic OR REST surface either.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller serving the Agent run-history read surface.
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */
class AgentsController extends Controller {
	/**
	 * Shared OpenBuild register slug.
	 */
	private const REGISTER_SLUG = 'openbuild';

	/**
	 * Schema slug of the Agent object.
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Schema slug of the AgentRun object.
	 */
	private const AGENT_RUN_SCHEMA = 'agentRun';

	/**
	 * Roles allowed to view an agent's run history — matches
	 * `CopilotService::assertWriteRoleOnApp()`'s posture for anything
	 * execute-adjacent (design.md Open Questions).
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param RegisterMapper $registerMapper Resolves register slugs to ids.
	 * @param SchemaMapper $schemaMapper Resolves schema slugs to ids.
	 * @param PermissionResolver $permissionResolver Shared permission-grammar resolver.
	 * @param IGroupManager $groupManager Group manager (admin bypass logging).
	 * @param IUserSession $userSession Current user session.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionResolver $permissionResolver,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/agents/{uuid}/runs — an agent's transparent run-history list,
	 * newest first (agent-workspace spec "Every agent run is transparently
	 * logged and reviewable").
	 *
	 * @param string $uuid The Agent object uuid.
	 *
	 * @return JSONResponse 200 with the ordered `AgentRun` list, or an error envelope.
	 *
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
	 */
	#[NoAdminRequired]
	public function runs(string $uuid): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$agent = $this->loadAgent(uuid: $uuid);
			if ($agent === null) {
				return $this->error(code: 'not_found', detail: 'Agent ' . $uuid . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			$applicationSlug = (string)($agent['applicationSlug'] ?? '');
			$application = $this->loadApplication(slug: $applicationSlug);
			if ($application === null) {
				return $this->error(code: 'not_found', detail: 'Application ' . $applicationSlug . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			$permissions = $this->orArray(value: $application['permissions'] ?? null);
			$userGroups = $this->permissionResolver->resolveUserGroups(user: $user);
			$allowed = $this->permissionResolver->matchesCaller(
				permissions: $permissions,
				caller: $user,
				userGroups: $userGroups,
				allowAdminBypass: true,
				roles: self::READ_ROLES
			);

			if ($allowed === false) {
				return $this->error(code: 'insufficient_permission', detail: null, status: Http::STATUS_FORBIDDEN);
			}

			if ($this->groupManager->isAdmin($user->getUID()) === true) {
				$this->logger->info(
					'OpenBuild AgentsController: rbac.admin_bypass',
					['actor' => $user->getUID(), 'applicationSlug' => $applicationSlug]
				);
			}

			$agentUuid = (string)($agent['id'] ?? $agent['uuid'] ?? $uuid);
			$runs = $this->loadRunsForAgent(agentUuid: $agentUuid);

			return new JSONResponse(data: $runs, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error('OpenBuild: AgentsController::runs failed for ' . $uuid . ': ' . $e->getMessage(), ['exception' => $e]);
			return $this->error(code: 'internal_error', detail: $e->getMessage(), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end runs()

	/**
	 * Load every `AgentRun` row belonging to the given agent uuid, newest first.
	 *
	 * OR's `searchObjects` does not reliably filter by relation-string
	 * equality on the `agentId` field (mirrors the same limitation
	 * `ApplicationVersionsController::index()` documents) — fetch every row
	 * in the `agentRun` schema and filter client-side. Cheap: run volume per
	 * agent is expected to stay small (design.md Risks — retention is a
	 * follow-up, not a v1 blocker).
	 *
	 * PROPAGATION IS DELIBERATE, AND IT IS THE SAFER OF THE TWO OPTIONS.
	 * `RegisterMapper::find()` / `SchemaMapper::find()` throw
	 * `DoesNotExistException` when the `openbuild` register or the `agentRun`
	 * schema is not installed, and `\Exception` when their RBAC check refuses
	 * — both are deployment faults, not "this agent has no runs". Swallowing
	 * them here and returning `[]` would render an empty run list that is
	 * indistinguishable from a genuinely empty history, in the one endpoint
	 * whose whole purpose is that agent runs are "transparently logged and
	 * reviewable". So they travel to `runs()`, whose `catch (Throwable)` logs
	 * the cause and answers HTTP 500 — the honest status for a broken
	 * install. This is why the sibling `loadAgent()` / `loadApplication()`
	 * helpers may return `null` and this one may not: there, `null` is
	 * translated to a 404 the caller can act on.
	 *
	 * @param string $agentUuid The Agent object uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the openbuild register or the agentRun schema is absent.
	 * @throws \Exception When the register/schema RBAC check refuses, or the object search fails.
	 */
	private function loadRunsForAgent(string $agentUuid): array {
		$registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
		$schemaId = $this->schemaMapper->find(self::AGENT_RUN_SCHEMA, _multitenancy: false)->getId();

		$rows = $this->objectService->searchObjects(
			query: [
				'@self' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
			]
		);

		$rowsList = [];
		if (is_array($rows) === true) {
			$rowsList = $rows;
		}

		$matching = [];
		foreach ($rowsList as $row) {
			$normalised = $this->normalise(object: $row);
			if ((string)($normalised['agentId'] ?? '') !== $agentUuid) {
				continue;
			}

			$matching[] = $normalised;
		}

		usort(
			$matching,
			static fn (array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''))
		);

		return array_values($matching);
	}//end loadRunsForAgent()

	/**
	 * Load an Agent object by uuid.
	 *
	 * @param string $uuid The Agent object uuid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadAgent(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->normalise(object: $entity);
	}//end loadAgent()

	/**
	 * Load the parent Application by slug.
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadApplication(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $slug, register: self::REGISTER_SLUG, schema: 'application');
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->normalise(object: $entity);
	}//end loadApplication()

	/**
	 * Return `$value` when it is an array, otherwise an empty array.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return array<string,mixed>
	 */
	private function orArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end orArray()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normalise()

	/**
	 * Build a uniform error envelope.
	 *
	 * @param string $code Error code.
	 * @param string|null $detail Optional detail.
	 * @param int $status HTTP status code.
	 *
	 * @return JSONResponse
	 */
	private function error(string $code, ?string $detail, int $status): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end error()
}//end class
