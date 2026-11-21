<?php

/**
 * Automation Sub-Flow Targets
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Reads the automations that "Run another automation" steps name
 * (logic-shared-flows-and-automations D4): their app, whether they are shared,
 * and the flow they compiled to, so the step can run that flow.
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
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The automations a run-automation step names.
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
class AutomationSubFlowTargets {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the automations.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The named automations, by uuid: slug, name, app, whether shared, and provenance.
	 *
	 * A uuid that resolves to nothing is left out, so the step is refused as unknown.
	 *
	 * @param list<string> $uuids The automation uuids.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
	 */
	public function targets(array $uuids): array {
		$out = [];
		foreach ($uuids as $uuid) {
			$automation = $this->find(uuid: (string)$uuid);
			if ($automation === null) {
				continue;
			}

			$out[(string)$uuid] = [
				'slug' => (string)($automation['slug'] ?? ''),
				'name' => (string)($automation['name'] ?? ''),
				'applicationSlug' => (string)($automation['applicationSlug'] ?? ''),
				'shared' => (($automation['shared'] ?? false) === true),
				'provenance' => self::arrayOf(value: $automation['provenance'] ?? null),
			];
		}

		return $out;
	}//end targets()

	/**
	 * One automation by uuid, or null when it cannot be read.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords is a pure coercion with no collaborators.
	 */
	private function find(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: AutomationRecords::REGISTER_SLUG, schema: AutomationRecords::AUTOMATION_SCHEMA)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationSubFlowTargets could not read automation "' . $uuid . '": ' . $e->getMessage());
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return AutomationRecords::toArray(object: $entity);
	}//end find()

	/**
	 * A value when it is an array, else [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private static function arrayOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOf()
}//end class
