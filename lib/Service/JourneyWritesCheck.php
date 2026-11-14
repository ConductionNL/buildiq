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
	 * Finding code: a write's `forEach` is not the key of an answer (forms-multi-product-request REQ-BQMP-001).
	 *
	 * @var string
	 */
	public const FOREACH_INVALID = 'write-foreach-invalid';

	/**
	 * Finding code: `targetBy` or `targets` on a write that does not repeat (REQ-BQMP-002).
	 *
	 * @var string
	 */
	public const TARGETS_WITHOUT_FOREACH = 'write-targets-without-foreach';

	/**
	 * Finding code: `targets` without the item field that picks one (REQ-BQMP-002).
	 *
	 * @var string
	 */
	public const TARGET_BY_MISSING = 'write-target-by-missing';

	/**
	 * Finding code: a product's target names no register and schema (REQ-BQMP-002).
	 *
	 * @var string
	 */
	public const TARGET_INVALID = 'write-target-invalid';

	/**
	 * Finding code: a bundle write that repeats; a bundle is written once (REQ-BQMP-003).
	 *
	 * @var string
	 */
	public const BUNDLE_REPEATS = 'write-bundle-repeats';

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
					$this->repeatRefusals(write: $write),
					$this->destinationFindings(write: $write, mapping: $mapping, options: $options)
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
	 * What OpenRegister's validator finds on the write's mapping: against the
	 * write's own destination, or, for a write that repeats with `targets`,
	 * against the schema of every product, each finding naming its product.
	 *
	 * @param array<string, mixed> $write   The write.
	 * @param array<string, mixed> $mapping The mapping, with placeholders.
	 * @param array<string, string> $options The validator options.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 *
	 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-each-item-goes-to-its-products-target-req-bqmp-002
	 */
	private function destinationFindings(array $write, array $mapping, array $options): array {
		$targets = $write['targets'] ?? null;
		if (is_array($targets) === false || $targets === [] || array_is_list($targets) === true) {
			return $this->destinations->check(
				register: (string)($write['register'] ?? ''),
				schema: (string)($write['schema'] ?? ''),
				mapping: $mapping,
				options: $options
			);
		}

		$out = [];
		foreach ($targets as $product => $target) {
			if (is_array($target) === false || (string)($target['register'] ?? '') === '' || (string)($target['schema'] ?? '') === '') {
				$out[] = [
					'product'  => (string)$product,
					'property' => '',
					'code'     => self::TARGET_INVALID,
					'message'  => sprintf('%s: the target needs a register and a schema.', $product),
				];
				continue;
			}

			$found = $this->destinations->check(
				register: (string)$target['register'],
				schema: (string)$target['schema'],
				mapping: $mapping,
				options: $options
			);
			foreach ($found as $finding) {
				$finding['message'] = sprintf('%s: %s', $product, (string)($finding['message'] ?? ''));
				$out[] = ['product' => (string)$product] + $finding;
			}
		}

		return $out;
	}//end destinationFindings()

	/**
	 * The refusals on a write's repeat settings: `forEach` names an answer, `targetBy`
	 * and `targets` only on a write that repeats and only together, and a bundle is
	 * written once.
	 *
	 * Whether the `forEach` answer is a list asked before the write, and whether every
	 * product value has a target, is checked in the designer, which has the forms.
	 *
	 * @param array<string, mixed> $write The write.
	 *
	 * @return array<int, array<string, string>> The refusals.
	 *
	 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-a-write-can-repeat-per-list-item-req-bqmp-001
	 */
	private function repeatRefusals(array $write): array {
		$refusals = [];
		$forEach  = $write['forEach'] ?? null;
		$repeats  = is_string($forEach) === true && trim($forEach) !== '';
		if ($forEach !== null && $repeats === false) {
			$refusals[] = ['property' => '', 'code' => self::FOREACH_INVALID, 'message' => 'A repeating write has to name the list answer it repeats over.'];
		}

		$hasTargets = isset($write['targets']) === true || isset($write['targetBy']) === true;
		if ($hasTargets === true && $repeats === false) {
			$refusals[] = [
				'property' => '',
				'code'     => self::TARGETS_WITHOUT_FOREACH,
				'message'  => 'Only a write that repeats over a list can send each item to its own target.',
			];
		}

		if (isset($write['targets']) === true && trim((string)($write['targetBy'] ?? '')) === '') {
			$refusals[] = [
				'property' => '',
				'code'     => self::TARGET_BY_MISSING,
				'message'  => 'Name the item field that picks the target, usually the product.',
			];
		}

		if (($write['bundle'] ?? false) === true && $repeats === true) {
			$refusals[] = ['property' => '', 'code' => self::BUNDLE_REPEATS, 'message' => 'A bundle is written once; it cannot repeat over a list.'];
		}

		return $refusals;
	}//end repeatRefusals()

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
