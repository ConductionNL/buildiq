<?php

/**
 * OpenBuild Seed Application Templates Repair Step
 *
 * Idempotent repair step that seeds the four Conduction-curated
 * ApplicationTemplate records on install. Modelled on the canonical
 * SeedHelloWorld.php pattern from chain spec #1 (bootstrap-openbuild).
 *
 * Loads four JSON fixtures from lib/Settings/templates/ and writes them
 * into OpenRegister via the standard ObjectService. Per-slug existence
 * guard makes re-runs no-ops. Validation failure on any fixture fails
 * the repair step loudly (REQ-OBTC-009).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenBuild\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-54
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-57
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Repair;

use OCA\OpenBuild\Service\TemplateSeedService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use RuntimeException;

/**
 * Seed Conduction-curated ApplicationTemplate records.
 *
 * Thin wrapper over {@see TemplateSeedService}: the create-missing-never-
 * overwrite seeding logic is shared with the first-time-setup action endpoint
 * (ADR-042). This step reports the resulting counts and preserves its
 * loud-fail contract (REQ-OBTC-009) by re-raising when the service collects
 * any error.
 *
 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-12
 */
class SeedApplicationTemplates implements IRepairStep {
	/**
	 * Constructor for SeedApplicationTemplates.
	 *
	 * @param TemplateSeedService $seedService The shared idempotent seeding service.
	 *
	 * @return void
	 */
	public function __construct(
		private TemplateSeedService $seedService,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-12
	 */
	public function getName(): string {
		return 'Seed Conduction-curated OpenBuild ApplicationTemplate records';
	}//end getName()

	/**
	 * Run the repair step — delegate to the shared seed service and report.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the service collects any seeding error (loud-fail, REQ-OBTC-009).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-54
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-12
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding ApplicationTemplate records...');

		$result = $this->seedService->seed();

		if (($result['deferred'] ?? false) === true) {
			// Register/schema not provisioned yet (install ordering) — the
			// service deferred instead of failing so a fresh install can
			// complete; the next `occ maintenance:repair` seeds the templates.
			$output->warning(
				'OpenBuild register/schema not available yet — deferring template seeding '
				. '(completes on the next repair once the register exists).'
			);
			return;
		}

		if (empty($result['errors']) === false) {
			foreach ($result['errors'] as $error) {
				$output->warning($error);
			}

			// Preserve the pre-refactor loud-fail contract: a validation or
			// write failure must fail the repair step, not pass silently.
			throw new RuntimeException(
				'OpenBuild template seeding failed: ' . implode('; ', $result['errors'])
			);
		}

		$output->info(
			'OpenBuild template seeding complete. New: ' . $result['seeded']
			. ', updated: ' . $result['updated'] . ', skipped: ' . $result['skipped']
		);
	}//end run()
}//end class
