<?php

/**
 * Journey Writes Check
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Judges every `writes[]` entry of a journey before it is saved
 * (journey-designer, ADR-085 §6). `writes[]` is the field of a journey that
 * creates objects, so an invalid mapping found at submit time is found by a
 * citizen. Each write goes through FormDestinationCheck, which hands it to
 * OpenRegister's form destination validator: the validator the submit path
 * runs, so the designer cannot judge a mapping differently (decision 179).
 *
 * A `{ "$write": "<as>" }` value points at an earlier write of the same
 * submit. It must name a write made before it, and it is judged with the
 * placeholder id the submit path puts in its place, so a reference to an
 * organisation written one write earlier validates as the uuid it will be.
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
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\JourneyWritesCheckInterface;

/**
 * Every finding on a journey's writes, each tagged with its step and write.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 */
class JourneyWritesCheck implements JourneyWritesCheckInterface {

	/**
	 * The id OpenRegister's submit path puts in place of a `{ "$write": "<as>" }` value before it validates.
	 *
	 * @var string
	 */
	public const PENDING_ID = '00000000-0000-4000-8000-000000000000';

	/**
	 * Finding code: a reference names no write made before it (OpenRegister's code for the same refusal).
	 *
	 * @var string
	 */
	public const WRITE_UNKNOWN = 'write-unknown';

	/**
	 * Finding code: a step's `writes` is not a list of writes.
	 *
	 * @var string
	 */
	public const WRITES_INVALID = 'writes-invalid';

	/**
	 * Constructor.
	 *
	 * @param FormDestinationCheck $destinations Hands each write to OpenRegister's validator.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
	 */
	public function __construct(
		private readonly FormDestinationCheck $destinations,
	) {
	}//end __construct()

	/**
	 * Every finding on the journey's writes; empty when every destination accepts its mapping.
	 *
	 * @param array<string, mixed> $journey The journey: `steps[]`, each with optional `writes[]`, and `audience`.
	 *
	 * @return array<int, array<string, mixed>> The findings, each with `step` and `write` added.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
	 */
	public function findings(array $journey): array {
		$options = $this->optionsOf(journey: $journey);
		$made    = [];
		$out     = [];
		$index   = 0;

		foreach ($this->stepsOf(journey: $journey) as $step) {
			$stepId = (string)($step['id'] ?? '');
			$writes = ($step['writes'] ?? []);
			if (is_array($writes) === false || array_is_list($writes) === false) {
				$out[] = [
					'step'     => $stepId,
					'write'    => '',
					'property' => '',
					'code'     => self::WRITES_INVALID,
					'message'  => 'The writes of a step have to be a list of writes.',
				];
				continue;
			}

			foreach ($writes as $write) {
				if (is_array($write) === false) {
					$write = [];
				}

				$as = (string)($write['as'] ?? ('write' . $index));
				$index++;

				$mapping = [];
				if (is_array($write['mapping'] ?? null) === true) {
					$mapping = $write['mapping'];
				}

				[$mapping, $refusals] = $this->withPlaceholders(mapping: $mapping, made: $made);
				$findings = array_merge(
					$refusals,
					$this->destinations->check(
						register: (string)($write['register'] ?? ''),
						schema: (string)($write['schema'] ?? ''),
						mapping: $mapping,
						options: $options
					)
				);
				foreach ($findings as $finding) {
					$out[] = ['step' => $stepId, 'write' => $as] + $finding;
				}

				$made[$as] = true;
			}//end foreach
		}//end foreach

		return $out;
	}//end findings()

	/**
	 * The mapping with each `{ "$write": "<as>" }` fixed value replaced by the placeholder id,
	 * and a refusal for each that names no earlier write.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 * @param array<string, true>  $made    The writes made before this one, by `as`.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, string>>} The mapping and the refusals.
	 */
	private function withPlaceholders(array $mapping, array $made): array {
		$fixed = $mapping['fixed'] ?? null;
		if (is_array($fixed) === false) {
			return [$mapping, []];
		}

		$refusals = [];
		foreach ($fixed as $property => $value) {
			if (is_array($value) === false || count($value) !== 1 || is_string($value['$write'] ?? null) === false) {
				continue;
			}

			if (isset($made[$value['$write']]) === false) {
				$refusals[] = [
					'property' => (string)$property,
					'code'     => self::WRITE_UNKNOWN,
					'message'  => sprintf('Write "%s" is referenced before it is made.', $value['$write']),
				];
			}

			$fixed[$property] = self::PENDING_ID;
		}

		$mapping['fixed'] = $fixed;

		return [$mapping, $refusals];
	}//end withPlaceholders()

	/**
	 * The journey's steps that are objects.
	 *
	 * @param array<string, mixed> $journey The journey.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 */
	private function stepsOf(array $journey): array {
		$steps = $journey['steps'] ?? [];
		if (is_array($steps) === false) {
			return [];
		}

		return array_values(array_filter($steps, 'is_array'));
	}//end stepsOf()

	/**
	 * The validator options: the audience when the journey is not internal.
	 *
	 * @param array<string, mixed> $journey The journey.
	 *
	 * @return array<string, string> The options.
	 */
	private function optionsOf(array $journey): array {
		$audience = (string)($journey['audience'] ?? '');
		if (in_array($audience, ['public', 'authenticated'], true) === true) {
			return ['audience' => $audience];
		}

		return [];
	}//end optionsOf()
}//end class
