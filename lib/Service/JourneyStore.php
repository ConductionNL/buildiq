<?php

/**
 * Buildiq JourneyStore
 *
 * Stores a journey of the Journey Designer (journey-designer) as an
 * OpenRegister object in buildiq's `journey` schema. A form step is stored
 * with its reference to the form (`formId`) and never with field definitions
 * of its own, whatever the client sent: field authoring stays in the form
 * editor. The caller decides who may save; JourneyDesignerController lets
 * only administrators through, so the write itself skips OpenRegister's
 * per-object RBAC like the other buildiq stores do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\JourneyStoreInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;
use Throwable;

/**
 * Writes journeys into buildiq's `journey` schema.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
class JourneyStore implements JourneyStoreInterface {

	/**
	 * The register journeys live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'buildiq';

	/**
	 * The schema journeys live in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'journey';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects Writes the register.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
	) {
	}//end __construct()

	/**
	 * Store a journey; a form step keeps only its reference to the form.
	 *
	 * @param array<string, mixed> $journey The journey: `id` (empty for a new one), `title`, `description`, `steps[]`.
	 *
	 * @return array<string, mixed> The journey as stored, with the id OpenRegister gave it.
	 *
	 * @throws RuntimeException When OpenRegister refuses the write.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
	 */
	public function save(array $journey): array {
		$id  = trim((string)($journey['id'] ?? ''));
		$row = $this->storedShape(journey: $journey);

		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		try {
			$entity = $this->objects->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister refused the journey: ' . $e->getMessage(), 0, $e);
		}

		$row['id'] = (string)($entity->getUuid() ?? $id);
		return $row;
	}//end save()

	/**
	 * The journey as it is stored: title, description and the steps, each
	 * form step reduced to its reference.
	 *
	 * @param array<string, mixed> $journey The journey as posted.
	 *
	 * @return array<string, mixed> The row to store.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
	 */
	public function storedShape(array $journey): array {
		$steps = [];
		$given = ($journey['steps'] ?? []);
		if (is_array($given) === false) {
			$given = [];
		}

		foreach ($given as $step) {
			if (is_array($step) === false) {
				continue;
			}

			if (($step['type'] ?? '') === 'form') {
				unset($step['form'], $step['fields']);
				$step['formId'] = (string)($step['formId'] ?? '');
			}

			$steps[] = $step;
		}

		return [
			'title'       => trim((string)($journey['title'] ?? '')),
			'description' => (string)($journey['description'] ?? ''),
			'steps'       => $steps,
		];
	}//end storedShape()
}//end class
