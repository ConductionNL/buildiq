<?php

/**
 * Buildiq AutomationRecords
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Reads Automation objects from the shared buildiq register as plain arrays.
 * The automation services and the repair step that walk every automation share
 * this one read, and every automation service coerces an OpenRegister result
 * entry through it.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Automation reads and entry coercion for the automation services.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
 */
final class AutomationRecords {

	/**
	 * The shared buildiq register.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * The Automation schema slug.
	 *
	 * @var string
	 */
	public const AUTOMATION_SCHEMA = 'automation';

	/**
	 * The most automations read in one call.
	 *
	 * @var int
	 */
	public const LIMIT = 10000;

	/**
	 * Every automation in the buildiq register, each as an array.
	 *
	 * Reads without RBAC or organisation scoping, as the system callers that walk
	 * every automation need; a failing read is thrown to the caller.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public static function all(ObjectServiceInterface $objectService): array {
		$rows = $objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER_SLUG, 'schema' => self::AUTOMATION_SCHEMA], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$automations = [];
		foreach ($rows as $row) {
			$automations[] = ObjectRecord::toArray(object: $row);
		}

		return $automations;
	}//end all()

	/**
	 * An OpenRegister result entry as an array (see ObjectRecord::toArray()).
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public static function toArray(mixed $object): array {
		return ObjectRecord::toArray(object: $object);
	}//end toArray()
}//end class
