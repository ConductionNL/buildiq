<?php

/**
 * Buildiq Schema Reference Resolver
 *
 * Answers whether a schema id/slug is still claimed by some OpenRegister
 * register, across the whole instance — the check ApplicationDeletionService
 * needs before it may permanently remove a schema DEFINITION a deleted
 * register owned.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Resolves whether a schema is still referenced by any register.
 */
class SchemaReferenceResolver {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper OR register lookup (full scan)
	 * @param SchemaMapper $schemaMapper OR slug-to-id resolution
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Whether a schema id/slug is still claimed by a register.
	 *
	 * Matches by string against $referenced (already-resolved ids). A
	 * non-numeric $schemaId is additionally resolved to its real id(s) via
	 * {@see SchemaMapper::findIdsBySlugs()}, so a schema this register held by
	 * slug still matches a register that claims the same schema by numeric id.
	 * A slug is not unique across apps, so any resolved id counting as
	 * referenced is deliberately conservative — an ambiguous case is left
	 * alone rather than treated as unclaimed.
	 *
	 * @param mixed $schemaId The schema id or slug to check.
	 * @param array<int,string> $referenced Ids other registers still hold.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/application-detail-ui/spec.md
	 */
	public function isReferenced(mixed $schemaId, array $referenced): bool {
		if (in_array((string)$schemaId, $referenced, true) === true) {
			return true;
		}

		if (is_numeric($schemaId) === true) {
			return false;
		}

		foreach ($this->schemaMapper->findIdsBySlugs([(string)$schemaId]) as $ids) {
			if (array_intersect($ids, $referenced) !== []) {
				return true;
			}
		}

		return false;
	}//end isReferenced()

	/**
	 * Collect every schema id still claimed by a register, as strings.
	 *
	 * Unfiltered on purpose (`_rbac`/`_multitenancy` off): a schema shared with a
	 * register the caller cannot see is still shared, and a filtered scan would
	 * report it as unreferenced and delete it out from under that register.
	 *
	 * A slug entry in `register.schemas` is resolved to its real id(s) via
	 * {@see SchemaMapper::findIdsBySlugs()} so it is comparable to a numeric id
	 * another register recorded for the same schema.
	 *
	 * @throws Throwable When the register scan fails; the caller must not treat
	 *                    a failed scan as an empty (nothing-referenced) result.
	 *
	 * @return array<int,string> Schema ids, deduplicated.
	 *
	 * @spec openspec/specs/application-detail-ui/spec.md
	 */
	public function heldByOtherRegisters(): array {
		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

		$ids = [];
		$slugs = [];
		foreach ($registers as $register) {
			foreach (($register->getSchemas() ?? []) as $schemaId) {
				if (is_numeric($schemaId) === true) {
					$ids[] = (string)$schemaId;
					continue;
				}

				$slugs[] = (string)$schemaId;
			}
		}

		if ($slugs !== []) {
			foreach ($this->schemaMapper->findIdsBySlugs($slugs) as $matchingIds) {
				foreach ($matchingIds as $id) {
					$ids[] = $id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end heldByOtherRegisters()
}//end class
