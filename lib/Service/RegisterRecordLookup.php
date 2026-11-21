<?php

/**
 * Buildiq RegisterRecordLookup
 *
 * Reads single records of Buildiq's own register by schema slug, outside the
 * caller's role and organisation filters. Callers that serve what they read
 * apply their own checks first; this class only finds rows.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds one record of a schema in Buildiq's register.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
 */
class RegisterRecordLookup {
	private const REGISTER = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param LoggerInterface        $logger        Records a failed lookup.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The first record of a schema matching equality filters, or null.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters; `@self` holds metadata filters such as `uuid`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function first(string $schema, array $filters): ?array {
		try {
			$rows = $this->objectService->searchObjectsBySlug(self::REGISTER, $schema, $filters, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->debug('RegisterRecordLookup: lookup in {schema} failed: {message}', ['schema' => $schema, 'message' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return ObjectRecord::arrayOrNull(object: reset($rows));
	}//end first()

	/**
	 * One record of a schema by uuid, or null.
	 *
	 * Read through the slug search with a metadata filter, which every
	 * OpenRegister version of the contract takes the same way.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function byUuid(string $schema, string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		return $this->first(schema: $schema, filters: ['@self' => ['uuid' => $uuid]]);
	}//end byUuid()

	/**
	 * A record found by slug, else by uuid.
	 *
	 * @param string $schema The schema slug.
	 * @param string $ref    The slug or uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function bySlugOrUuid(string $schema, string $ref): ?array {
		return $this->first(schema: $schema, filters: ['slug' => $ref]) ?? $this->byUuid(schema: $schema, uuid: $ref);
	}//end bySlugOrUuid()
}//end class
