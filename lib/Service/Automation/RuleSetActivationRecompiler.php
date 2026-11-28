<?php

/**
 * Buildiq RuleSetActivationRecompiler
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A flow built from an automation runs the rule set version it was compiled
 * against: the automation's `provenance.ruleSetVersions` names each rule set it
 * uses and that version (logic-automation-actions-that-run D7). When a new
 * version of a rule set is activated, every automation whose provenance names
 * that rule set at another version is compiled again, so its flow and its
 * provenance follow the active version (REQ-BQAR-005).
 *
 * A recompile that fails is logged and counted, never thrown: activating a rule
 * set must not fail because one automation that uses it cannot compile.
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
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationWriteServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recompiles the automations that use a rule set when a new version of it is activated.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */
class RuleSetActivationRecompiler {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface             $objectService Reads the automations.
	 * @param AutomationCompilerServiceInterface $compiler      Compiles and applies an automation.
	 * @param AutomationWriteServiceInterface    $writeService  Stores the recompiled automation.
	 * @param LoggerInterface                    $logger        Logs a recompile that fails.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AutomationCompilerServiceInterface $compiler,
		private readonly AutomationWriteServiceInterface $writeService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompile every automation that copies a rule set at a version other than the active one.
	 *
	 * @param string $slug    The rule set just activated.
	 * @param string $version Its new active version.
	 *
	 * @return array{recompiled: int, failed: int}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords is a pure coercion with no collaborators.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public function recompileReferencing(string $slug, string $version): array {
		$counts = ['recompiled' => 0, 'failed' => 0];
		if (trim($slug) === '') {
			return $counts;
		}

		try {
			$automations = AutomationRecords::all(objectService: $this->objectService);
		} catch (Throwable $e) {
			$this->logger->warning('RuleSetActivationRecompiler: automations could not be read after activating "' . $slug . '": ' . $e->getMessage());
			return $counts;
		}

		foreach ($automations as $automation) {
			if (self::copiesOtherVersion(automation: $automation, slug: $slug, version: $version) === false) {
				continue;
			}

			$key = 'failed';
			if ($this->recompile(automation: $automation) === true) {
				$key = 'recompiled';
			}

			$counts[$key]++;
		}

		return $counts;
	}//end recompileReferencing()

	/**
	 * Whether an automation's provenance names the rule set at another version.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $slug       The rule set.
	 * @param string               $version    The version now active.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public static function copiesOtherVersion(array $automation, string $slug, string $version): bool {
		$provenance = $automation['provenance'] ?? null;
		if (is_array($provenance) === false) {
			return false;
		}

		foreach ((array)($provenance['ruleSetVersions'] ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['slug'] ?? '') === $slug) {
				return (string)($entry['version'] ?? '') !== $version;
			}
		}

		return false;
	}//end copiesOtherVersion()

	/**
	 * Compile, apply and store one automation.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return bool Whether it was recompiled and stored.
	 */
	private function recompile(array $automation): bool {
		$uuid = (string)($automation['id'] ?? ($automation['uuid'] ?? ''));
		try {
			$plan  = $this->compiler->compile(automation: $automation);
			$prior = (array)$automation['provenance'];
			$automation['provenance'] = $this->compiler->apply(automation: $automation, plan: $plan, priorProvenance: $prior);
			$this->writeService->saveCompiled(automation: $automation, uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning('RuleSetActivationRecompiler: could not recompile "' . $uuid . '": ' . $e->getMessage());
			return false;
		}

		return true;
	}//end recompile()
}//end class
