<?php

/**
 * Buildiq SchemaRowReader
 *
 * Reads every object of one schema in one register, normalised to arrays and in
 * stored order. The versioning repair steps enumerate what they migrate this
 * way; each resolves its own register and schema ids first and reports a
 * missing one in its own words.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
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

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Every row of one register and schema.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-26
 */
final class SchemaRowReader {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Every row of the schema in the register, normalised, in stored order.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-26
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function rows(int $registerId, int $schemaId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
			]
		);

		$normalised = [];
		foreach ($rows as $row) {
			$normalised[] = ObjectRecord::toArray(object: $row);
		}

		return $normalised;
	}//end rows()
}//end class
