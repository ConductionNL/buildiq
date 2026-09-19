<?php

/**
 * Buildiq Register Row Reader
 *
 * Reads every row held in an OpenRegister register, across all of the
 * schemas that register owns.
 *
 * OpenRegister's `ObjectService::searchObjects()` resolves its target
 * table from the PAIR `@self.register` + `@self.schema`. A query that
 * names only the register satisfies neither half of the mapper's
 * `if ($registerId !== null && $schemaId !== null)` guard, so it falls
 * through to a logged warning and an empty list. There is no error, no
 * exception and no signal at the call site: a register holding a
 * thousand rows answers exactly as one holding none.
 *
 * Three buildiq callers asked the register-only question and read the
 * empty answer as fact: the export bundler wrote an empty seed-data
 * fixture for a binding whose includeData the admin had just switched
 * on, and version promotion neither wiped nor copied a single row while
 * reporting the promotion published. This class is the one place that
 * knows the question has to be asked per schema.
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
 * @spec openspec/changes/register-row-reads-name-a-schema/tasks.md#task-1
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\RegisterRowReadFailedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use Throwable;

/**
 * Reads every row of a register by asking OpenRegister once per schema.
 *
 * A read that fails RAISES. The callers this replaces each treated a
 * failed read as "the register is empty", which is the same shape as the
 * defect it fixes: a caller cannot tell an empty register from one it was
 * unable to read unless the two answer differently.
 *
 * @spec openspec/changes/register-row-reads-name-a-schema/tasks.md#task-1
 */
class RegisterRowReader {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Every row held in `$register`, across every schema it owns.
	 *
	 * The register's own schema list is the authority for which schemas to
	 * ask about. A register that owns no schemas holds no rows, and that
	 * is the one case where an empty list is a real answer.
	 *
	 * @param Register $register       The register to read.
	 * @param bool     $_rbac          Whether to apply RBAC checks.
	 * @param bool     $_multitenancy  Whether to apply the multi-tenancy filter.
	 *
	 * @return array<int, mixed> The rows, as OpenRegister returned them.
	 *
	 * @throws RegisterRowReadFailedException When a schema's rows cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror searchObjects() upstream.
	 *
	 * @spec openspec/changes/register-row-reads-name-a-schema/tasks.md#task-1
	 */
	public function rowsInRegister(
		Register $register,
		bool $_rbac=true,
		bool $_multitenancy=true
	): array {
		$registerId = $register->getId();
		$rows       = [];

		foreach ((array)$register->getSchemas() as $schemaId) {
			if ($schemaId === null || $schemaId === '') {
				continue;
			}

			try {
				$found = $this->objectService->searchObjects(
					query: [
						'@self' => [
							'register' => $registerId,
							'schema'   => $schemaId,
						],
					],
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);
			} catch (Throwable $e) {
				// RAISE. A caller that reads a failed search as an empty
				// register deletes nothing, copies nothing and exports
				// nothing, and calls each of those a success.
				throw new RegisterRowReadFailedException(
					registerSlug: (string)$register->getSlug(),
					schemaId: (string)$schemaId,
					previous: $e
				);
			}//end try

			if (is_array($found) === false) {
				continue;
			}

			foreach ($found as $row) {
				$rows[] = $row;
			}
		}//end foreach

		return $rows;
	}//end rowsInRegister()
}//end class
