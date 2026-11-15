<?php

/**
 * Authored Row Reader
 *
 * Reads the objects buildiq authors for a consuming app (page layouts,
 * registration forms) out of buildiq's own register: the rows of one authored
 * schema that target one register and schema, in stored order. Built by
 * PageLayoutAuthoringService and RegistrationFormAuthoringService for their
 * own schema.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001)
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;

/**
 * The stored rows of one authored schema, per target register and schema.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 */
final class AuthoredRowReader {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param string $schema The authored schema read, e.g. `pageLayout`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly string $schema,
	) {
	}//end __construct()

	/**
	 * The rows stored for a target register and schema, in stored order.
	 *
	 * @param string $register The target register.
	 * @param string $schema The target schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 */
	public function storedFor(string $register, string $schema): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema($this->schema)
			->findAll(['filters' => ['register' => $register], 'limit' => 500]);

		if (is_array($rows) === false) {
			return [];
		}

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
			) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end storedFor()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 */
	public function registerSlug(): string {
		$slug = $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
		if ($slug === '') {
			// A setting emptied by hand is a misconfiguration, and reading on
			// with no register names every register at once. Buildiq's own
			// register is where these objects live, so that is what is used.
			return 'buildiq';
		}

		return $slug;
	}//end registerSlug()
}//end class
