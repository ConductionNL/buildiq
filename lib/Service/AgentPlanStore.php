<?php

/**
 * Where agent plans are stored: `agent-plan` objects in the buildiq register.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores agent plans as `agent-plan` objects in the buildiq register, outside
 * RBAC: who may read, decide or use a plan is AgentPlanService's decision.
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
 */
class AgentPlanStore {

	private const REGISTER_SLUG = 'buildiq';

	private const PLAN_SCHEMA = 'agent-plan';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface        $logger        Logs a plan that could not be stored.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store a plan: a new one without a uuid, or the plan with that uuid.
	 *
	 * @param array<string, mixed> $object The plan's fields.
	 * @param string|null          $uuid   The plan to update, or null for a new plan.
	 *
	 * @return string|null The plan uuid, or null when it could not be stored.
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function put(array $object, ?string $uuid=null): ?string {
		try {
			$saved = $this->objectService->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::PLAN_SCHEMA), uuid: $uuid),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: storing an agent plan failed: ' . $e->getMessage());
			return null;
		}

		return (string)$saved->getUuid();
	}//end put()

	/**
	 * Load a plan by uuid.
	 *
	 * @param string $planId The plan uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function load(string $planId): ?array {
		if ($planId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $planId,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::PLAN_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = self::asArray(item: $entity);
		$data = array_intersect_key($data, array_flip(['appSlug', 'steps', 'status', 'submittedBy', 'decidedBy', 'usedSteps', 'approval']));
		return ['uuid' => (string)$entity->getUuid(), 'data' => $data];
	}//end load()

	/**
	 * An object as an array.
	 *
	 * @param mixed $item An object entity or an array.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function asArray(mixed $item): array {
		if ($item instanceof ObjectEntityInterface) {
			return (array)$item->getObject();
		}

		if (is_array($item) === true) {
			return $item;
		}

		return [];
	}//end asArray()
}//end class
