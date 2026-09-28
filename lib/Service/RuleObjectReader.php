<?php

/**
 * Buildiq RuleObjectReader
 *
 * The one read path for rule-engine objects (rule sets, decision tables,
 * condition-action rules, test cases), scoped to the caller's organisation
 * (REQ-BRE-007, buildiq#988).
 *
 * The `buildiq` register is system-wide, so resolving it through the
 * organisation filter throws for a caller outside the organisation that
 * holds it. That is why the reads used to switch the filter off altogether,
 * which let every signed-in user read and evaluate every rule set on the
 * instance. The filter belongs on the OBJECTS, not on the register: the
 * register and schema are looked up once with the filter off, and the object
 * search then runs with it on, the same split `ApplicationsController`
 * uses for the app routes.
 *
 * The schema is resolved within the register (a slug such as `rule-test-case`
 * is not unique across apps), and an ambiguous or missing schema reads as
 * nothing rather than as some other app's schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Organisation-scoped reads of rule-engine objects in the `buildiq` register.
 *
 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
 */
class RuleObjectReader {

	/**
	 * Resolved `[registerId, schemaId]` per schema slug, or null when the
	 * schema cannot be resolved, for the life of the request.
	 *
	 * @var array<string,array{0:int,1:int}|null>
	 */
	private array $scopes = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService  OpenRegister object service.
	 * @param RegisterMapper         $registerMapper OpenRegister register lookup.
	 * @param SchemaMapper           $schemaMapper   OpenRegister schema lookup.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Objects of a rule-engine schema matching equality filters, as arrays.
	 *
	 * Schema RBAC and the caller's organisation both apply: an object held by
	 * another organisation is not returned. A lookup or search failure reads as
	 * no rows, so a caller answers "not found" rather than falling back to an
	 * unscoped read.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Equality filters on object fields.
	 * @param int|null            $limit   Optional row cap.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function find(string $schema, array $filters, ?int $limit = null): array {
		$scope = $this->scopeOf(schema: $schema);
		if ($scope === null) {
			return [];
		}

		$filters['@self'] = ['register' => $scope[0], 'schema' => $scope[1]];

		try {
			$results = $this->objectService->searchObjects(query: $filters, _rbac: true, _multitenancy: true);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: rule-engine search failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		$rows = [];
		foreach ($results as $row) {
			$normalised = $this->normalise(object: $row);
			if ($normalised !== []) {
				$rows[] = $normalised;
			}
		}

		if ($limit !== null && count($rows) > $limit) {
			$rows = array_slice($rows, 0, $limit);
		}

		return $rows;

	}//end find()

	/**
	 * The `buildiq` register id and the id of one of its schemas.
	 *
	 * The lookup runs with the organisation filter off because the register is
	 * system-wide; only the object search that follows is organisation-scoped.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array{0:int,1:int}|null Null when the register or schema cannot be resolved.
	 */
	private function scopeOf(string $schema): ?array {
		if (array_key_exists($schema, $this->scopes) === true) {
			return $this->scopes[$schema];
		}

		$scope = null;
		try {
			$register = $this->registerMapper->find(RuleEngineService::REGISTER_SLUG, _multitenancy: false);
			$schemaId = $this->schemaIdInRegister(schema: $schema, held: ($register->getSchemas() ?? []));
			if ($schemaId !== null) {
				$scope = [(int)$register->getId(), $schemaId];
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not resolve the rule-engine register or schema',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
		}

		if ($scope === null) {
			$this->logger->warning('Buildiq: rule-engine schema "' . $schema . '" is not resolvable in the buildiq register');
		}

		$this->scopes[$schema] = $scope;
		return $scope;

	}//end scopeOf()

	/**
	 * The id of the schema with this slug that the register holds.
	 *
	 * The register lists schemas by id, or by slug for an older import.
	 *
	 * @param string            $schema The schema slug.
	 * @param array<int,mixed>  $held   The register's schema entries.
	 *
	 * @return int|null Null when none or more than one schema matches.
	 */
	private function schemaIdInRegister(string $schema, array $held): ?int {
		$held = array_map('strval', $held);
		$candidates = [];
		foreach ($this->schemaMapper->findIdsBySlugs([$schema]) as $ids) {
			foreach ($ids as $id) {
				$candidates[] = (string)$id;
			}
		}

		$inRegister = array_values(array_unique(array_intersect($candidates, $held)));
		if ($inRegister === [] && in_array($schema, $held, true) === true && count($candidates) === 1) {
			$inRegister = $candidates;
		}

		if (count($inRegister) !== 1) {
			return null;
		}

		return (int)$inRegister[0];

	}//end schemaIdInRegister()

	/**
	 * Coerce an OpenRegister result entry to a plain array.
	 *
	 * @param mixed $object The result entry.
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
}//end class
