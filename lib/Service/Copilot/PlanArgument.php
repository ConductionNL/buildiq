<?php

/**
 * Buildiq PlanArgument
 *
 * One argument of a copilot plan step, with the property schema its tool
 * declares for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-a-natural-language-brief-produces-a-validated-builder-operation-plan
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

/**
 * A plan step's argument and the JSON-Schema fragment it is checked against.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-a-natural-language-brief-produces-a-validated-builder-operation-plan
 */
final class PlanArgument {

	/**
	 * Constructor.
	 *
	 * @param string               $key    Argument name (for the violation message).
	 * @param mixed                $value  The argument value.
	 * @param array<string, mixed> $schema The property's JSON-Schema fragment.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $key,
		public readonly mixed $value,
		public readonly array $schema,
	) {
	}//end __construct()
}//end class
