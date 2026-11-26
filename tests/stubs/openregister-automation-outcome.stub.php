<?php

/**
 * Stubs for OpenRegister's automation outcome records
 * (a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did).
 *
 * Mirrors the shapes of openregister/lib/Db/AutomationRecord.php,
 * lib/Db/AutomationRecordFilter.php, lib/Service/AutomationRecordService.php
 * and lib/Service/AutomationOutcome.php that buildiq's outcome reader calls,
 * so it can be analysed and unit-tested against an OpenRegister that predates
 * them. Every declaration is guarded with an autoloading class_exists(), so
 * the real classes answer first whenever they are on the autoload path.
 *
 * @category Stub
 * @package  OCA\Buildiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db {

	if (class_exists(AutomationRecord::class) === false) {
		/**
		 * Stub AutomationRecord: one notification run or lifecycle action run.
		 *
		 * The stub carries the outcome shape it answers as, so a test states
		 * what OpenRegister would have answered.
		 */
		class AutomationRecord {

			/**
			 * @param array<string, mixed> $outcome The outcome shape this record answers as.
			 */
			public function __construct(
				public readonly array $outcome = [],
			) {
			}
		}
	}

	if (class_exists(AutomationRecordFilter::class) === false) {
		/**
		 * Stub AutomationRecordFilter: which records to read.
		 */
		final class AutomationRecordFilter {

			/**
			 * @param array<string, mixed> $params The parameters it was built from.
			 */
			public function __construct(
				public readonly array $params = [],
			) {
			}

			/**
			 * The filter of the outcomes route (`keyPrefix`, `kind`, `since`, `until`, `limit`, `offset`).
			 *
			 * @param array<string, mixed> $params The parameters.
			 *
			 * @return self
			 */
			public static function fromOutcomeParams(array $params): self {
				return new self(params: $params);
			}
		}
	}
}

namespace OCA\OpenRegister\Service {

	use OCA\OpenRegister\Db\AutomationRecord;
	use OCA\OpenRegister\Db\AutomationRecordFilter;

	if (class_exists(AutomationRecordService::class) === false) {
		/**
		 * Stub AutomationRecordService: reads records under the current user.
		 */
		class AutomationRecordService {

			/**
			 * The records the filter matches, newest first.
			 *
			 * @param AutomationRecordFilter $filter The filter.
			 *
			 * @return list<AutomationRecord>
			 */
			public function find(AutomationRecordFilter $filter): array {
				unset($filter);
				return [];
			}
		}
	}

	if (class_exists(AutomationOutcome::class) === false) {
		/**
		 * Stub AutomationOutcome: one record in the outcome shape.
		 */
		final class AutomationOutcome {

			/**
			 * The record as `{kind, key, at, subject, outcome, reason, recipients?}`.
			 *
			 * @param AutomationRecord $record The record.
			 *
			 * @return array<string, mixed>
			 */
			public static function fromRecord(AutomationRecord $record): array {
				return $record->outcome;
			}
		}
	}
}
