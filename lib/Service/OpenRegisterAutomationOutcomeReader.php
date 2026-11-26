<?php

/**
 * Buildiq OpenRegisterAutomationOutcomeReader
 *
 * Reads OpenRegister's automation outcome records in-process, under the
 * current user, the same way `GET /api/automation-outcomes` answers them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Service\AutomationOutcome;
use OCA\OpenRegister\Service\AutomationRecordService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The outcome reader over OpenRegister's AutomationRecordService.
 *
 * OpenRegister applies its own access rule: an admin reads every record,
 * anyone else only records of objects they may read. Reading never throws:
 * an OpenRegister without outcome records, or a read that fails, answers an
 * empty list and the runs dialog keeps its label.
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
class OpenRegisterAutomationOutcomeReader {

	/**
	 * The most outcomes one read asks for.
	 */
	public const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's record service.
	 * @param LoggerInterface    $logger    Logs a read that failed.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The outcomes whose key starts with the prefix, newest first.
	 *
	 * Each outcome is `{kind, key, at, subject: {register, schema, id}, outcome, reason, recipients?}`,
	 * the shape OpenRegister's own `GET /api/automation-outcomes` answers.
	 *
	 * @param string $keyPrefix The key prefix.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationOutcome::fromRecord() is OpenRegister's one mapping to the outcome shape; it has no instance form.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public function outcomes(string $keyPrefix): array {
		if ($keyPrefix === '' || $this->available() === false) {
			return [];
		}

		try {
			$service = $this->container->get(AutomationRecordService::class);
			if (($service instanceof AutomationRecordService) === false) {
				return [];
			}

			$records = $service->find(new AutomationRecordFilter(keyPrefix: $keyPrefix, limit: self::READ_LIMIT));

			$outcomes = [];
			foreach ($records as $record) {
				$outcomes[] = AutomationOutcome::fromRecord(record: $record);
			}

			return $outcomes;
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: automation outcomes could not be read from OpenRegister', ['exception' => $e->getMessage()]);
			return [];
		}//end try
	}//end outcomes()

	/**
	 * Whether this OpenRegister records automation outcomes.
	 *
	 * @return bool
	 */
	private function available(): bool {
		return class_exists(AutomationRecordService::class) === true
			&& class_exists(AutomationRecordFilter::class) === true
			&& class_exists(AutomationOutcome::class) === true;
	}//end available()
}//end class
