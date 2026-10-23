<?php

/**
 * Evaluate a live form's calculations and blocking check again on save.
 *
 * What a person's browser computed while they filled in the form is never
 * trusted: before the record is stored, every calculated field is evaluated
 * again with the record's own values and overwritten, and a blocking
 * eligibility check that fails refuses the save with its explanation
 * (REQ-BQLV-005). These evaluations are not previews, so they are logged.
 *
 * A calculation or check that cannot be evaluated refuses the save: storing
 * the browser's value instead would be exactly the hole this closes.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://buildiq.nl
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes calculated fields and enforces blocking checks for a save.
 *
 * @psalm-import-type LiveBinding from FormLiveBindingIndex
 * @phpstan-import-type LiveBinding from FormLiveBindingIndex
 */
class FormLiveValuesRecomputer {

	/**
	 * Constructor.
	 *
	 * @param RuleEngineService $engine The rule engine
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleEngineService $engine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompute an object's calculated fields and run its blocking checks.
	 *
	 * @param array<string,mixed> $object The object data being saved
	 * @param list<LiveBinding> $bindings The live forms writing here
	 *
	 * @return array{changes:array<string,mixed>,refusal:array{code:string,message:string}|null}
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function recompute(array $object, array $bindings): array {
		$changes = [];
		foreach ($bindings as $binding) {
			foreach ($binding['calculate'] as $calc) {
				$payload = array_intersect_key($object, array_flip($calc['inputs']));
				$result = $this->evaluate(ruleSet: $calc['ruleSet'], payload: $payload);
				if ($result === null) {
					return $this->refuse(
						code: 'buildiq.form_live.calculation_failed',
						message: 'The value of ' . $calc['field'] . ' could not be worked out, so the record was not saved.'
					);
				}

				$changes[$calc['field']] = ($result[$calc['output']] ?? null);
			}

			$check = $binding['eligibility'];
			if (is_array($check) === false) {
				continue;
			}

			$result = $this->evaluate(ruleSet: (string)$check['ruleSet'], payload: array_merge($object, $changes));
			if ($result === null) {
				return $this->refuse(
					code: 'buildiq.form_live.check_failed',
					message: 'The conditions of this form could not be checked, so the record was not saved.'
				);
			}

			$passWhen = (array)($check['passWhen'] ?? []);
			if ((string)($result[(string)($passWhen['output'] ?? '')] ?? '') !== (string)($passWhen['equals'] ?? '')) {
				$explanation = $result[(string)($check['explainWith'] ?? '')] ?? '';
				if (is_string($explanation) === false || $explanation === '') {
					$explanation = 'The conditions of this form are not met.';
				}

				return $this->refuse(code: 'buildiq.form_live.not_eligible', message: $explanation);
			}
		}//end foreach

		return ['changes' => $changes, 'refusal' => null];
	}//end recompute()

	/**
	 * Evaluate a rule set, logged; null when it cannot be evaluated.
	 *
	 * @param string $ruleSet The rule set slug
	 * @param array<string,mixed> $payload The values it reads
	 *
	 * @return array<string,mixed>|null
	 */
	private function evaluate(string $ruleSet, array $payload): ?array {
		try {
			$outcome = $this->engine->evaluate(
				ruleSetSlug: $ruleSet,
				payload: $payload,
				version: null,
				dryRun: false,
				maskPii: true,
				preview: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: a live form rule set could not be evaluated on save.',
				['ruleSet' => $ruleSet, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($outcome['errors'] !== []) {
			$this->logger->warning(
				'Buildiq: a live form rule set reported errors on save.',
				['ruleSet' => $ruleSet, 'errors' => $outcome['errors']]
			);
			return null;
		}

		return $outcome['result'];
	}//end evaluate()

	/**
	 * A refusal outcome.
	 *
	 * @param string $code The error code
	 * @param string $message What the person is told
	 *
	 * @return array{changes:array<string,mixed>,refusal:array{code:string,message:string}}
	 */
	private function refuse(string $code, string $message): array {
		return ['changes' => [], 'refusal' => ['code' => $code, 'message' => $message]];
	}//end refuse()
}//end class
