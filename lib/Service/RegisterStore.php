<?php

/**
 * Buildiq Register Store
 *
 * OpenRegister's object service, register and schema mappers and audit trail. Most services and
 * controllers reach OpenRegister through the same three or four collaborators. Taking them as one
 * object keeps their constructors short.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * OpenRegister's object service, register and schema mappers and audit trail.
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-9
 */
final class RegisterStore {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param RegisterMapper $registerMapper OpenRegister register mapper.
	 * @param SchemaMapper $schemaMapper OpenRegister schema mapper.
	 * @param AuditTrailMapper|null $auditTrailMapper OpenRegister audit trail writer, null until OpenRegister is loaded.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ObjectServiceInterface $objectService,
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly ?AuditTrailMapper $auditTrailMapper = null,
	) {
	}//end __construct()
}//end class
